<?php

use App\History\ReadTimeline;
use App\History\Retention;
use App\Incidents\EvaluateFleet;
use App\Jobs\PruneHistory;
use App\Models\Machine;
use App\ReadModels\FleetSummary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\TelemetryDatabase;

uses(TelemetryDatabase::class);
beforeEach(fn () => $this->prepareTelemetryDatabase());

it('preserves peaks, measured zero, and unknown spans when replay arrives out of order', function () {
    [$machine, $token] = $this->telemetryMachine();
    $start = now()->startOfHour()->subHours(2);
    $reports = [];
    foreach ([10 => 0, 20 => 99, 50 => 20] as $offset => $cpu) {
        $report = $this->telemetryReport($offset);
        $report['sampled_at'] = $start->copy()->addSeconds($offset)->toIso8601String();
        $report['metrics']['cpu_percent']['value'] = $cpu;
        $reports[] = $report;
    }
    $this->withToken($token)->postJson($this->collectorPath($machine, 'reports'), ['reports' => array_reverse($reports)])->assertOk();
    $this->withToken($token)->postJson($this->collectorPath($machine, 'reports'), ['reports' => $reports])->assertOk();
    expect(DB::table('history_buckets')->count())->toBe(0);
    $timeline = app(ReadTimeline::class)->timeline($machine, $start, $start->copy()->addMinute(), 'minute');
    expect($timeline['points'][0]['coverage_seconds'])->toBe(30.0)->and($timeline['points'][0]['metrics']['cpu_percent']['average'])->toEqualWithDelta(119 / 3, 0.000001)
        ->and($timeline['gaps'])->toHaveCount(2)->and($timeline['points'][0]['peak_evidence']['cpu']['kind'])->toBe('peak_process_evidence');
});

it('compacts expired raw identities and never contributes replay twice or revives old raw', function () {
    [$machine, $token] = $this->telemetryMachine();
    $report = $this->telemetryReport();
    $path = $this->collectorPath($machine, 'reports');
    $this->withToken($token)->postJson($path, ['reports' => [$report]])->assertOk();
    $this->travel(8)->days();
    app(PruneHistory::class)->handle();
    expect(DB::table('telemetry_samples')->count())->toBe(0)->and(DB::table('telemetry_accepted')->count())->toBe(0)
        ->and(DB::table('history_accepted_intervals')->count())->toBe(1);
    $this->withToken($token)->postJson($path, ['reports' => [$report]])->assertOk();
    expect(DB::table('telemetry_streams')->value('last_received_at'))->toBe(now()->toIso8601String())
        ->and(DB::table('telemetry_contacts')->count())->toBe(0);
    $report['metrics']['cpu_percent']['value'] = 75;
    $this->withToken($token)->postJson($path, ['reports' => [$report]])->assertOk();
    expect(DB::table('history_buckets')->count())->toBe(0)->and(DB::table('telemetry_samples')->count())->toBe(0);
});

it('ACKs eight day backfill without payload and normalizes eligible index timestamps', function () {
    [$machine, $token] = $this->telemetryMachine();
    $report = $this->telemetryReport();
    $report['sampled_at'] = now()->subDays(8)->setTimezone('Pacific/Honolulu')->toIso8601String();
    $this->withToken($token)->postJson($this->collectorPath($machine, 'reports'), ['reports' => [$report]])->assertOk();
    expect(DB::table('telemetry_samples')->count())->toBe(0)->and(DB::table('telemetry_contacts')->count())->toBe(0)
        ->and(DB::table('history_buckets')->count())->toBe(0)
        ->and(DB::table('history_accepted_intervals')->count())->toBe(1);
    $fresh = $this->telemetryReport(2);
    $fresh['sampled_at'] = now()->setTimezone('Asia/Kolkata')->toIso8601String();
    $this->freshContact($machine, $token, $fresh)->assertOk();
    expect(DB::table('telemetry_samples')->value('sampled_at'))->toBe(now()->utc()->toIso8601String())
        ->and(DB::table('telemetry_contacts')->value('sampled_at'))->toBe(now()->utc()->toIso8601String());
});

it('returns exact raw snapshot completeness and backfill provenance without filling unsupported values', function () {
    [$machine, $token] = $this->telemetryMachine();
    $report = $this->telemetryReport();
    $report['coverage']['processes']['status'] = 'partial';
    $report['coverage']['processes']['reason'] = 'permission_denied';
    $report['processes']['top_cpu']['completeness'] = 'partial';
    $report['processes']['top_cpu']['reason'] = 'permission_denied';
    $this->withToken($token)->postJson($this->collectorPath($machine, 'reports'), ['reports' => [$report]])->assertOk();
    $timeline = app(ReadTimeline::class)->timeline($machine, now()->subHours(4), now());
    expect($timeline['resolution'])->toBe('raw')->and($timeline['points'][0]['backfilled'])->toBeTrue()
        ->and($timeline['points'][0]['snapshot']['coverage']['status'])->toBe('partial')
        ->and($timeline['points'][0]['metrics']['pressure.memory.some.avg10']['average'])->toBeNull();
    $snapshot = app(ReadTimeline::class)->snapshot($machine, $timeline['points'][0]['snapshot']['id']);
    expect($snapshot['processes'])->toEqual($report['processes'])->and($snapshot['coverage'])->toEqual($report['coverage']['processes']);
});

it('retains exact four hour boundaries without longer tier payloads', function () {
    [$machine, $token] = $this->telemetryMachine();
    $reports = [];
    foreach ([now()->subHours(4), now()->subHours(4)->subSecond(), now()->subDays(90)->subSecond(), now()->subYear()->subSecond()] as $i => $date) {
        $report = $this->telemetryReport($i + 1);
        $report['sampled_at'] = $date->toIso8601String();
        $reports[] = $report;
    }
    $this->withToken($token)->postJson($this->collectorPath($machine, 'reports'), ['reports' => $reports])->assertOk();
    expect(DB::table('telemetry_samples')->count())->toBe(1);
    app(PruneHistory::class)->handle();
    expect(DB::table('history_buckets')->where('resolution', 60)->where('bucket_at', '<', now()->subDays(90)->startOfMinute()->toIso8601String())->count())->toBe(0);
});

it('rolls back all contributions and identities when persistence fails before the ACK', function () {
    [$machine, $token] = $this->telemetryMachine();
    DB::unprepared("CREATE TRIGGER fail_history BEFORE INSERT ON telemetry_samples BEGIN SELECT RAISE(ABORT, 'injected storage failure'); END");
    $this->withToken($token)->postJson($this->collectorPath($machine, 'reports'), ['reports' => [$this->telemetryReport()]])->assertStatus(503);
    expect(DB::table('telemetry_accepted')->count())->toBe(0)->and(DB::table('history_buckets')->count())->toBe(0)->and(DB::table('telemetry_samples')->count())->toBe(0);
});

it('merges retained sequence intervals across reordered missing ranges in bounded pruning chunks', function () {
    [$machine, $token] = $this->telemetryMachine();
    config(['history.prune_chunk' => 1]);
    foreach ([1, 3, 2] as $sequence) {
        $report = $this->telemetryReport($sequence);
        $report['sampled_at'] = now()->subYears(2)->toIso8601String();
        $this->withToken($token)->postJson($this->collectorPath($machine, 'reports'), ['reports' => [$report]])->assertOk();
        app(PruneHistory::class)->handle();
    }
    $interval = DB::table('history_accepted_intervals')->sole();
    expect($interval->first_sequence)->toBe(1)->and($interval->last_sequence)->toBe(3)
        ->and(DB::table('telemetry_accepted')->count())->toBe(0)->and(DB::table('history_buckets')->count())->toBe(0);
});

it('rejects an expired challenged contact without reviving or replacing the current projection', function () {
    [$machine, $token] = $this->telemetryMachine();
    $this->freshContact($machine, $token, $this->telemetryReport())->assertOk();
    $before = DB::table('telemetry_contacts')->first();
    $report = $this->telemetryReport(2);
    $report['sampled_at'] = now()->subYears(2)->toIso8601String();
    $this->freshContact($machine, $token, $report)->assertConflict()->assertExactJson(['error' => 'expired_contact']);
    expect(DB::table('telemetry_contacts')->first())->toEqual($before);
    $this->withToken($token)->postJson($this->collectorPath($machine, 'reports'), ['reports' => [$report]])->assertOk();
    expect(DB::table('telemetry_contacts')->first())->toEqual($before);
});

it('rejects explicit resolutions whose requested start precedes their retained evidence', function (string $resolution, int $days) {
    [$machine] = $this->telemetryMachine();
    $from = now()->subDays($days);
    expect(fn () => app(ReadTimeline::class)->timeline($machine, $from, $from->copy()->addHour(), $resolution))
        ->toThrow(InvalidArgumentException::class);
})->with([['raw', 8], ['minute', 91]]);

it('does not round sequence interval adjacency at the largest supported integer', function () {
    [$machine, $token] = $this->telemetryMachine();
    foreach ([PHP_INT_MAX - 2, PHP_INT_MAX] as $sequence) {
        $report = $this->telemetryReport($sequence);
        $report['sampled_at'] = now()->subYears(2)->toIso8601String();
        $this->withToken($token)->postJson($this->collectorPath($machine, 'reports'), ['reports' => [$report]])->assertOk();
        app(PruneHistory::class)->handle();
    }
    expect(DB::table('history_accepted_intervals')->count())->toBe(2);
    $report = $this->telemetryReport(PHP_INT_MAX - 1);
    $report['sampled_at'] = now()->subYears(2)->toIso8601String();
    $this->withToken($token)->postJson($this->collectorPath($machine, 'reports'), ['reports' => [$report]])->assertOk();
    app(PruneHistory::class)->handle();
    expect(DB::table('history_accepted_intervals')->count())->toBe(1);
});

it('never promotes a compacted historical identity through a fresh challenge and changed timestamp', function () {
    [$machine, $token] = $this->telemetryMachine();
    $report = $this->telemetryReport();
    $this->withToken($token)->postJson($this->collectorPath($machine, 'reports'), ['reports' => [$report]])->assertOk();
    $this->travel(8)->days();
    app(PruneHistory::class)->handle();
    $report['sampled_at'] = now()->toIso8601String();
    $report['metrics']['cpu_percent']['value'] = 99;
    $this->freshContact($machine, $token, $report)->assertConflict()->assertExactJson(['error' => 'expired_contact']);
    expect(DB::table('telemetry_contacts')->count())->toBe(0)->and(DB::table('telemetry_samples')->count())->toBe(0);
});

it('derives golden aggregate arithmetic from retained raw across reordered duplicates', function () {
    [$machine, $token] = $this->telemetryMachine();
    $anchor = Carbon::parse('2026-10-15T12:00:00Z');
    $this->travelTo($anchor->copy()->addSeconds(61));
    $reports = [];
    foreach ([[0, null, 0], [2, 5.5, 99], [1, 10, null], [60, 10, 99], [-3599, 10, 20], [-30, null, 20], [-29, null, 10]] as $index => [$offset, $elapsed, $cpu]) {
        $report = $this->telemetryReport($index + 1, 'bucket-characterization');
        $report['sampled_at'] = $anchor->copy()->addSeconds($offset)->toIso8601String();
        $report['elapsed_seconds'] = $elapsed;
        $report['metrics']['cpu_percent'] = ['value' => $cpu, 'status' => $cpu === null ? 'unsupported' : 'available'];
        foreach (['top_cpu', 'top_rss'] as $category) {
            $report['processes'][$category]['items'][0]['name'] = 'worker-'.($index + 1);
            $report['processes'][$category]['items'][0]['cpu_percent'] = $cpu;
        }
        $reports[$index + 1] = $report;
    }
    $path = $this->collectorPath($machine, 'reports');
    $this->withToken($token)->postJson($path, ['reports' => array_map(fn ($sequence) => $reports[$sequence], [4, 2, 1, 3, 5, 6, 7])])->assertOk()->assertJsonCount(7, 'accepted');
    $read = app(ReadTimeline::class);
    $from = $anchor->copy()->subHours(2);
    $to = $anchor->copy()->addSeconds(61);
    $before = [$read->timeline($machine, $from, $to, 'minute'), $read->timeline($machine, $from, $to, 'hour')];
    $this->withToken($token)->postJson($path, ['reports' => array_values($reports)])->assertOk()->assertJsonCount(7, 'accepted');
    expect([$read->timeline($machine, $from, $to, 'minute'), $read->timeline($machine, $from, $to, 'hour')])->toEqual($before)
        ->and(DB::table('history_buckets')->count())->toBe(0);
    $golden = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/history-bucket-aggregates.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($before[0]['points'])->toHaveCount(4)->and($before[1]['points'])->toHaveCount(3);
    foreach ($before as $timeline) {
        foreach ($timeline['points'] as $point) {
            $expected = collect($golden)->first(fn ($row) => $row['resolution'] === $point['bucket_seconds'] && $row['bucket_at'] === $point['bucket_at'])['aggregate'];
            expect($point['count'])->toBe($expected['count'])->and($point['peak_evidence'])->toEqual($expected['peak_evidence']);
            foreach ($expected['metrics'] as $name => $stat) {
                expect(array_intersect_key($point['metrics'][$name], $stat))->toEqual($stat);
            }
        }
    }
    $this->travel(31)->seconds();
    expect([$read->timeline($machine, $from, $to, 'minute'), $read->timeline($machine, $from, $to, 'hour')])->toEqual($before);
});

it('rolls back the whole batch when its second raw write fails before ACK', function () {
    [$machine, $token] = $this->telemetryMachine();
    $path = $this->collectorPath($machine, 'reports');
    $this->withToken($token)->postJson($path, ['reports' => [$this->telemetryReport()]])->assertOk();
    $tables = ['history_buckets', 'telemetry_accepted', 'telemetry_samples', 'telemetry_streams'];
    $before = array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->all(), $tables);
    DB::unprepared("CREATE TRIGGER fail_history_update BEFORE INSERT ON telemetry_samples WHEN NEW.sequence = 3 BEGIN SELECT RAISE(ABORT, 'injected history update failure'); END");
    $this->withToken($token)->postJson($path, ['reports' => [$this->telemetryReport(2), $this->telemetryReport(3)]])
        ->assertStatus(503)->assertExactJson(['error' => 'ingest_unavailable']);
    expect(array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->all(), $tables))->toEqual($before);
});

it('admits only the inclusive four hour interval and permanently ACKs other replay identities', function () {
    [$machine, $token] = $this->telemetryMachine();
    $this->travelTo(Carbon::parse('2026-10-16T16:00:00Z'));
    $reports = [];
    foreach (['2026-10-16T11:59:59.999Z', '2026-10-16T17:30:00.999+05:30', '2026-10-16T12:00:01Z', '2026-10-16T16:00:00.000Z', '2026-10-16T16:00:01Z'] as $index => $at) {
        $report = $this->telemetryReport($index + 1);
        $report['sampled_at'] = $at;
        $reports[] = $report;
    }
    $path = $this->collectorPath($machine, 'reports');
    $this->withToken($token)->postJson($path, ['reports' => $reports])->assertOk()->assertJsonCount(5, 'accepted');
    expect(DB::table('telemetry_samples')->orderBy('id')->pluck('sequence')->all())->toBe([2, 3, 4])
        ->and(DB::table('telemetry_accepted')->count())->toBe(3)
        ->and(DB::table('history_accepted_intervals')->count())->toBe(2)
        ->and(DB::table('history_buckets')->count())->toBe(0);
    foreach ([0, 4] as $index) {
        $reports[$index]['sampled_at'] = now()->toIso8601String();
    }
    DB::purge('sqlite');
    $this->withToken($token)->postJson($path, ['reports' => [$reports[0], $reports[4]]])->assertOk();
    expect(DB::table('telemetry_samples')->count())->toBe(3);
    $reports[1]['metrics']['cpu_percent']['value'] = 88;
    $this->withToken($token)->postJson($path, ['reports' => [$reports[1]]])->assertConflict()->assertExactJson(['error' => 'identity_conflict']);
});

it('filters cursor snapshot and current evidence before delayed maintenance without losing identity', function () {
    [$machine, $token] = $this->telemetryMachine();
    $this->travelTo(Carbon::parse('2026-10-16T12:00:00Z'));
    $report = $this->telemetryReport();
    $report['sampled_at'] = now()->toIso8601String();
    $this->freshContact($machine, $token, $report)->assertOk();
    $id = DB::table('telemetry_samples')->value('id');
    $read = app(FleetSummary::class);
    $this->travel(4)->hours();
    expect($read->history($machine)['data'])->toHaveCount(1)->and($read->snapshot($machine, $id))->not->toBeNull()
        ->and($read->host($machine)['processes'])->not->toBeNull();
    $this->travel(1)->seconds();
    $contact = DB::table('telemetry_contacts')->first();
    expect($read->history($machine, 1)['data'])->toBe([])->and($read->snapshot($machine, $id))->toBeNull();
    $host = $read->host($machine);
    expect($host['metrics'])->toBeNull()->and($host['processes'])->toBeNull()->and($host['coverage'])->toBeNull()
        ->and($host['capabilities'])->toBeNull()->and($host['scope'])->toBeNull()->and($host['observation']['detail'])->toBeNull()
        ->and($host['contact_age_seconds'])->toBe(14401)->and($host['provenance']['generation'])->toBe($contact->generation)
        ->and($read->processes($machine)['processes'])->toBeNull()->and($read->capabilities($machine)['capabilities'])->toBeNull();
    app(EvaluateFleet::class)->run();
    expect(DB::table('telemetry_contacts')->first())->toEqual($contact)->and(Machine::count())->toBe(1);
});

it('rejects expired contact before mutations and never revives a future unavailable projection', function () {
    [$machine, $token] = $this->telemetryMachine();
    $this->travelTo(Carbon::parse('2026-10-16T16:00:00Z'));
    $this->freshContact($machine, $token, $this->telemetryReport())->assertOk();
    $tables = ['telemetry_contacts', 'telemetry_streams', 'telemetry_boots', 'telemetry_accepted'];
    $before = array_map(fn ($table) => DB::table($table)->get()->all(), $tables);
    $expired = $this->telemetryReport(2);
    $expired['sampled_at'] = now()->subHours(4)->subSecond()->toIso8601String();
    $this->freshContact($machine, $token, $expired)->assertConflict()->assertExactJson(['error' => 'expired_contact']);
    expect(array_map(fn ($table) => DB::table($table)->get()->all(), $tables))->toEqual($before)
        ->and(DB::table('telemetry_challenges')->value('consumed_at'))->toBeNull();
    $future = $this->telemetryReport(3);
    $future['sampled_at'] = now()->addSecond()->toIso8601String();
    $this->freshContact($machine, $token, $future)->assertOk();
    $this->travel(1)->seconds();
    $read = app(FleetSummary::class);
    expect($read->host($machine)['metrics'])->toBeNull()->and($read->host($machine)['unavailable_reason'])->toBe('observation_unavailable');
    app(EvaluateFleet::class)->run();
    $this->freshContact($machine, $token, $this->telemetryReport(4))->assertOk();
    expect($read->host($machine)['metrics'])->not->toBeNull()->and($read->host($machine)['unavailable_reason'])->toBeNull();
});

it('rolls back identity-only replay membership before returning any ACK on storage failure', function () {
    [$machine, $token] = $this->telemetryMachine();
    DB::unprepared("CREATE TRIGGER fail_membership BEFORE INSERT ON history_accepted_intervals BEGIN SELECT RAISE(ABORT, 'injected membership failure'); END");
    $old = $this->telemetryReport(2);
    $old['sampled_at'] = now()->subHours(5)->toIso8601String();
    $this->withToken($token)->postJson($this->collectorPath($machine, 'reports'), ['reports' => [$this->telemetryReport(), $old]])
        ->assertStatus(503)->assertExactJson(['error' => 'ingest_unavailable']);
    foreach (['telemetry_accepted', 'telemetry_samples', 'telemetry_streams', 'telemetry_boots', 'history_accepted_intervals'] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
});

it('clips aggregate intervals before splitting and retains selected zero duration samples once', function () {
    [$machine, $token] = $this->telemetryMachine();
    $this->travelTo(Carbon::parse('2026-10-16T16:00:00Z'));
    $reports = [];
    foreach (['2026-10-16T12:00:00Z', '2026-10-16T12:00:05Z', '2026-10-16T12:00:10Z'] as $i => $at) {
        $report = $this->telemetryReport($i + 1);
        $report['sampled_at'] = $at;
        $report['elapsed_seconds'] = $i === 2 ? null : 10;
        $report['processes']['top_cpu']['items'][0]['name'] = 'winner-'.($i + 1);
        $reports[] = $report;
    }
    $this->withToken($token)->postJson($this->collectorPath($machine, 'reports'), ['reports' => array_reverse($reports)])->assertOk();
    DB::table('history_buckets')->insert(['machine_id' => $machine->id, 'resolution' => 60, 'bucket_at' => '2026-10-16T12:00:00+00:00', 'aggregate' => '{"legacy_secret":"must-not-be-read"}']);
    foreach (['minute', 'hour'] as $resolution) {
        $timeline = app(ReadTimeline::class)->timeline($machine, now()->subHours(4), Carbon::parse('2026-10-16T12:00:10Z'), $resolution);
        expect($timeline['points'])->toHaveCount(1);
        $point = $timeline['points'][0];
        expect($point['bucket_at'])->toBe('2026-10-16T12:00:00+00:00')->and($point['count'])->toBe(3)
            ->and($point['coverage_seconds'])->toBe(5.0)->and($point['metrics']['cpu_percent']['duration'])->toBe(5.0)
            ->and($point['peak_evidence']['cpu']['process']['name'])->toBe('winner-3');
        $zero = app(ReadTimeline::class)->timeline($machine, now()->subHours(4), Carbon::parse('2026-10-16T12:00:01Z'), $resolution)['points'][0];
        expect($zero['count'])->toBe(1)->and($zero['coverage_seconds'])->toBe(0.0)->and($zero['metrics']['cpu_percent']['average'])->toBeNull();
    }
});

it('keeps live receipt backfill provenance unchanged as query time advances', function () {
    [$machine, $token] = $this->telemetryMachine();
    $this->travelTo(Carbon::parse('2026-10-16T12:00:10Z'));
    $report = $this->telemetryReport();
    $report['sampled_at'] = now()->toIso8601String();
    $this->withToken($token)->postJson($this->collectorPath($machine, 'reports'), ['reports' => [$report]])->assertOk();
    $from = now()->subMinute();
    $to = now();
    $before = [];
    foreach (['minute', 'hour'] as $resolution) {
        $before[$resolution] = app(ReadTimeline::class)->timeline($machine, $from, $to, $resolution);
        expect($before[$resolution]['points'][0]['backfilled_count'])->toBe(0);
    }
    $this->travel(31)->seconds();
    foreach (['minute', 'hour'] as $resolution) {
        expect(app(ReadTimeline::class)->timeline($machine, $from, $to, $resolution))->toEqual($before[$resolution]);
    }
});

it('rejects fractional future samples at the whole-second anchor', function () {
    $retention = new Retention(Carbon::parse('2026-10-16T12:00:00Z'));
    expect($retention->eligible('2026-10-16T12:00:00.999999Z'))->toBeFalse();
    expect($retention->eligible('2026-10-16T13:29:59.999999+05:30'))->toBeFalse();
    expect($retention->eligible('2026-10-16T13:30:00.000000+05:30'))->toBeTrue();
});

it('keeps unavailable pressure compatible with prior host rendering', function () {
    foreach (Retention::unavailable()['metrics']['pressure'] as $signals) {
        expect($signals['full']['status'])->toBe('unavailable');
        expect($signals['full']['avg10'])->toBeNull();
    }
});

it('ACKs fractional future replay without payload and masks future challenged contact', function () {
    [$machine, $token] = $this->telemetryMachine();
    $this->travelTo(Carbon::parse('2026-10-16T12:00:00Z'));
    $report = $this->telemetryReport();
    $report['sampled_at'] = '2026-10-16T17:30:00.999999+05:30';
    $this->withToken($token)->postJson($this->collectorPath($machine, 'reports'), ['reports' => [$report]])->assertOk();
    expect(DB::table('telemetry_samples')->count())->toBe(0);
    $report['sequence'] = 2;
    $this->freshContact($machine, $token, $report)->assertOk();
    expect(DB::table('telemetry_samples')->count())->toBe(0)
        ->and(json_decode(DB::table('telemetry_contacts')->value('payload'), true)['unavailable_reason'])->toBe('observation_unavailable')
        ->and(app(FleetSummary::class)->host($machine)['metrics'])->toBeNull();
});

it('keeps a current fractional collector sample visible before the next second', function () {
    [$machine, $token] = $this->telemetryMachine();
    $this->travelTo(Carbon::parse('2026-10-21T09:20:00.800000Z'));
    $report = $this->telemetryReport();
    $report['sampled_at'] = '2026-10-21T09:20:00.700000Z';
    $this->freshContact($machine, $token, $report)->assertOk();
    expect(DB::table('telemetry_samples')->count())->toBe(1)
        ->and(app(FleetSummary::class)->host($machine)['metrics'])->toEqual($report['metrics']);
    $this->travelTo(Carbon::parse('2026-10-21T09:20:01.100000Z'));
    expect(app(FleetSummary::class)->host($machine)['metrics'])->toEqual($report['metrics']);
    expect((new Retention)->eligible('2026-10-21T09:20:01.100001Z'))->toBeFalse();
});
