<?php

use Tests\Support\TelemetryDatabase;

uses(TelemetryDatabase::class);
beforeEach(fn () => $this->prepareTelemetryDatabase());

it('makes the approved planetary frontend the only root entry and removes retired fixtures', function () {
    $this->telemetryOwner();
    $this->get('/')->assertOk()->assertSee('id="sky"', false)->assertSee('aria-label="Host systems"', false)->assertDontSee('class="lens', false)->assertDontSee('fleet-ledger');
    foreach (['/design', '/design/satellites', '/design/hosts/standby'] as $path) {
        $this->get($path)->assertNotFound();
    }
    $this->get('/satellites')->assertRedirect('/reporting');
});

it('keeps Orbit behind existing owner authentication without caching private responses', function () {
    $this->get('/orbit')->assertRedirect('/login');
    $this->telemetryOwner();
    $response = $this->get('/orbit')->assertOk()->assertHeader('Cache-Control');
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
});

it('loads a live Orbit shell without embedding host payloads or collector credentials', function () {
    [$machine, $token] = $this->telemetryMachine();
    $report = $this->telemetryReport();
    $report['processes']['top_cpu']['items'][0]['name'] = 'private-process-sentinel';
    $this->freshContact($machine, $token, $report)->assertOk();
    $this->telemetryOwner();
    $this->get('/orbit')->assertOk()->assertDontSee($token)->assertDontSee('private-process-sentinel');
    $this->getJson('/api/v1/fleet')->assertOk()->assertJsonPath('hosts.0.name', $machine->name);
});
