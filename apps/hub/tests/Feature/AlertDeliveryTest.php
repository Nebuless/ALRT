<?php

use App\Incidents\AlertOutbox;
use App\Incidents\EvaluateFleet;
use App\Incidents\IncidentReadModel;
use App\Incidents\ObservePeerHub;
use App\Jobs\SendIncidentAlert;
use App\Models\Machine;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\IncidentFixtures;
use Tests\Support\TelemetryDatabase;

uses(TelemetryDatabase::class, IncidentFixtures::class);
beforeEach(function () {
    $this->prepareTelemetryDatabase();
    $this->freezeSecond();
    Queue::fake();
    Http::preventStrayRequests();
    config(['incidents.public_url' => 'https://nebuless.example.test']);
});

it('stagger alerts at the fixed deadline while unavailable peers cannot postpone them', function (string $name, string $role, int $offset) {
    $this->incidentHub($name, $role);
    [$machine, $token] = $this->telemetryMachine();
    $this->incidentPeerConfig($machine, $token);
    Http::fake(fn () => Http::response([], 503));
    $this->incidentContact($machine, $token, 1);
    app(EvaluateFleet::class)->run();
    $this->travel(90)->seconds();
    app(ObservePeerHub::class)->run();
    app(EvaluateFleet::class)->run();
    $outbox = DB::table('incident_outbox')->first();
    expect(Carbon::parse($outbox->due_at)->diffInSeconds(now(), false))->toBe((float) -$offset);
    if ($offset > 0) {
        Queue::assertNotPushed(SendIncidentAlert::class);
        $this->travel($offset - 1)->seconds();
        app(EvaluateFleet::class)->run();
        Queue::assertNotPushed(SendIncidentAlert::class);
        $this->travel(1)->seconds();
        app(EvaluateFleet::class)->run();
    }
    Queue::assertPushedOn('urgent', SendIncidentAlert::class, fn ($job) => $job->incidentId === $outbox->incident_id);
})->with([['Primary-Hub', 'primary', 0], ['Alpha-Hub', 'standby', 10], ['Beta-Restricted', 'standby', 20]]);

it('distinguishes a failed hub scheduler and urgent worker from a live collector and HTTP service', function () {
    $this->incidentHub();
    [$machine, $token] = $this->telemetryMachine('Beta-Restricted');
    $this->incidentPeerConfig($machine, $token);
    $this->incidentContact($machine, $token, 1);
    $restrictedHost = $this->incidentPeerBody(schedulerAge: 46, workerAge: 0);
    $standby = $this->incidentPeerBody('cccccccc-cccc-4ccc-8ccc-cccccccccccc', schedulerAge: 0, workerAge: 80);
    Http::fake(['https://beta-restricted.example.test/*' => Http::response($restrictedHost), 'https://alpha.example.test/*' => Http::response($standby)]);
    app(ObservePeerHub::class)->run();
    app(EvaluateFleet::class)->run();
    expect(DB::table('incidents')->orderBy('rule')->pluck('rule')->all())->toBe(['hub_scheduler', 'hub_urgent_worker'])
        ->and(app(IncidentReadModel::class)->host($machine->id)['state'])->toBe('healthy');
    $hubs = app(IncidentReadModel::class)->peerHubs();
    expect($hubs[0]['http_state'])->toBe('authenticated')->and($hubs[0]['urgent_worker_age_seconds'])->toBe(80)
        ->and($hubs[1]['scheduler_age_seconds'])->toBe(46)->and($hubs[1]['observation_age_seconds'])->toBe(0);
    Http::assertSent(fn ($request) => $request->url() === 'https://alpha.example.test/api/v1/collector/'.$machine->id.'/hub-health' && $request->hasHeader('Authorization', 'Bearer '.$token));
});

it('detects independent HTTP failure without manufacturing stopped host or scheduler observations', function () {
    $this->incidentHub();
    [$machine, $token] = $this->telemetryMachine('Beta-Restricted');
    $this->incidentPeerConfig($machine, $token);
    Http::fake(fn () => throw new ConnectionException('private transport problem'));
    for ($sequence = 1; $sequence <= 10; $sequence++) {
        $this->incidentContact($machine, $token, $sequence);
        app(ObservePeerHub::class)->run();
        app(EvaluateFleet::class)->run();
        if ($sequence < 10) {
            $this->travel(10)->seconds();
        }
    }
    expect(DB::table('incidents')->pluck('rule')->all())->toBe(['hub_http', 'hub_http'])
        ->and(app(IncidentReadModel::class)->host($machine->id)['state'])->toBe('healthy');
});

it('suppresses only an exact fresh successful delivery claim from the pinned authenticated peer', function (string $claim, bool $suppressed) {
    $this->incidentHub();
    $id = $this->openSilenceIncident();
    $machine = Machine::first();
    $this->incidentPeerConfig($machine, str_repeat('t', 64));
    $body = $this->incidentPeerBody();
    $body['delivered_incidents'] = [['incident_id' => $claim === 'different' ? str_repeat('f', 64) : $id, 'delivered_at' => now()->toIso8601String()]];
    if ($claim === 'wrong_hub') {
        $body['hub_id'] = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
    } elseif ($claim === 'stale_probe') {
        $body['observed_at'] = now()->subSeconds(16)->toIso8601String();
    } elseif ($claim === 'old_receipt') {
        $body['delivered_incidents'][0]['delivered_at'] = now()->subSeconds(301)->toIso8601String();
    } elseif ($claim === 'future_receipt') {
        $body['delivered_incidents'][0]['delivered_at'] = now()->addSeconds(1)->toIso8601String();
    }
    Http::fake(['https://beta-restricted.example.test/*' => Http::response($body), 'https://alpha.example.test/*' => Http::response([], 503), 'https://api.telegram.org/*' => Http::response(['ok' => true])]);
    app(ObservePeerHub::class)->run();
    app(AlertOutbox::class)->deliver($id);
    expect(DB::table('incident_outbox')->where('incident_id', $id)->value('suppressed_at') !== null)->toBe($suppressed)
        ->and(DB::table('incident_outbox')->where('incident_id', $id)->value('delivered_at') !== null)->toBe(! $suppressed);
    if ($suppressed) {
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.telegram.org'));
    } else {
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org'));
    }
})->with([['matching', true], ['different', false], ['wrong_hub', false], ['stale_probe', false], ['old_receipt', false], ['future_receipt', false]]);

it('does not reuse an aged local peer observation even if its old successful receipt matched', function () {
    $this->incidentHub();
    $id = $this->openSilenceIncident();
    $machine = Machine::first();
    $this->incidentPeerConfig($machine, str_repeat('t', 64));
    $body = $this->incidentPeerBody();
    $body['delivered_incidents'] = [['incident_id' => $id, 'delivered_at' => now()->toIso8601String()]];
    Http::fake(['https://beta-restricted.example.test/*' => Http::response($body), 'https://alpha.example.test/*' => Http::response([], 503), 'https://api.telegram.org/*' => Http::response(['ok' => true])]);
    app(ObservePeerHub::class)->run();
    $this->travel(16)->seconds();
    app(AlertOutbox::class)->deliver($id);
    expect(DB::table('incident_outbox')->where('incident_id', $id)->value('delivered_at'))->not->toBeNull();
});

it('retries timeouts with redacted errors and obeys bounded Telegram rate limit backoff', function () {
    $this->incidentHub();
    $id = $this->openSilenceIncident();
    Log::spy();
    Http::fakeSequence('https://api.telegram.org/*')
        ->pushFailedConnection('https://api.telegram.org/bot123456:test-only-token/sendMessage private proxy secret')
        ->push(['ok' => false, 'parameters' => ['retry_after' => 90000]], 429)
        ->push(['ok' => true]);
    $outbox = app(AlertOutbox::class);
    $outbox->deliver($id);
    expect(DB::table('incident_outbox')->value('last_error'))->toBe('transport_failed')
        ->and(DB::table('incident_outbox')->value('attempts'))->toBe(1);
    $outbox->deliver($id);
    expect(DB::table('incident_outbox')->value('attempts'))->toBe(1);
    $this->travel(10)->seconds();
    $outbox->deliver($id);
    expect(DB::table('incident_outbox')->value('last_error'))->toBe('rate_limited')
        ->and(DB::table('incident_outbox')->value('due_at'))->toBe(now()->addSeconds(3600)->toIso8601String());
    $this->travel(3599)->seconds();
    $outbox->deliver($id);
    expect(DB::table('incident_outbox')->value('attempts'))->toBe(2);
    $this->travel(1)->seconds();
    $outbox->deliver($id);
    expect(DB::table('incident_outbox')->value('delivered_at'))->not->toBeNull()
        ->and(DB::table('incident_outbox')->value('attempts'))->toBe(3)
        ->and(DB::table('incident_outbox')->value('last_error'))->toBeNull();
    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('warning');
});

it('recovers abandoned durable leases after reopening the database and sends only minimal observation fields', function () {
    $this->incidentHub();
    $id = $this->openSilenceIncident();
    DB::table('incident_outbox')->where('incident_id', $id)->update(['lease_token' => str_repeat('z', 64), 'lease_until' => now()->addSeconds(30)->toIso8601String(), 'attempts' => 1]);
    DB::disconnect();
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
    app(AlertOutbox::class)->deliver($id);
    Http::assertNothingSent();
    $this->travel(30)->seconds();
    app(AlertOutbox::class)->deliver($id);
    app(AlertOutbox::class)->deliver($id);
    Http::assertSentCount(1);
    Http::assertSent(function ($request) {
        expect($request->data())->toHaveKeys(['chat_id', 'text', 'link_preview_options'])
            ->not->toHaveKeys(['reply_markup', 'callback_data']);
        expect($request['text'])->toContain('Alpha-Hub — No fresh contact', 'Seen by Primary-Hub at', 'https://nebuless.example.test')
            ->not->toContain('worker', 'test-only-token', 'process', 'boot_id');

        return true;
    });
    expect(DB::table('incident_outbox')->value('attempts'))->toBe(2)
        ->and(DB::table('incident_outbox')->value('lease_token'))->toBeNull();
    $this->postJson('/telegram/webhook', ['message' => ['text' => '/reboot Alpha-Hub']])->assertNotFound();
});

it('does not read peer credentials from non-private files or forward them to a mismatched origin', function () {
    $this->incidentHub();
    [$machine, $token] = $this->telemetryMachine();
    $path = $this->incidentPeerConfig($machine, $token);
    chmod($path, 0644);
    app(ObservePeerHub::class)->run();
    Http::assertNothingSent();
    expect(DB::table('peer_hub_observations')->count())->toBe(0);
    chmod($path, 0600);
    clearstatcache(true, $path);
    $config = json_decode(file_get_contents($path), true);
    $config['destinations'][0]['url'] = 'https://wrong.example.test';
    $config['destinations'][1]['url'] = 'https://alpha.example.test/private-path';
    file_put_contents($path, json_encode($config));
    app(ObservePeerHub::class)->run();
    Http::assertNothingSent();
});
