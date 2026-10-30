<?php

namespace App\History;

class Coverage
{
    /** @param list<array{0: float|int, 1: float|int}> $intervals
     * @return list<array{0: float, 1: float}>
     */
    public static function merge(array $intervals): array
    {
        usort($intervals, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($intervals as [$from, $to]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $from <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], (float) $to);
            } else {
                $merged[] = [(float) $from, (float) $to];
            }
        }

        return $merged;
    }

    /** @param list<array{0: float|int, 1: float|int}> $intervals */
    public static function seconds(array $intervals): float
    {
        return array_sum(array_map(fn (array $interval): float => $interval[1] - $interval[0], self::merge($intervals)));
    }
}
