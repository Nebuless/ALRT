<?php

use App\ReadModels\FleetSummary;
use Tests\Support\TelemetryDatabase;

uses(TelemetryDatabase::class);
beforeEach(fn () => $this->prepareTelemetryDatabase());

it('requires confirmed owner sessions and represents empty and unreported states honestly', function () {
    $this->getJson('/api/v1/fleet')->assertUnauthorized();
    $owner = $this->telemetryOwner();
    $this->getJson('/api/v1/fleet')->assertOk()->assertJsonPath('state', 'awaiting_enrollment')->assertJsonPath('hosts', []);
    [$machine] = $this->telemetryMachine();
    $this->getJson('/api/v1/hosts/'.$machine->id)->assertOk()->assertJsonPath('data.state', 'awaiting_report')->assertJsonPath('data.metrics', null)->assertJsonPath('data.sample_age_seconds', null);
    $this->provisionBetterOwner($owner->email, true, 'replacement-password');
    $this->getJson('/api/v1/fleet')->assertUnauthorized();
});

it('shares observation ages scope provenance and reasons across services and owner reads', function () {
    [$machine, $token] = $this->telemetryMachine();
    $report = $this->telemetryReport();
    $this->freshContact($machine, $token, $report)->assertOk();
    $this->travel(10)->seconds();
    $this->telemetryOwner();
    $expected = app(FleetSummary::class)->host($machine);
    $host = $this->getJson('/api/v1/hosts/'.$machine->id)->assertOk()->json('data');
    expect($host)->toBe($expected)->and($host['contact_age_seconds'])->toBe(10)->and($host['sample_age_seconds'])->toBe(130);
    expect($host['scope'])->toBe($report['scope'])->and($host['provenance']['kind'])->toBe('direct_contact');
    $this->getJson('/api/v1/fleet')->assertOk()->assertJsonPath('hosts.0.sample_age_seconds', 130);
    $this->getJson('/api/v1/hosts/'.$machine->id.'/processes')->assertOk()->assertJsonPath('data.processes', fn ($value) => $value == $report['processes']);
    $this->getJson('/api/v1/hosts/'.$machine->id.'/capabilities')->assertOk()->assertJsonPath('data.capabilities', fn ($value) => $value == $report['capabilities']);
    $this->getJson('/api/v1/hosts/'.$machine->id.'/paths')->assertOk()->assertJsonPath('data.paths.0.provenance', 'direct_receipt')->assertJsonPath('data.paths.0.age_seconds', 10);
    $history = $this->getJson('/api/v1/hosts/'.$machine->id.'/history?limit=1')->assertOk()->json();
    expect($history['data'])->toHaveCount(1)->and($history['data'][0]['report']['sequence'])->toBe(1);
});

it('paginates raw history and distinguishes contact sample and replay ages', function () {
    [$machine, $token] = $this->telemetryMachine();
    $this->withToken($token)->postJson($this->collectorPath($machine, 'reports'), ['reports' => [$this->telemetryReport(1), $this->telemetryReport(2)]])->assertOk();
    $this->telemetryOwner();
    $this->getJson('/api/v1/hosts/'.$machine->id)->assertOk()->assertJsonPath('data.state', 'awaiting_contact')->assertJsonPath('data.metrics', null);
    $first = $this->getJson('/api/v1/hosts/'.$machine->id.'/history?limit=1')->assertOk()->json();
    $second = $this->getJson('/api/v1/hosts/'.$machine->id.'/history?limit=1&before='.$first['next_before'])->assertOk()->json();
    expect($first['data'][0]['report']['sequence'])->toBe(2)->and($second['data'][0]['report']['sequence'])->toBe(1);
    $this->freshContact($machine, $token, $this->telemetryReport(2))->assertOk();
    $this->getJson('/api/v1/hosts/'.$machine->id)->assertOk()->assertJsonPath('data.contact_age_seconds', 0)->assertJsonPath('data.sample_age_seconds', 120);
});

it('marks private owner reads as network only', function () {
    [$machine] = $this->telemetryMachine();
    $this->telemetryOwner();
    foreach (['/fleet', '/hosts/'.$machine->id, '/hosts/'.$machine->id.'/processes', '/hosts/'.$machine->id.'/capabilities', '/hosts/'.$machine->id.'/paths', '/hosts/'.$machine->id.'/history'] as $path) {
        $this->getJson('/api/v1'.$path)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }
});
