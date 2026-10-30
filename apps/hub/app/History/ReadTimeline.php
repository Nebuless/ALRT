<?php

namespace App\History;

use App\Models\Machine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReadTimeline
{
    /** @return array<string, mixed> */
    public function timeline(Machine $machine, Carbon $from, Carbon $to, ?string $resolution = null, ?Retention $retention = null): array
    {
        $retention ??= new Retention;
        $from = $from->copy()->utc()->startOfSecond();
        $to = $to->copy()->utc()->startOfSecond();
        $resolution ??= 'raw';
        if ($to->lte($from) || $from->lt($retention->cutoff) || $to->gt($retention->now)
            || ! in_array($resolution, ['raw', 'minute', 'hour'], true)) {
            throw new InvalidArgumentException('History range must fit the retained four hours.');
        }
        $points = [];
        $intervals = [];
        $fromTimestamp = (float) $from->getTimestamp();
        $toTimestamp = (float) $to->getTimestamp();
        if ($resolution === 'raw') {
            $rows = DB::table('telemetry_samples')->where('machine_id', $machine->id)->where('sampled_at', '>=', $from->toIso8601String())
                ->where('sampled_at', '<=', $to->toIso8601String())->orderBy('sampled_at')->orderBy('id')
                ->select(['id', 'stream_id', 'sequence', 'scope', 'sampled_at', 'received_at'])
                ->selectRaw('unixepoch(sampled_at) AS sampled_epoch, unixepoch(received_at) AS received_epoch')
                ->selectRaw("json_remove(payload, '$.processes.top_cpu.items', '$.processes.top_rss.items', '$.processes.orphans.items', '$.processes.zombies.items', '$.processes.longest_running.items') AS payload")
                ->get();
            foreach ($rows as $row) {
                $report = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
                $end = (float) $row->sampled_epoch;
                $start = max($fromTimestamp, $end - min(10.0, max(0.0, (float) ($report['elapsed_seconds'] ?? 0))));
                if ($end > $start) {
                    $intervals[] = [$start, $end];
                }
                $metrics = [];
                foreach (ReportMetrics::values($report) as $name => $value) {
                    $metrics[$name] = ['average' => $value, 'min' => $value, 'max' => $value, 'count' => $value === null ? 0 : 1];
                }
                $points[] = ['sampled_at' => $row->sampled_at, 'received_at' => $row->received_at, 'stream_id' => $row->stream_id, 'sequence' => $row->sequence,
                    'scope' => $row->scope, 'backfilled' => $end < (float) $row->received_epoch - 30,
                    'metrics' => $metrics, 'coverage_seconds' => $end - $start,
                    'snapshot' => ['id' => $row->id, 'coverage' => $report['coverage']['processes'], 'categories' => $report['processes']]];
            }
        } else {
            $seconds = $resolution === 'minute' ? 60 : 3600;
            // Acceptance order preserves the first received identity on equal process peaks.
            $rows = DB::table('telemetry_samples')->where('machine_id', $machine->id)
                ->whereBetween('sampled_at', [$from->toIso8601String(), $to->toIso8601String()])->orderBy('sampled_at')->orderBy('id')
                ->select(['id', 'sampled_at', 'received_at'])->selectRaw('unixepoch(sampled_at) AS sampled_epoch, unixepoch(received_at) AS received_epoch')->selectRaw("json_remove(payload, '$.processes.orphans.items', '$.processes.zombies.items', '$.processes.longest_running.items') AS payload")->get()->sortBy('id');
            $buckets = [];
            $contribution = app(ApplyContribution::class);
            foreach ($rows as $row) {
                $contribution->apply($buckets, json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR), $row->sampled_at, (float) $row->sampled_epoch, (float) $row->received_epoch, $seconds, $fromTimestamp, $toTimestamp);
            }
            ksort($buckets);
            foreach ($buckets as $bucket => $aggregate) {
                array_push($intervals, ...$aggregate['intervals']);
                $metrics = [];
                foreach ($aggregate['metrics'] as $name => $stat) {
                    $metrics[$name] = $stat + ['average' => $stat['duration'] > 0 ? $stat['weighted_sum'] / $stat['duration'] : null];
                }
                $points[] = ['bucket_at' => Carbon::createFromTimestampUTC($bucket)->toIso8601String(), 'bucket_seconds' => $seconds, 'count' => $aggregate['count'], 'backfilled_count' => $aggregate['backfilled_count'],
                    'metrics' => $metrics, 'coverage_seconds' => Coverage::seconds($aggregate['intervals']), 'peak_evidence' => $aggregate['peak_evidence'], 'snapshot' => null];
            }
        }
        $gaps = [];
        $cursor = $fromTimestamp;
        foreach (Coverage::merge($intervals) as [$start, $end]) {
            if ($start > $cursor) {
                $gaps[] = $this->gap($cursor, $start);
            }
            $cursor = max($cursor, $end);
        }
        if ($cursor < $toTimestamp) {
            $gaps[] = $this->gap($cursor, $toTimestamp);
        }

        return ['machine_id' => $machine->id, 'from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'resolution' => $resolution,
            'coverage_basis' => 'observed_intervals_capped_at_ten_seconds', 'points' => $points, 'gaps' => $gaps];
    }

    /** @return array<string, mixed>|null */
    public function snapshot(Machine $machine, int $id): ?array
    {
        $retention = new Retention;
        $row = DB::table('telemetry_samples')->where(['machine_id' => $machine->id, 'id' => $id])
            ->whereBetween('sampled_at', [$retention->cutoff->toIso8601String(), $retention->now->toIso8601String()])->first();
        if (! $row) {
            return null;
        }
        $report = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);

        return ['id' => $id, 'sampled_at' => $row->sampled_at, 'received_at' => $row->received_at, 'scope' => $row->scope,
            'backfilled' => Carbon::parse($row->sampled_at)->lt(Carbon::parse($row->received_at)->subSeconds(30)),
            'coverage' => $report['coverage']['processes'], 'processes' => $report['processes']];
    }

    /** @return array{from: string, to: string, status: string} */
    private function gap(float $from, float $to): array
    {
        return ['from' => Carbon::createFromTimestampUTC($from)->toIso8601String(), 'to' => Carbon::createFromTimestampUTC($to)->toIso8601String(), 'status' => 'unknown'];
    }
}
