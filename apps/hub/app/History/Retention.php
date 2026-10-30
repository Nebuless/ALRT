<?php

namespace App\History;

use Illuminate\Support\Carbon;

class Retention
{
    public readonly Carbon $now;

    public readonly Carbon $cutoff;

    public function __construct(?Carbon $now = null)
    {
        $this->now = ($now ?? now())->copy()->utc();
        $this->cutoff = $this->now->copy()->startOfSecond()->subSeconds(14400);
    }

    public function eligible(string $sampledAt): bool
    {
        return Carbon::parse($sampledAt)->utc()->betweenIncluded($this->cutoff, $this->now);
    }

    /** Empty evidence compatible with the prior evaluator, never a public report.
     * @return array<string, mixed>
     */
    public static function unavailable(): array
    {
        $value = ['status' => 'unavailable', 'value' => null];
        $pressure = array_fill_keys(['some', 'full'], ['status' => 'unavailable', 'avg10' => null]);

        return ['unavailable_reason' => 'observation_unavailable', 'metrics' => [
            'cpu_percent' => $value,
            'memory' => ['used_bytes' => $value, 'total_bytes' => $value],
            'pressure' => ['memory' => $pressure, 'io' => $pressure],
        ]];
    }

    /** @return array<string, mixed>|null */
    public function evidence(string $sampledAt, string $payload): ?array
    {
        if (! $this->eligible($sampledAt)) {
            return null;
        }
        $report = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

        return isset($report['unavailable_reason']) ? null : $report;
    }
}
