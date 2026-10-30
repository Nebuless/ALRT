<?php

use App\Incidents\AlertOutbox;
use App\Incidents\EvaluateFleet;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\IncidentFixtures;
use Tests\Support\TelemetryDatabase;
use Tests\TestCase;

uses(TestCase::class, TelemetryDatabase::class, IncidentFixtures::class);
it('carries actual collector wire through owner data and minimal fake Telegram without private canaries', function () {
    $this->prepareTelemetryDatabase();
    $wire = json_decode(file_get_contents(getenv('NEBULESS_ACCEPTANCE_WIRE')), true, flags: JSON_THROW_ON_ERROR);
    $canary = $wire['canary'];
    // Wire collection may finish within its final sample's fractional UTC second.
    $this->travelTo(Carbon::parse(max(array_column($wire['reports'], 'sampled_at')))->utc()->ceilSecond());
    $this->incidentHub('Beta-Restricted', 'standby');
    [$machine, $token] = $this->telemetryMachine('Beta-Restricted');
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
    foreach ($wire['reports'] as $report) {
        $this->withToken($token)->postJson($this->collectorPath($machine, 'reports'), ['reports' => [$report]])->assertOk();
    }
    // Deliberately prohibited input is rejected, never reflected or persisted.
    $bad = $wire['reports'][0];
    $bad['processes']['top_cpu']['items'][0]['cmdline'] = $canary;
    $this->withToken($token)->postJson($this->collectorPath($machine, 'reports'), ['reports' => [$bad]])->assertUnprocessable()->assertDontSee($canary);
    $this->freshContact($machine, $token, end($wire['reports']))->assertOk();
    $this->travel(130)->seconds();
    app(EvaluateFleet::class)->run();
    $this->travel(20)->seconds();
    app(AlertOutbox::class)->deliver(DB::table('incidents')->where('rule', 'silence')->value('id'));
    Http::assertSent(function ($request) use ($canary) {
        expect(json_encode($request->data()))->not->toContain($canary);
        expect($request->data())->not->toHaveKeys(['reply_markup', 'callback_data']);

        return true;
    });
    $this->telemetryOwner();
    $this->withHeader('Authorization', '');
    foreach (['/api/v1/fleet', '/api/v1/hosts/'.$machine->id, '/api/v1/hosts/'.$machine->id.'/processes'] as $path) {
        $this->getJson($path)->assertOk()->assertDontSee($canary);
    }
    $this->get('/hosts/'.$machine->id)->assertOk()->assertDontSee($canary);
    // Actual normal and emergency diagnostics are inspected by the parent stderr capture.
    config(['logging.default' => 'stderr']);
    Log::warning('privacy-normal-sink');
    Log::channel('privacy-invalid-channel')->warning('privacy-emergency-sink');
    $database = DB::selectOne('PRAGMA database_list')->file;
    DB::statement('PRAGMA wal_checkpoint(TRUNCATE)');
    foreach ([$database, $database.'-wal', storage_path('logs/laravel.log')] as $file) {
        if (is_file($file)) {
            expect(file_get_contents($file))->not->toContain($canary);
        }
    }
});
