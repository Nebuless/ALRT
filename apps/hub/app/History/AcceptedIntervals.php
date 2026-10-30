<?php

namespace App\History;

use Illuminate\Support\Facades\DB;

class AcceptedIntervals
{
    public function contains(string $machine, string $stream, int $sequence): bool
    {
        return DB::table('history_accepted_intervals')->where(['machine_id' => $machine, 'stream_id' => $stream])
            ->where('first_sequence', '<=', $sequence)->where('last_sequence', '>=', $sequence)->exists();
    }

    /** Must run in the same write transaction as deleting the accepted row. */
    public function remember(string $machine, string $stream, int $sequence): void
    {
        $key = ['machine_id' => $machine, 'stream_id' => $stream];
        $adjacent = DB::table('history_accepted_intervals')->where($key)
            ->where('first_sequence', '<=', $sequence + 1)->where('last_sequence', '>=', $sequence - 1)->get();
        $first = $last = $sequence;
        foreach ($adjacent as $interval) {
            $first = min($first, $interval->first_sequence);
            $last = max($last, $interval->last_sequence);
        }
        DB::table('history_accepted_intervals')->whereIn('id', $adjacent->pluck('id'))->delete();
        DB::table('history_accepted_intervals')->insert($key + ['first_sequence' => $first, 'last_sequence' => $last]);
    }
}
