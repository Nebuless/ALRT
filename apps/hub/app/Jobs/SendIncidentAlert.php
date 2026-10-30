<?php

namespace App\Jobs;

use App\Incidents\AlertOutbox;
use App\Incidents\Heartbeats;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendIncidentAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(public readonly string $incidentId) {}

    public function handle(AlertOutbox $outbox, Heartbeats $heartbeats): void
    {
        $heartbeats->beat('urgent_worker');
        $outbox->deliver($this->incidentId);
    }
}
