<?php

namespace App\Jobs;

use App\Incidents\Heartbeats;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecordUrgentHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 5;

    public function __construct()
    {
        $this->onQueue('urgent');
    }

    public function handle(Heartbeats $heartbeats): void
    {
        $heartbeats->beat('urgent_worker');
    }
}
