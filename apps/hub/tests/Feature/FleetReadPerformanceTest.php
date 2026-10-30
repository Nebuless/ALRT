<?php

use App\Http\Controllers\FleetController;
use App\Incidents\IncidentReadModel;
use App\Models\HubIdentity;
use App\Models\Machine;
use App\ReadModels\FleetSummary;
use Illuminate\Support\Facades\DB;
use Tests\Support\TelemetryDatabase;

uses(TelemetryDatabase::class);

beforeEach(function () {
    $this->prepareTelemetryDatabase();
    HubIdentity::create(['id' => 1, 'hub_uuid' => '33333333-3333-4333-8333-333333333333', 'name' => 'Primary-Hub', 'role' => 'primary', 'peers' => ['Peer' => 'https://peer.example.test']]);
});

function seedFleetReadHosts(int $count): void
{
    $report = json_decode(file_get_contents(dirname(__DIR__, 4).'/contracts/fixtures/telemetry/valid-sample.json'), true, flags: JSON_THROW_ON_ERROR);
    $stamp = now()->toIso8601String();
    for ($i = 0; $i < $count; $i++) {
        $id = sprintf('22222222-2222-4222-8222-%012d', $i);
        $machine = Machine::create(['id' => $id, 'name' => sprintf('Synthetic-%03d', $i), 'data_policy' => Machine::HEALTH_PROCESSES]);
        $machine->forceFill(['reboot_enabled' => true])->save();
        $report['sampled_at'] = now()->subSeconds(5)->toIso8601String();
        DB::table('telemetry_contacts')->insert(['machine_id' => $id, 'generation' => 1, 'stream_id' => 'synthetic', 'boot_id' => $report['boot_id'], 'sequence' => 1, 'last_contact_at' => $stamp, 'sampled_at' => $report['sampled_at'], 'payload' => json_encode($report, JSON_THROW_ON_ERROR)]);
        DB::table('telemetry_streams')->insert(['machine_id' => $id, 'stream_id' => 'synthetic', 'boot_id' => $report['boot_id'], 'highest_sequence' => 1, 'first_received_at' => $stamp, 'last_received_at' => $stamp]);
        DB::table('incident_watches')->insert(['target' => $id, 'rule' => 'silence', 'state' => 'healthy', 'evaluated_at' => $stamp]);
        DB::table('incidents')->insert(['id' => 'incident-'.$i, 'target' => $id, 'target_name' => $machine->name, 'kind' => 'host', 'rule' => 'cpu', 'anchor' => 'synthetic', 'opened_at' => $stamp]);
        DB::table('incident_outbox')->insert(['incident_id' => 'incident-'.$i, 'due_at' => $stamp, 'attempts' => 2, 'last_error' => 'synthetic']);
        DB::table('reboot_capabilities')->insert(['machine_id' => $id, 'boot_id' => $report['boot_id'], 'available' => true, 'observed_at' => $stamp]);
        // Insertion order, not queued_at, chooses the latest action.
        DB::table('reboot_actions')->insert(['id' => 'old-'.$i, 'machine_id' => $id, 'boot_id' => $report['boot_id'], 'state' => 'canceled', 'queued_at' => now()->addMinute()->toIso8601String(), 'expires_at' => now()->addHour()->toIso8601String()]);
        DB::table('reboot_actions')->insert(['id' => 'latest-'.$i, 'machine_id' => $id, 'boot_id' => $report['boot_id'], 'state' => 'picked_up', 'queued_at' => $stamp, 'expires_at' => now()->addHour()->toIso8601String(), 'cancel_until' => now()->addMinute()->toIso8601String()]);
        DB::table('telemetry_delivery')->insert(['machine_id' => $id, 'observed_at' => $stamp, 'payload' => json_encode(['acknowledgements' => [], 'queue' => ['samples' => $i]], JSON_THROW_ON_ERROR)]);
    }
}

it('bounds fleet queries at synthetic host counts and measures the controller response', function (int $count) {
    seedFleetReadHosts($count);
    // Exercise the real controller serialization without auth-service/network latency.
    $controller = app(FleetController::class);
    $read = app(FleetSummary::class);
    $controller->fleet($read);
    for ($run = 1; $run <= 5; $run++) {
        DB::enableQueryLog();
        DB::flushQueryLog();
        gc_collect_cycles();
        memory_reset_peak_usage();
        $memory = memory_get_usage();
        $start = hrtime(true);
        try {
            $response = $controller->fleet($read);
            $elapsed = (hrtime(true) - $start) / 1e6;
            $peak = memory_get_peak_usage() - $memory;
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        if ($evidence = getenv('FLEET_PERFORMANCE_EVIDENCE')) {
            file_put_contents($evidence, json_encode(['hosts' => $count, 'run' => $run, 'queries' => count($queries), 'controller_ms' => round($elapsed, 3), 'peak_delta_bytes' => $peak, 'response_bytes' => strlen($response->getContent()), 'php' => PHP_VERSION, 'sqlite' => DB::selectOne('select sqlite_version() as version')->version], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND);
        }
    }
    $fleet = $response->getData(true);
    expect($response->status())->toBe(200)->and($fleet['hosts'])->toHaveCount($count)
        ->and($queries)->toHaveCount(11)
        ->and(array_filter($queries, fn (array $query) => str_contains($query['query'], 'telemetry_streams')))->toBe([]);
    $expected = Machine::orderBy('name')->get()->map(fn (Machine $machine) => $read->host($machine))->all();
    expect($fleet['hosts'])->toBe($expected)->and($fleet['incidents'])->toBe(app(IncidentReadModel::class)->fleet());
})->with([1, 10, 100]);

it('preserves absent contact stale evidence health actions delivery and next-read freshness', function () {
    seedFleetReadHosts(10);
    $machines = Machine::orderBy('name')->get();
    DB::table('telemetry_contacts')->whereIn('machine_id', [$machines[0]->id, $machines[1]->id])->delete();
    DB::table('telemetry_streams')->where('machine_id', $machines[0]->id)->delete();
    DB::table('telemetry_contacts')->where('machine_id', $machines[2]->id)->update(['sampled_at' => now()->subHours(5)->toIso8601String()]);
    DB::table('incident_watches')->where('target', $machines[3]->id)->update(['evaluated_at' => now()->subHour()->toIso8601String()]);
    DB::table('incident_watches')->where('target', $machines[4]->id)->delete();
    DB::table('incidents')->where('target', $machines[5]->id)->update(['resolved_at' => now()->toIso8601String()]);
    DB::table('telemetry_delivery')->where('machine_id', $machines[6]->id)->delete();
    DB::table('reboot_actions')->where('machine_id', $machines[7]->id)->delete();
    DB::table('reboot_capabilities')->where('machine_id', $machines[8]->id)->update(['boot_id' => 'other-boot']);
    DB::table('telemetry_contacts')->where('machine_id', $machines[9]->id)->update(['boot_id' => 'new-boot']);
    DB::table('incidents')->insert(['id' => 'hub', 'target' => 'hub', 'target_name' => 'Peer', 'kind' => 'hub', 'rule' => 'hub_http', 'anchor' => 'synthetic', 'opened_at' => now()->toIso8601String()]);
    DB::table('incident_outbox')->where('incident_id', 'incident-1')->delete();
    $read = app(FleetSummary::class);
    $expected = $machines->map(fn (Machine $machine) => $read->host($machine))->all();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $fleet = $read->fleet();
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($queries)->toHaveCount(12)
        ->and(array_filter($queries, fn (array $query) => str_contains($query['query'], 'telemetry_streams')))->toHaveCount(1);
    expect($fleet['hosts'])->toBe($expected)->and($fleet['incidents'])->toBe(app(IncidentReadModel::class)->fleet())
        ->and($fleet['hosts'][0]['unavailable_reason'])->toBe('no_report')
        ->and($fleet['hosts'][1]['unavailable_reason'])->toBe('no_live_contact')
        ->and($fleet['hosts'][2]['unavailable_reason'])->toBe('observation_unavailable')
        ->and($fleet['hosts'][3]['health']['unavailable_reason'])->toBe('evaluation_stale')
        ->and($fleet['hosts'][4]['health']['state'])->toBe('awaiting_evaluation')
        ->and($fleet['hosts'][5]['health']['state'])->toBe('healthy')
        ->and($fleet['hosts'][6]['delivery'])->toBeNull()
        ->and($fleet['hosts'][7]['actions']['latest'])->toBeNull()
        ->and($fleet['hosts'][8]['actions']['capability']['available'])->toBeFalse()
        ->and($fleet['hosts'][9]['actions']['latest']['state'])->toBe('boot_changed')
        ->and($fleet['hosts'][2]['actions']['latest']['id'])->toBe('latest-2');
    $staleMachine = Machine::findOrFail($machines[2]->id);
    DB::table('machines')->where('id', $staleMachine->id)->update(['reboot_enabled' => false]);
    expect($read->host($staleMachine)['actions']['capability']['reason'])->toBe('Reboot execution capability has not been enrolled locally.')
        ->and($read->fleet()['hosts'][2]['actions']['capability']['reason'])->toBe('Reboot execution capability has not been enrolled locally.');
    $this->travel(61)->seconds();
    DB::table('incident_outbox')->where('incident_id', 'incident-2')->update(['attempts' => 3, 'last_error' => null]);
    DB::table('telemetry_delivery')->where('machine_id', $machines[2]->id)->update(['payload' => '{"acknowledgements":[]}']);
    HubIdentity::find(1)->update(['role' => 'standby']);
    $machines[2]->forceFill(['reboot_enabled' => false])->save();
    $fresh = $read->fleet();
    expect($fresh['hosts'])->toBe($machines->map(fn (Machine $machine) => $read->host($machine))->all())
        ->and($fresh['hub']['role'])->toBe('standby')
        ->and($fresh['hosts'][2]['actions']['latest']['state'])->toBe('awaiting_primary')
        ->and($fresh['hosts'][2]['health']['incidents'][0]['alert']['attempts'])->toBe(3)
        ->and($fresh['hosts'][2]['delivery'])->not->toHaveKey('queue');
});

it('does not read history for a host with contact or retain preloads on an empty fleet', function () {
    seedFleetReadHosts(1);
    $read = app(FleetSummary::class);
    $machine = Machine::firstOrFail();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $read->host($machine);
        expect(array_filter(DB::getQueryLog(), fn (array $query) => str_contains($query['query'], 'telemetry_streams')))->toBe([]);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    $read->fleet();
    HubIdentity::query()->delete();
    expect($read->fleet()['hosts'])->toBe([$read->host($machine)]);
    DB::table('reboot_actions')->delete();
    DB::table('reboot_capabilities')->delete();
    $machine->delete();
    $empty = $read->fleet();
    expect($empty['hosts'])->toBe([])->and($empty['state'])->toBe('awaiting_enrollment');
});
