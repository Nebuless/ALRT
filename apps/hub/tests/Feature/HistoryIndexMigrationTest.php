<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\TelemetryDatabase;

uses(TelemetryDatabase::class);

it('preserves retained public evidence across indexed upgrade rollback and subsequent writes', function () {
    $this->prepareTelemetryDatabase();
    $path = database_path('migrations/2026_10_15_000050_cover_raw_history_timeline.php');
    if (is_file($path)) {
        (require $path)->down();
    }
    [$machine, $token] = $this->telemetryMachine();
    $this->travelTo(Carbon::parse('2026-10-15T12:06:00Z'));
    $reports = [];
    foreach ([0, 30, 90] as $index => $offset) {
        $report = $this->telemetryReport($index + 1);
        $report['sampled_at'] = Carbon::parse('2026-10-15T12:00:00Z')->addSeconds($offset)->toIso8601String();
        $report['metrics']['cpu_percent']['value'] = [0, 99, 20][$index];
        $report['elapsed_seconds'] = $index === 1 ? 5.5 : 10;
        $reports[] = $report;
    }
    $this->withToken($token)->postJson($this->collectorPath($machine, 'reports'), ['reports' => $reports])->assertOk();
    $this->telemetryOwner();
    $this->withHeader('Authorization', '');
    $historyQuery = null;
    $failDrop = false;
    DB::listen(function (QueryExecuted $event) use (&$historyQuery, &$failDrop): void {
        if (str_starts_with(strtolower(ltrim($event->sql)), 'select ') && str_contains($event->sql, 'json_remove(payload,')) {
            $historyQuery = [$event->sql, $event->bindings];
        }
        if ($failDrop && $event->sql === 'DROP INDEX telemetry_samples_machine_id_sampled_at_index') {
            throw new RuntimeException('injected failure after index DDL');
        }
    });
    $url = '/api/v1/hosts/'.$machine->id.'/history';
    $timelineUrl = $url.'?'.http_build_query(['from' => '2026-10-15T11:59:50Z', 'to' => '2026-10-15T12:06:00Z', 'resolution' => 'raw']);
    $evidence = function () use ($timelineUrl, $url): array {
        $response = $this->getJson($timelineUrl)->assertOk();
        $snapshots = [];
        foreach ($response->json('points') as $point) {
            $snapshots[] = $this->getJson($url.'/snapshots/'.$point['snapshot']['id'])->assertOk()->json();
        }

        return ['timeline' => $response->json(), 'timeline_bytes' => $response->getContent(), 'snapshots' => $snapshots,
            'identities' => DB::table('telemetry_accepted')->orderBy('id')->get()->all(),
            'rollups' => DB::table('history_buckets')->orderBy('id')->get()->all()];
    };
    $executesProjection = function () use (&$historyQuery): bool {
        expect($historyQuery)->not->toBeNull();
        assert(is_array($historyQuery));

        return collect(DB::select('EXPLAIN '.$historyQuery[0], $historyQuery[1]))->contains(
            fn ($row) => in_array($row->opcode, ['Function', 'PureFunc'], true) && str_contains((string) $row->p4, 'json_remove')
        );
    };
    $before = $evidence();
    expect($before['timeline']['points'])->toHaveCount(3)->and($before['snapshots'])->toHaveCount(3)
        ->and($executesProjection())->toBeTrue();
    $migration = require $path;
    $migration->up();
    expect($evidence())->toEqual($before)->and($executesProjection())->toBeFalse();

    $new = $this->telemetryReport(4);
    $new['sampled_at'] = now()->toIso8601String();
    $this->withToken($token)->postJson($this->collectorPath($machine, 'reports'), ['reports' => [$new]])->assertOk();
    $this->withHeader('Authorization', '');
    $inserted = $evidence();
    expect($inserted['timeline']['points'])->toHaveCount(4)->and($executesProjection())->toBeFalse();
    $id = $inserted['timeline']['points'][3]['snapshot']['id'];
    $this->getJson($url.'/snapshots/'.$id)->assertOk()->assertJsonPath('data.processes', fn ($actual) => $actual == $new['processes']);
    DB::table('telemetry_samples')->where('id', $id)->delete();
    $deleted = $evidence();
    expect($deleted['timeline'])->toEqual($before['timeline'])->and($deleted['snapshots'])->toEqual($before['snapshots']);
    $this->getJson($url.'/snapshots/'.$id)->assertNotFound();
    $migration->down();
    expect($evidence())->toEqual($deleted)->and($executesProjection())->toBeTrue();
    $migration->up();
    expect($evidence())->toEqual($deleted)->and($executesProjection())->toBeFalse();
    $migration->down();
    $failDrop = true;
    try {
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'injected failure after index DDL');
    } finally {
        $failDrop = false;
    }
    expect($evidence())->toEqual($deleted)->and($executesProjection())->toBeTrue();
    $migration->up();
    expect($evidence())->toEqual($deleted)->and($executesProjection())->toBeFalse();
});
