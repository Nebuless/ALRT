<?php

use App\Incidents\AlertOutbox;
use App\Incidents\EvaluateFleet;
use App\Incidents\ObservePeerHub;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\IncidentFixtures;
use Tests\Support\TelemetryDatabase;
use Tests\TestCase;

uses(TestCase::class, TelemetryDatabase::class, IncidentFixtures::class);
beforeEach(function () {
    $this->prepareTelemetryDatabase();
    Queue::fake();
    Http::preventStrayRequests();
});

it('joins four-hour recovery, sampled peak, honest gaps and independent owner observation', function (string $name, string $role) {
    $this->incidentHub($name, $role);
    [$machine, $token] = $this->telemetryMachine();
    $this->incidentContact($machine, $token, 1);
    $start = now()->copy();
    $this->travel(110)->seconds();
    app(EvaluateFleet::class)->run();
    $silence = DB::table('incidents')->where('rule', 'silence')->value('id');
    expect($silence)->not->toBeNull();
    // Give each independent hub its own deadline even though no peer responds.
    $this->travel(20)->seconds();
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
    app(AlertOutbox::class)->deliver($silence);
    expect(DB::table('incident_outbox')->where('incident_id', $silence)->value('delivered_at'))->not->toBeNull();
    Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'Seen by '.$name));
    $this->travelTo($start->copy()->addHours(4));
    $report = function (int $n) use ($start): array {
        $report = $this->telemetryReport($n, 'recovery');
        $report['sampled_at'] = $start->copy()->addSeconds($n * 10)->toIso8601String();
        if ($n === 600) {
            $report['metrics']['cpu_percent']['value'] = 99;
            $report['processes']['top_cpu']['items'][0]['name'] = 'sampled-compiler';
            $report['processes']['top_cpu']['items'][0]['cpu_percent'] = 99;
        }

        return $report;
    };
    // Current first; replay must never replace this projection or fresh receipt.
    $this->freshContact($machine, $token, $report(1440))->assertOk();
    $current = DB::table('telemetry_contacts')->first();
    $recorded = [];
    for ($n = 1; $n < 1440; $n++) {
        if ($n >= 900 && $n < 930) {
            continue; // Five minutes were never recorded; no interpolation.
        }
        $recorded[] = $report($n);
    }
    foreach (array_chunk($recorded, 24) as $batch) {
        $this->withToken($token)->postJson($this->collectorPath($machine, 'reports'), ['reports' => $batch])->assertOk();
    }
    DB::disconnect(); // Reopen the actual file-backed hub before owner login.
    expect(DB::table('telemetry_contacts')->value('payload'))->toBe($current->payload)
        ->and(DB::table('telemetry_contacts')->value('last_contact_at'))->toBe($current->last_contact_at);
    $this->telemetryOwner();
    $this->withHeader('Authorization', '');
    $url = '/api/v1/hosts/'.$machine->id.'/history';
    $timeline = $this->getJson($url.'?'.http_build_query(['from' => $start->toIso8601String(), 'to' => now()->toIso8601String(), 'resolution' => 'raw']))
        ->assertOk()->assertJsonCount(1411, 'points')->json();
    $peak = collect($timeline['points'])->firstWhere('sequence', 600);
    expect($peak['metrics']['cpu_percent']['max'])->toEqual(99);
    $this->getJson($url.'/snapshots/'.$peak['snapshot']['id'])->assertOk()->assertJsonPath('data.processes.top_cpu.items.0.name', 'sampled-compiler');
    expect(collect($timeline['gaps'])->contains(fn ($gap) => $gap['from'] === $start->copy()->addSeconds(8990)->toIso8601String() && $gap['to'] === $start->copy()->addSeconds(9290)->toIso8601String()))->toBeTrue();
    $this->get('/hosts/'.$machine->id)->assertOk()->assertSee('Alpha-Hub');
    if ($role === 'standby') {
        $this->postJson('/api/v1/hosts/'.$machine->id.'/reboot', ['proof' => str_repeat('p', 64)])->assertForbidden();
        expect(DB::table('reboot_actions')->count())->toBe(0);
    }
})->with([['Primary-Hub', 'primary'], ['Alpha-Hub', 'standby'], ['Beta-Restricted', 'standby']]);

it('keeps standby login data and alerts independent of primary faults and refuses reboot', function (string $fault) {
    $hub = $this->incidentHub('Alpha-Hub', 'standby');
    $hub->update(['peers' => ['Primary-Hub' => 'https://primary.example.test']]);
    [$machine, $token] = $this->telemetryMachine($fault === 'host' ? 'Primary-Hub' : 'Beta-Restricted');
    $path = tempnam(sys_get_temp_dir(), 'nebuless-primary-fault-');
    file_put_contents($path, json_encode(['machine_id' => $machine->id, 'destinations' => [
        ['id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'url' => 'https://primary.example.test', 'token' => $token],
    ]], JSON_THROW_ON_ERROR));
    chmod($path, 0600);
    config(['incidents.collector_config' => $path]);
    $this->beforeApplicationDestroyed(fn () => unlink($path));
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true]), 'https://primary.example.test/*' => function () use ($fault) {
        if ($fault === 'scheduler') {
            return Http::response($this->incidentPeerBody(schedulerAge: 100, workerAge: 0));
        }
        throw new ConnectionException('synthetic primary path unavailable');
    }]);
    $this->incidentContact($machine, $token, 1);
    app(ObservePeerHub::class)->run();
    app(EvaluateFleet::class)->run();
    // Host failure/split stops fresh host evidence; HTTP/scheduler-only failure
    // keeps ordinary host contacts coming, so the two observations stay distinct.
    for ($n = 2; $n <= 13; $n++) {
        $this->travel(10)->seconds();
        if (in_array($fault, ['http', 'scheduler'], true)) {
            $this->incidentContact($machine, $token, $n);
        }
        app(ObservePeerHub::class)->run();
        app(EvaluateFleet::class)->run();
    }
    $rule = $fault === 'scheduler' ? 'hub_scheduler' : 'hub_http';
    expect(DB::table('incidents')->where('rule', $rule)->exists())->toBeTrue()
        ->and(DB::table('incidents')->where('rule', 'silence')->exists())->toBe(in_array($fault, ['host', 'split'], true));
    foreach (DB::table('incident_outbox')->pluck('incident_id') as $id) {
        app(AlertOutbox::class)->deliver($id);
    }
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org') && str_contains($request['text'], 'Seen by Alpha-Hub'));
    $this->telemetryOwner();
    $this->withHeader('Authorization', '');
    $this->getJson('/api/v1/fleet')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $this->get('/hosts/'.$machine->id)->assertOk()->assertSee($machine->name);
    $this->postJson('/api/v1/hosts/'.$machine->id.'/reboot', ['proof' => str_repeat('p', 64)])->assertForbidden();
    expect(DB::table('reboot_actions')->count())->toBe(0);
})->with(['http', 'scheduler', 'host', 'split']);
