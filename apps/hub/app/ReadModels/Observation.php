<?php

namespace App\ReadModels;

class Observation
{
    /** @param array<string, mixed> $host
     * @return array{state: string, sentence: string, detail: ?string, priority: int, unavailable_reasons: list<string>}
     */
    public static function describe(array $host): array
    {
        $contact = $host['contact_age_seconds'];
        $sample = $host['sample_age_seconds'];
        $clockSkew = $sample === null && $host['sampled_at'] !== null;
        $state = $host['health']['state'];
        $reasons = self::measurementReasons($host['metrics'] ?? []);
        if (($host['coverage']['processes']['status'] ?? 'unavailable') !== 'complete') {
            $reasons[] = 'process coverage: '.($host['coverage']['processes']['status'] ?? 'unavailable');
        }
        foreach ($host['processes'] ?? [] as $category => $list) {
            $rankedSelection = in_array($category, ['top_cpu', 'top_rss', 'longest_running'], true)
                && $list['completeness'] === 'partial' && $list['reason'] === 'category_limit'
                && count($list['items']) === 10 && $list['total_observed'] > 10;
            if ($list['completeness'] !== 'complete' && ! $rankedSelection) {
                $reasons[] = $category.': '.$list['completeness'];
            }
        }
        if ($contact === null) {
            $state = 'awaiting_report';
        } elseif ($contact >= 90) {
            $state = 'silent';
        } elseif ($contact >= 45) {
            $state = 'suspect';
        } elseif ($clockSkew) {
            $state = 'unknown';
            $reasons[] = 'sample timestamp: ahead_of_hub_clock';
        } elseif ($sample === null || $sample > (int) config('incidents.metric_fresh_seconds')) {
            $state = 'stale_metrics';
        } elseif ($state === 'healthy' && $reasons !== []) {
            $state = 'partial_data';
        }
        $name = $host['name'];
        $rules = array_column($host['health']['incidents'], 'rule');
        $pressure = in_array('psi_memory', $rules, true) || in_array('psi_io', $rules, true);
        $sentence = match ($state) {
            'silent' => "$name is silent.",
            'suspect' => "$name has stopped checking in.",
            'pressure' => $pressure ? "$name is under pressure." : "$name has sustained resource use.",
            'healthy' => "$name is calm.",
            'partial_data' => "$name has incomplete measurements.",
            'stale_metrics' => "$name has old measurements.",
            'unknown' => $clockSkew ? "$name has unknown measurements." : "$name is awaiting a current assessment.",
            'awaiting_report' => "$name is waiting for its first live report.",
            'recovering' => "$name is checking in again.",
            default => "$name is awaiting a current assessment.",
        };

        $culprit = $host['processes']['top_cpu']['items'][0] ?? null;
        $detail = $culprit !== null && in_array($state, ['silent', 'suspect', 'pressure'], true)
            ? 'Last sample: '.$culprit['name'].' used '.self::number($culprit['cpu_percent'], '%').' CPU. A possible contributor, not a confirmed cause.' : null;
        if ($state === 'unknown' && $clockSkew) {
            $detail = 'Measurements are unknown because the sample timestamp is ahead of this hub’s clock.';
        }

        return ['state' => $state, 'sentence' => $sentence, 'detail' => $detail, 'unavailable_reasons' => $reasons, 'priority' => match ($state) {
            'silent' => 0, 'suspect' => 1, 'pressure' => $pressure ? 2 : 3, 'healthy' => 6, default => 4,
        }];
    }

    public static function hubServiceSentence(string $name, string $rule): string
    {
        $service = match ($rule) {
            'hub_http' => 'HTTP service',
            'hub_scheduler' => 'scheduler',
            'hub_urgent_worker' => 'urgent worker',
            default => 'hub service',
        };

        return "{$name}’s $service is unavailable.";
    }

    /** @param array<string, mixed> $metrics
     * @return list<string>
     */
    private static function measurementReasons(array $metrics, string $prefix = ''): array
    {
        $reasons = [];
        foreach ($metrics as $name => $value) {
            if (! is_array($value)) {
                continue;
            }
            $field = $prefix.$name;
            if (isset($value['status'])) {
                if ($value['status'] !== 'available' && ! (str_starts_with($field, 'pressure.') && $value['status'] === 'unsupported')) {
                    $reasons[] = $field.': '.$value['status'];
                }
            } else {
                $reasons = array_merge($reasons, self::measurementReasons($value, $field.'.'));
            }
        }

        return $reasons;
    }

    public static function age(?int $seconds): string
    {
        if ($seconds === null) {
            return 'unknown';
        }

        return $seconds < 60 ? $seconds.' s' : ($seconds < 3600 ? (int) floor($seconds / 60).' min' : (int) floor($seconds / 3600).' h '.(int) floor(($seconds % 3600) / 60).' min');
    }

    public static function number(int|float|null $value, string $unit = ''): string
    {
        return $value === null ? 'Unavailable' : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.').$unit;
    }

    public static function bytes(int|float|null $value): string
    {
        if ($value === null) {
            return 'Unavailable';
        }
        foreach (['B', 'KiB', 'MiB', 'GiB', 'TiB'] as $unit) {
            if ($value < 1024 || $unit === 'TiB') {
                return self::number($value, ' '.$unit);
            }
            $value /= 1024;
        }

        return 'Unavailable';
    }
}
