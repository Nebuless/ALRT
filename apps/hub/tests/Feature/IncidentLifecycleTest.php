<?php

use App\Incidents\EvaluateFleet;
use App\Incidents\IncidentReadModel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\IncidentFixtures;
use Tests\Support\TelemetryDatabase;

uses(TelemetryDatabase::class, IncidentFixtures::class);
beforeEach(function () {
    $this->prepareTelemetryDatabase();
    $this->freezeSecond();
    Queue::fake();
});

it('requires sustained fresh samples at the signal threshold and sixty fresh seconds to clear', function (string $rule, int $duration) {
    [$machine, $token] = $this->telemetryMachine();
    $this->incidentHub();
    $evaluate = app(EvaluateFleet::class);
    $sequence = 1;
    $this->incidentContact($machine, $token, $sequence++, $rule);
    $evaluate->run();
    for ($elapsed = 0; $elapsed <= $duration; $elapsed += 10) {
        $this->travel(10)->seconds();
        $this->incidentContact($machine, $token, $sequence++, $rule, true);
        $evaluate->run();
        expect(DB::table('incidents')->where('rule', $rule)->count())->toBe($elapsed < $duration ? 0 : 1);
    }
    expect(app(IncidentReadModel::class)->host($machine->id)['state'])->toBe('pressure');
    for ($elapsed = 0; $elapsed <= 60; $elapsed += 10) {
        $this->travel(10)->seconds();
        $this->incidentContact($machine, $token, $sequence++, $rule);
        $evaluate->run();
        expect(DB::table('incidents')->where('rule', $rule)->whereNull('resolved_at')->count())->toBe($elapsed < 60 ? 1 : 0);
    }
    expect(app(IncidentReadModel::class)->host($machine->id)['state'])->toBe('healthy')
        ->and(DB::table('incident_transitions')->pluck('state')->all())->toBe(['opened', 'resolved'])
        ->and(DB::table('incident_outbox')->value('cancelled_at'))->not->toBeNull();
})->with([['cpu', 60], ['memory', 60], ['psi_memory', 30], ['psi_io', 30]]);

it('never advances a resource timer from repeated evaluation replay or stale metrics on a live contact', function () {
    [$machine, $token] = $this->telemetryMachine();
    $evaluate = app(EvaluateFleet::class);
    $this->incidentContact($machine, $token, 1, 'cpu', true);
    $evaluate->run();
    for ($sequence = 2; $sequence <= 9; $sequence++) {
        $this->travel(10)->seconds();
        $report = $this->telemetryReport($sequence);
        $report['metrics']['cpu_percent'] = ['value' => 99, 'status' => 'available'];
        $this->freshContact($machine, $token, $report)->assertOk();
        $evaluate->run();
        $evaluate->run();
    }
    expect(DB::table('incidents')->count())->toBe(0)
        ->and(app(IncidentReadModel::class)->host($machine->id)['state'])->toBe('healthy');
    $this->withToken($token)->postJson($this->collectorPath($machine, 'reports'), ['reports' => [$this->telemetryReport(100)]])->assertOk();
    $this->travel(90)->seconds();
    $evaluate->run();
    expect(DB::table('incidents')->pluck('rule')->all())->toBe(['silence']);
});

it('does not infer a continuous signal or clearing interval through missing unsupported or interrupted samples', function () {
    [$machine, $token] = $this->telemetryMachine();
    $evaluate = app(EvaluateFleet::class);
    for ($sequence = 1; $sequence <= 4; $sequence++) {
        $this->incidentContact($machine, $token, $sequence, 'cpu', true);
        $evaluate->run();
        $this->travel(10)->seconds();
    }
    $this->travel(31)->seconds();
    $this->incidentContact($machine, $token, 5, 'cpu', true);
    $evaluate->run();
    expect(DB::table('incidents')->count())->toBe(0);
    for ($sequence = 6; $sequence <= 11; $sequence++) {
        $this->travel(10)->seconds();
        $this->incidentContact($machine, $token, $sequence, 'cpu', true);
        $evaluate->run();
    }
    expect(DB::table('incidents')->where('rule', 'cpu')->whereNull('resolved_at')->count())->toBe(1);
    for ($sequence = 12; $sequence <= 16; $sequence++) {
        $this->travel(10)->seconds();
        $this->incidentContact($machine, $token, $sequence);
        $evaluate->run();
    }
    $this->travel(10)->seconds();
    $report = $this->telemetryReport(17);
    $report['sampled_at'] = now()->toIso8601String();
    $report['metrics']['cpu_percent'] = ['status' => 'unsupported', 'value' => null];
    $report['capabilities']['cpu'] = false;
    $this->freshContact($machine, $token, $report)->assertOk();
    $evaluate->run();
    for ($sequence = 18; $sequence <= 23; $sequence++) {
        $this->travel(10)->seconds();
        $this->incidentContact($machine, $token, $sequence);
        $evaluate->run();
    }
    expect(DB::table('incidents')->where('rule', 'cpu')->whereNull('resolved_at')->count())->toBe(1);
    $this->travel(10)->seconds();
    $this->incidentContact($machine, $token, 24);
    $evaluate->run();
    expect(DB::table('incidents')->where('rule', 'cpu')->whereNull('resolved_at')->count())->toBe(0);
});

it('commits the incident transition and outbox together or neither', function () {
    [$machine, $token] = $this->telemetryMachine();
    $this->incidentContact($machine, $token, 1);
    app(EvaluateFleet::class)->run();
    $this->travel(90)->seconds();
    DB::unprepared("CREATE TRIGGER fail_incident_outbox BEFORE INSERT ON incident_outbox BEGIN SELECT RAISE(ABORT, 'test unavailable outbox'); END");
    expect(fn () => app(EvaluateFleet::class)->run())->toThrow(QueryException::class);
    expect(DB::table('incidents')->count())->toBe(0)
        ->and(DB::table('incident_transitions')->count())->toBe(0)
        ->and(DB::table('incident_watches')->where('rule', 'silence')->value('state'))->toBe('healthy');
    DB::unprepared('DROP TRIGGER fail_incident_outbox');
    app(EvaluateFleet::class)->run();
    app(EvaluateFleet::class)->run();
    expect(DB::table('incidents')->count())->toBe(1)->and(DB::table('incident_outbox')->count())->toBe(1);
});

it('derives silence identity from the last actual contact even if an evaluator missed it', function () {
    [$machine, $token] = $this->telemetryMachine();
    $this->incidentContact($machine, $token, 1);
    app(EvaluateFleet::class)->run();
    $this->travel(10)->seconds();
    $this->incidentContact($machine, $token, 2);
    $contact = DB::table('telemetry_contacts')->first();
    $this->travel(90)->seconds();
    app(EvaluateFleet::class)->run();
    expect(DB::table('incidents')->value('id'))->toBe(hash('sha256', $machine->id.'|silence|'.$contact->boot_id.':'.$contact->stream_id.':2'));
});

it('detects silence at 45 and 90 seconds and needs two fresh generations to recover', function () {
    [$machine, $token] = $this->telemetryMachine();
    $evaluate = app(EvaluateFleet::class);
    $read = app(IncidentReadModel::class);
    $evaluate->run();
    expect($read->host($machine->id)['state'])->toBe('awaiting_report');
    $report = $this->telemetryReport();
    $report['sampled_at'] = now()->toIso8601String();
    $this->freshContact($machine, $token, $report)->assertOk();
    $evaluate->run();
    expect($read->host($machine->id)['state'])->toBe('healthy');
    $this->travel(45)->seconds();
    $evaluate->run();
    expect($read->host($machine->id)['state'])->toBe('suspect')
        ->and(DB::table('incident_outbox')->count())->toBe(0);
    $this->travel(45)->seconds();
    $evaluate->run();
    $incident = DB::table('incidents')->where('rule', 'silence')->first();
    expect($read->host($machine->id)['state'])->toBe('silent')
        ->and(DB::table('incident_outbox')->count())->toBe(1);
    $this->withToken($token)->postJson($this->collectorPath($machine, 'reports'), ['reports' => [$this->telemetryReport(50)]])->assertOk();
    $evaluate->run();
    expect($read->host($machine->id)['state'])->toBe('silent');
    foreach ([51, 52] as $sequence) {
        $this->travel(10)->seconds();
        $report = $this->telemetryReport($sequence);
        $report['sampled_at'] = now()->toIso8601String();
        $this->freshContact($machine, $token, $report)->assertOk();
        $evaluate->run();
        if ($sequence === 51) {
            $evaluate->run();
            expect($read->host($machine->id)['state'])->toBe('recovering');
        }
    }
    expect($read->host($machine->id)['state'])->toBe('healthy')
        ->and(DB::table('incidents')->where('id', $incident->id)->value('resolved_at'))->not->toBeNull();
});
