<?php

namespace App\History;

class ReportMetrics
{
    /** @param array<string, mixed> $report
     * @return array<string, float|null>
     */
    public static function values(array $report): array
    {
        $metrics = $report['metrics'];
        $values = ['cpu_percent' => $metrics['cpu_percent']['value']];
        foreach (['memory', 'load'] as $group) {
            foreach ($metrics[$group] as $name => $metric) {
                $values[$group.'.'.$name] = $metric['value'];
            }
        }
        foreach (['disks', 'network'] as $group) {
            foreach ($metrics[$group] as $device) {
                foreach ($device as $name => $metric) {
                    if ($name !== 'label') {
                        $values[$group.'.'.$device['label'].'.'.$name] = $metric['value'];
                    }
                }
            }
        }
        foreach ($metrics['pressure'] as $resource => $kinds) {
            foreach ($kinds as $kind => $metric) {
                foreach (['avg10', 'avg60', 'avg300', 'total_microseconds'] as $name) {
                    $values['pressure.'.$resource.'.'.$kind.'.'.$name] = $metric[$name];
                }
            }
        }

        return array_map(fn ($value): ?float => $value === null ? null : (float) $value, $values);
    }
}
