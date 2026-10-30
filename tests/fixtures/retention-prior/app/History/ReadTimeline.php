<?php

namespace App\History;

use App\Models\Machine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReadTimeline
{
    /** @return array<string, mixed> */
    public function timeline(Machine $machine, Carbon $from, Carbon $to, ?string $resolution = null): array
    {
        $from = $from->copy()->utc();
        $to = $to->copy()->utc();
        if ($to->lte($from) || $from->lt(now()->startOfSecond()->subYears(config('history.hour_years')))) {
            throw new InvalidArgumentException('History range must fit the retained year.');
        }
        $resolution ??= $from->gte(now()->startOfSecond()->subDays(config('history.raw_days'))) && $from->diffInHours($to) <= 6 ? 'raw'
            : ($from->gte(now()->startOfSecond()->subDays(config('history.minute_days'))) && $from->diffInDays($to) <= 1 ? 'minute' : 'hour');
        if (! in_array($resolution, ['raw', 'minute', 'hour'], true) || ($resolution === 'raw' && $from->diffInHours($to) > 6)
            || ($resolution === 'minute' && $from->diffInDays($to) > 1)
            || ($resolution === 'raw' && $from->lt(now()->startOfSecond()->subDays(config('history.raw_days'))))
            || ($resolution === 'minute' && $from->lt(now()->startOfSecond()->subDays(config('history.minute_days'))))) {
            throw new InvalidArgumentException('Unsupported history resolution or raw range.');
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
            $bucketFrom = Carbon::createFromTimestampUTC((int) floor($from->getTimestamp() / $seconds) * $seconds);
            $rows = DB::table('history_buckets')->where(['machine_id' => $machine->id, 'resolution' => $seconds])
                ->where('bucket_at', '>=', $bucketFrom->toIso8601String())->where('bucket_at', '<', $to->toIso8601String())->orderBy('bucket_at')->get();
            foreach ($rows as $row) {
                $aggregate = json_decode($row->aggregate, true, flags: JSON_THROW_ON_ERROR);
                $clipped = [];
                foreach ($aggregate['intervals'] as [$start, $end]) {
                    $start = max($start, (float) $from->getTimestamp());
                    $end = min($end, (float) $to->getTimestamp());
                    if ($end > $start) {
                        $clipped[] = [$start, $end];
                        $intervals[] = [$start, $end];
                    }
                }
                $metrics = [];
                foreach ($aggregate['metrics'] as $name => $stat) {
                    $metrics[$name] = $stat + ['average' => $stat['duration'] > 0 ? $stat['weighted_sum'] / $stat['duration'] : null];
                }
                $points[] = ['bucket_at' => $row->bucket_at, 'bucket_seconds' => $seconds, 'count' => $aggregate['count'], 'backfilled_count' => $aggregate['backfilled_count'],
                    'metrics' => $metrics, 'coverage_seconds' => Coverage::seconds($clipped), 'peak_evidence' => $aggregate['peak_evidence'], 'snapshot' => null];
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
        $row = DB::table('telemetry_samples')->where(['machine_id' => $machine->id, 'id' => $id])
            ->where('sampled_at', '>=', now()->startOfSecond()->subDays(config('history.raw_days'))->toIso8601String())->first();
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
