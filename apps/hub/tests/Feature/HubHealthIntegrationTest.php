<?php

use App\Incidents\Heartbeats;
use App\Models\HubIdentity;
use Tests\Support\TelemetryDatabase;

uses(TelemetryDatabase::class);

beforeEach(fn () => $this->prepareTelemetryDatabase());

it('exposes separate service health only to an enrolled collector credential', function () {
    [$machine, $token] = $this->telemetryMachine();
    HubIdentity::create(['hub_uuid' => '11111111-1111-4111-8111-111111111111', 'name' => 'Alpha-Hub', 'role' => 'standby', 'peers' => []]);
    $path = $this->collectorPath($machine, 'hub-health');
    $this->getJson($path)->assertUnauthorized();
    $this->withToken($token)->getJson($path)->assertOk()
        ->assertJsonPath('hub_id', '11111111-1111-4111-8111-111111111111')
        ->assertJsonPath('heartbeats.scheduler', null)
        ->assertJsonPath('heartbeats.urgent_worker', null)
        ->assertHeader('Cache-Control', 'no-store, private');
    app(Heartbeats::class)->beat('scheduler');
    $this->travel(12)->seconds();
    app(Heartbeats::class)->beat('urgent_worker');
    $this->withToken($token)->getJson($path)->assertOk()
        ->assertJsonPath('heartbeats.scheduler', 12)
        ->assertJsonPath('heartbeats.urgent_worker', 0);
    $machine->revokeToken();
    $this->withToken($token)->getJson($path)->assertUnauthorized();
});
