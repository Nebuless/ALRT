<?php

use App\Actions\RebootState;
use App\Incidents\EvaluateFleet;
use App\Incidents\Heartbeats;
use App\Incidents\ObservePeerHub;
use App\Jobs\PruneHistory;
use App\Jobs\RecordUrgentHeartbeat;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function (): void {
    app(Heartbeats::class)->beat('scheduler');
    RecordUrgentHeartbeat::dispatch();
    app(ObservePeerHub::class)->run();
    app(EvaluateFleet::class)->run();
    app(RebootState::class)->reconcile();
})->everyTenSeconds()->name('fleet.observe');

Schedule::job(new PruneHistory, 'maintenance')->everyMinute()->name('fleet.prune');
