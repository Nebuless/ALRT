<?php

namespace App\Incidents;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ObservePeerHub
{
    public function __construct(private readonly PeerCredentials $credentials, private readonly IncidentStore $store) {}

    public function run(): void
    {
        foreach ($this->credentials->configured() as $peer) {
            $body = null;
            try {
                $options = ['allow_redirects' => false];
                if ($peer['proxy_url'] !== null) {
                    $options['proxy'] = $peer['proxy_url'];
                }
                $response = Http::acceptJson()->withToken($peer['token'])->timeout((float) config('incidents.peer_timeout_seconds'))
                    ->connectTimeout((float) config('incidents.peer_timeout_seconds'))->withOptions($options)
                    ->get($peer['origin'].'/api/v1/collector/'.$peer['machine_id'].'/hub-health');
                if ($response->successful() && strlen($response->body()) <= 65536) {
                    $body = $this->validated($response->json(), $peer['hub_id']);
                }
            } catch (\Throwable) {
                // Transport diagnostics can contain bearer tokens and private proxy URLs.
            }
            DB::transaction(function () use ($peer, $body): void {
                $old = DB::table('peer_hub_observations')->where('hub_id', $peer['hub_id'])->first();
                DB::table('peer_hub_observations')->updateOrInsert(['hub_id' => $peer['hub_id']], [
                    'name' => $peer['name'], 'http_state' => $body === null ? 'unreachable' : 'authenticated',
                    'first_observed_at' => $old->first_observed_at ?? now()->toIso8601String(),
                    'observed_at' => now()->toIso8601String(), 'peer_observed_at' => $body['observed_at'] ?? null,
                    'scheduler_age_seconds' => $body['heartbeats']['scheduler'] ?? null,
                    'urgent_worker_age_seconds' => $body['heartbeats']['urgent_worker'] ?? null,
                    'receipts' => json_encode($body['delivered_incidents'] ?? [], JSON_THROW_ON_ERROR),
                ]);
                foreach (['http', 'scheduler', 'urgent_worker'] as $service) {
                    $age = $body['heartbeats'][$service] ?? null;
                    $failed = $service === 'http' ? $body === null : ($body !== null && ($age === null || $age >= (int) config('incidents.heartbeat_stale_seconds')));
                    $unknown = $service !== 'http' && $body === null;
                    $anchor = $body === null ? null : Carbon::parse($body['observed_at'])->subSeconds($age ?? 0)->toIso8601String();
                    $this->service($peer['hub_id'], $peer['name'], $service, $failed, $unknown, $anchor);
                }
            });
        }
    }

    private function service(string $id, string $name, string $service, bool $failed, bool $unknown, ?string $anchor): void
    {
        $watch = $this->store->watch($id, 'hub_'.$service);
        if ($unknown) {
            $watch['state'] = $watch['incident_id'] === null ? 'unknown' : 'active';
        } elseif ($failed) {
            $watch['condition_since'] ??= now()->toIso8601String();
            $threshold = $service === 'http' ? (int) config('incidents.silent_seconds') : 0;
            if (Heartbeats::age($watch['condition_since']) >= $threshold) {
                $this->store->open($watch, $name, 'hub', $watch['healthy_anchor'] ?? $watch['condition_since'], Carbon::parse($watch['condition_since'])->addSeconds($threshold)->toIso8601String());
            }
            $watch['state'] = $watch['incident_id'] === null ? 'suspect' : 'active';
        } else {
            $this->store->resolve($watch);
            $watch['state'] = 'healthy';
            $watch['healthy_anchor'] = $anchor;
        }
        $this->store->save($watch);
    }

    /** @return array<string, mixed>|null */
    private function validated(mixed $body, string $expected): ?array
    {
        if (! is_array($body) || ($body['hub_id'] ?? null) !== $expected || ! is_string($body['observed_at'] ?? null) || ! is_array($body['heartbeats'] ?? null) || ! is_array($body['delivered_incidents'] ?? null) || count($body['delivered_incidents']) > 128) {
            return null;
        }
        try {
            if (abs(Carbon::parse($body['observed_at'])->diffInSeconds(now(), false)) > 15) {
                return null;
            }
            foreach (['scheduler', 'urgent_worker'] as $service) {
                $age = $body['heartbeats'][$service] ?? null;
                if ($age !== null && (! is_int($age) || $age < 0 || $age > 31536000)) {
                    return null;
                }
            }
            foreach ($body['delivered_incidents'] as $receipt) {
                if (! is_array($receipt) || ! is_string($receipt['incident_id'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $receipt['incident_id']) || ! is_string($receipt['delivered_at'] ?? null)) {
                    return null;
                }
                Carbon::parse($receipt['delivered_at']);
            }
        } catch (\Throwable) {
            return null;
        }

        return $body;
    }

    public function deliveredElsewhere(string $incident, string $openedAt): bool
    {
        foreach (DB::table('peer_hub_observations')->where('http_state', 'authenticated')->get() as $peer) {
            if (Heartbeats::age($peer->observed_at) > 15 || $peer->peer_observed_at === null || abs(Carbon::parse($peer->peer_observed_at)->diffInSeconds(now(), false)) > 15) {
                continue;
            }
            foreach (json_decode($peer->receipts, true, flags: JSON_THROW_ON_ERROR) as $receipt) {
                $at = Carbon::parse($receipt['delivered_at']);
                if ($receipt['incident_id'] === $incident && $at->gte(Carbon::parse($openedAt)) && $at->lte(now()) && Heartbeats::age($receipt['delivered_at']) <= (int) config('incidents.receipt_fresh_seconds')) {
                    return true;
                }
            }
        }

        return false;
    }
}
