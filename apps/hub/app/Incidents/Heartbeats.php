<?php

namespace App\Incidents;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class Heartbeats
{
    public function beat(string $service): void
    {
        if (! in_array($service, ['scheduler', 'urgent_worker'], true)) {
            throw new \InvalidArgumentException('Unknown hub service.');
        }
        DB::table('hub_heartbeats')->updateOrInsert(['service' => $service], ['observed_at' => now()->toIso8601String()]);
    }

    /** @return array{scheduler: ?int, urgent_worker: ?int} */
    public function ages(): array
    {
        $rows = DB::table('hub_heartbeats')->pluck('observed_at', 'service');

        return [
            'scheduler' => isset($rows['scheduler']) ? self::age($rows['scheduler']) : null,
            'urgent_worker' => isset($rows['urgent_worker']) ? self::age($rows['urgent_worker']) : null,
        ];
    }

    public static function age(string $timestamp): int
    {
        return (int) max(0, Carbon::parse($timestamp)->diffInSeconds(now(), false));
    }
}
