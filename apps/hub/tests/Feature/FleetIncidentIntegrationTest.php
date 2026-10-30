<?php

use App\Incidents\EvaluateFleet;
use Tests\Support\TelemetryDatabase;

uses(TelemetryDatabase::class);

beforeEach(fn () => $this->prepareTelemetryDatabase());

it('exposes the same silent-host verdict and observer health to owner API reads', function () {
    [$machine, $token] = $this->telemetryMachine();
    $this->freshContact($machine, $token, $this->telemetryReport())->assertOk();
    app(EvaluateFleet::class)->run();
    $this->travel(90)->seconds();
    app(EvaluateFleet::class)->run();
    $this->telemetryOwner();
    $this->getJson('/api/v1/hosts/'.$machine->id)->assertOk()
        ->assertJsonPath('data.health.state', 'silent')
        ->assertJsonPath('data.health.incidents.0.rule', 'silence');
    $this->getJson('/api/v1/fleet')->assertOk()
        ->assertJsonPath('hosts.0.health.state', 'silent')
        ->assertJsonPath('incidents.0.rule', 'silence')
        ->assertJsonPath('services.scheduler', null)
        ->assertJsonPath('services.urgent_worker', null);
});

it('ages an evaluated verdict when the scheduler no longer evaluates the fleet', function () {
    [$machine, $token] = $this->telemetryMachine();
    $this->freshContact($machine, $token, $this->telemetryReport())->assertOk();
    app(EvaluateFleet::class)->run();
    $this->telemetryOwner();
    $this->getJson('/api/v1/hosts/'.$machine->id)->assertOk()->assertJsonPath('data.health.state', 'healthy');
    $this->travel(46)->seconds();
    $this->getJson('/api/v1/hosts/'.$machine->id)->assertOk()
        ->assertJsonPath('data.health.state', 'unknown')
        ->assertJsonPath('data.health.evaluation_age_seconds', 46)
        ->assertJsonPath('data.health.unavailable_reason', 'evaluation_stale');
});
