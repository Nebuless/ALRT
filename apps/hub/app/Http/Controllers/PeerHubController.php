<?php

namespace App\Http\Controllers;

use App\Incidents\Heartbeats;
use App\Models\HubIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PeerHubController extends Controller
{
    public function __invoke(Heartbeats $heartbeats): JsonResponse
    {
        $receipts = DB::table('incident_outbox')->whereNotNull('delivered_at')
            ->where('delivered_at', '>=', now()->subSeconds((int) config('incidents.receipt_fresh_seconds'))->toIso8601String())
            ->orderByDesc('delivered_at')->limit(128)->get(['incident_id', 'delivered_at']);

        return response()->json([
            'hub_id' => HubIdentity::find(1)?->hub_uuid,
            'observed_at' => now()->toIso8601String(),
            'heartbeats' => $heartbeats->ages(),
            'delivered_incidents' => $receipts,
        ])->header('Cache-Control', 'no-store, private');
    }
}
