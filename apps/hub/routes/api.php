<?php

use App\Http\Controllers\CitizensController;
use App\Http\Controllers\FleetController;
use App\Http\Controllers\PeerHubController;
use App\Http\Controllers\RebootController;
use App\Http\Controllers\TelemetryController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/collector/{machine}')->middleware('collector')->group(function (): void {
    Route::post('/citizens/challenge', [CitizensController::class, 'challenge']);
    Route::post('/citizens', [CitizensController::class, 'exchange']);
    Route::post('/actions/pickup', [RebootController::class, 'pickup']);
    Route::post('/actions/{action}/ack', [RebootController::class, 'acknowledge']);
    Route::post('/actions/{action}/permit', [RebootController::class, 'permit']);
    Route::post('/actions/{action}/result', [RebootController::class, 'result']);
    Route::post('/reports', [TelemetryController::class, 'reports']);
    Route::post('/challenge', [TelemetryController::class, 'challenge']);
    Route::post('/contact', [TelemetryController::class, 'contact']);
    Route::get('/hub-health', PeerHubController::class);
});

Route::prefix('v1')->middleware(['web', 'auth', 'owner', 'cache.headers:no_store;private'])->group(function (): void {
    Route::get('/citizens', [CitizensController::class, 'index']);
    Route::post('/citizens/{machine}/{citizen}/terminate', [CitizensController::class, 'terminate'])->middleware('throttle:owner-action-confirmation');
    Route::get('/hosts/{machine}/actions', [RebootController::class, 'show']);
    Route::post('/hosts/{machine}/reboot', [RebootController::class, 'queue'])->middleware('throttle:owner-action-confirmation');
    Route::post('/hosts/{machine}/reboots/{action}/cancel', [RebootController::class, 'cancel']);
    Route::get('/reporting-paths', [FleetController::class, 'reportingPaths']);
    Route::get('/hosts/{machine}/incidents', [FleetController::class, 'incidents']);
    Route::get('/fleet', [FleetController::class, 'fleet']);
    Route::get('/hosts/{machine}', [FleetController::class, 'host']);
    Route::get('/hosts/{machine}/processes', [FleetController::class, 'processes']);
    Route::get('/hosts/{machine}/capabilities', [FleetController::class, 'capabilities']);
    Route::get('/hosts/{machine}/paths', [FleetController::class, 'paths']);
    Route::get('/hosts/{machine}/history', [FleetController::class, 'history']);
    Route::get('/hosts/{machine}/history/snapshots/{sample}', [FleetController::class, 'snapshot'])->whereNumber('sample');
});
