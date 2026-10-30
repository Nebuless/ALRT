<?php

use Illuminate\Support\Carbon;
use Tests\Support\TelemetryDatabase;

uses(TelemetryDatabase::class);

beforeEach(fn () => $this->prepareTelemetryDatabase());

it('serves timeline gaps and exact snapshots through the authenticated history API', function () {
    [$machine, $token] = $this->telemetryMachine();
    $report = $this->telemetryReport();
    $this->withToken($token)->postJson($this->collectorPath($machine, 'reports'), ['reports' => [$report]])->assertOk();
    $this->telemetryOwner();
    $url = '/api/v1/hosts/'.$machine->id.'/history';
    $timeline = $this->getJson($url.'?'.http_build_query(['from' => now()->subHours(4)->toIso8601String(), 'to' => now()->toIso8601String()]));
    $timeline->assertOk()->assertJsonPath('resolution', 'raw')->assertJsonCount(1, 'points');
    expect($timeline->json('gaps'))->not->toBeEmpty();
    $snapshot = $timeline->json('points.0.snapshot.id');
    $this->getJson($url.'/snapshots/'.$snapshot)->assertOk()
        ->assertJsonPath('data.processes.top_cpu', fn (array $actual): bool => $actual == $report['processes']['top_cpu'])
        ->assertJsonPath('data.coverage', fn (array $actual): bool => $actual == $report['coverage']['processes']);
    $this->getJson($url.'/snapshots/999999')->assertNotFound();
    $this->getJson($url.'?'.http_build_query(['from' => now()->toIso8601String(), 'to' => now()->subHour()->toIso8601String()]))->assertUnprocessable();
});

it('preserves the full raw response across backfill cutoffs timezones gaps and snapshot metadata', function () {
    [$machine, $token] = $this->telemetryMachine();
    foreach ([[0, 29, 'UTC'], [30, 30, 'UTC'], [60, 31, 'UTC'], [180, 31, 'Asia/Kolkata'], [190, 29, 'Pacific/Honolulu']] as $index => [$offset, $delay, $timezone]) {
        $sampled = Carbon::parse('2026-10-15T12:00:00Z')->addSeconds($offset);
        $this->travelTo($sampled->copy()->addSeconds($delay));
        $report = $this->telemetryReport($index + 1, 'raw-characterization');
        $report['sampled_at'] = $sampled->copy()->setTimezone($timezone)->toIso8601String();
        $report['metrics']['cpu_percent']['value'] = [0, 99, 20, 40, 50][$index];
        $report['elapsed_seconds'] = $index === 3 ? 5.5 : 10;
        $this->withToken($token)->postJson($this->collectorPath($machine, 'reports'), ['reports' => [$report]])->assertOk();
    }
    $this->travelTo(Carbon::parse('2026-10-15T12:04:00Z'));
    $this->telemetryOwner();
    $this->withHeader('Authorization', '');
    $timeline = $this->getJson('/api/v1/hosts/'.$machine->id.'/history?'.http_build_query([
        'from' => '2026-10-15T11:59:50Z', 'to' => '2026-10-15T12:04:00Z', 'resolution' => 'raw',
    ]))->assertOk()->assertJsonCount(5, 'points')->json();
    expect(array_column($timeline['points'], 'backfilled'))->toBe([false, false, true, true, false]);
    expect($timeline)->toEqual(json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/raw-history-response.json'), true, flags: JSON_THROW_ON_ERROR));
});

it('enforces expiry and future bounds through authenticated API reads with maintenance stopped', function () {
    [$machine, $token] = $this->telemetryMachine();
    $this->travelTo(Carbon::parse('2026-10-16T12:00:00Z'));
    $this->freshContact($machine, $token, $this->telemetryReport())->assertOk();
    $this->telemetryOwner();
    $this->withHeader('Authorization', '');
    $url = '/api/v1/hosts/'.$machine->id.'/history';
    $id = $this->getJson($url)->assertOk()->json('data.0.id');
    $this->getJson($url.'?resolution=minute')->assertOk()->assertJsonPath('resolution', 'minute');
    $this->travel(5)->hours();
    $this->getJson($url.'?limit=1')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('next_before', null);
    $this->getJson($url.'/snapshots/'.$id)->assertNotFound();
    foreach ([['from' => now()->subHours(4)->subSecond()->toIso8601String()], ['to' => now()->addSecond()->toIso8601String()]] as $input) {
        $this->getJson($url.'?'.http_build_query($input))->assertUnprocessable();
    }
    $this->getJson('/api/v1/hosts/'.$machine->id.'/processes')->assertOk()->assertJsonPath('data.processes', null);
    $this->getJson('/api/v1/hosts/'.$machine->id.'/capabilities')->assertOk()->assertJsonPath('data.capabilities', null);
});
