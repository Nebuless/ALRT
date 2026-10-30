<?php

namespace App\History;

class ApplyContribution
{
    /**
     * @param  array<int, array<string, mixed>>  $buckets
     * @param  array<string, mixed>  $report
     */
    public function apply(array &$buckets, array $report, string $sampledAt, float $sampledEpoch, float $receivedEpoch, int $resolution, float $rangeStart, float $rangeEnd): void
    {
        $backfilled = $sampledEpoch < $receivedEpoch - 30;
        $values = ReportMetrics::values($report);
        $end = $sampledEpoch;
        $start = max($rangeStart, $end - min(10.0, max(0.0, (float) ($report['elapsed_seconds'] ?? 0))));
        $end = min($end, $rangeEnd);
        $first = (int) floor($start / $resolution) * $resolution;
        $lastIncludedInstant = $end > $start ? $end - 0.000001 : $end;
        $last = (int) floor($lastIncludedInstant / $resolution) * $resolution;
        for ($bucket = $first; $bucket <= $last; $bucket += $resolution) {
            $aggregate = $buckets[$bucket] ?? ['count' => 0, 'backfilled_count' => 0, 'intervals' => [], 'metrics' => [], 'peak_evidence' => []];
            $from = max($start, (float) $bucket);
            $to = min($end, (float) ($bucket + $resolution));
            $duration = $to - $from;
            $aggregate['count']++;
            $aggregate['backfilled_count'] += $backfilled ? 1 : 0;
            if ($duration > 0) {
                $aggregate['intervals'][] = [$from, $to];
            }
            foreach ($values as $name => $value) {
                $stat = $aggregate['metrics'][$name] ?? ['count' => 0, 'duration' => 0.0, 'weighted_sum' => 0.0, 'min' => null, 'max' => null];
                if ($value !== null) {
                    $stat['count']++;
                    $stat['duration'] += $duration;
                    $stat['weighted_sum'] += $value * $duration;
                    $stat['min'] = $stat['min'] === null ? $value : min($stat['min'], $value);
                    $stat['max'] = $stat['max'] === null ? $value : max($stat['max'], $value);
                }
                $aggregate['metrics'][$name] = $stat;
            }
            foreach (['cpu' => ['top_cpu', 'cpu_percent'], 'memory' => ['top_rss', 'rss_bytes']] as $kind => [$category, $field]) {
                foreach ($report['processes'][$category]['items'] as $process) {
                    $value = $process[$field];
                    if ($value !== null && $value > ($aggregate['peak_evidence'][$kind]['value'] ?? -1)) {
                        $aggregate['peak_evidence'][$kind] = ['kind' => 'peak_process_evidence', 'value' => $value, 'process' => $process,
                            'sampled_at' => $sampledAt, 'scope' => $report['scope'], 'completeness' => $report['processes'][$category]['completeness']];
                    }
                }
            }
            $buckets[$bucket] = $aggregate;
        }
    }
}
