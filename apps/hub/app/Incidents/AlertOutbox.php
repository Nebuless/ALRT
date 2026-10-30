<?php

namespace App\Incidents;

use App\Jobs\SendIncidentAlert;
use App\Notifications\TelegramTransport;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AlertOutbox
{
    public function __construct(private readonly TelegramTransport $telegram, private readonly ObservePeerHub $peers) {}

    public function dispatchDue(): void
    {
        foreach ($this->due()->limit(128)->pluck('incident_id') as $id) {
            SendIncidentAlert::dispatch($id)->onQueue('urgent');
        }
    }

    private function due(): Builder
    {
        return DB::table('incident_outbox')->whereNull('delivered_at')->whereNull('suppressed_at')->whereNull('cancelled_at')
            ->where('due_at', '<=', now()->toIso8601String())
            ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()->toIso8601String()));
    }

    public function deliver(string $id): void
    {
        $lease = Str::random(64);
        $claimed = DB::transaction(function () use ($id, $lease): ?object {
            $changed = $this->due()->where('incident_id', $id)->update([
                'lease_token' => $lease, 'lease_until' => now()->addSeconds((int) config('incidents.lease_seconds'))->toIso8601String(),
                'attempts' => DB::raw('attempts + 1'),
            ]);
            if ($changed !== 1) {
                return null;
            }
            $incident = DB::table('incidents')->where('id', $id)->first();
            if ($incident === null || $incident->resolved_at !== null) {
                DB::table('incident_outbox')->where('incident_id', $id)->update(['cancelled_at' => now()->toIso8601String()]);

                return null;
            }

            return $incident;
        });
        if ($claimed === null) {
            return;
        }
        if ($this->peers->deliveredElsewhere($id, $claimed->opened_at)) {
            $this->finish($id, $lease, ['suppressed_at' => now()->toIso8601String(), 'last_error' => null]);

            return;
        }
        $condition = match ($claimed->rule) {
            'silence' => 'No fresh contact',
            'cpu' => 'Sustained CPU pressure',
            'memory' => 'Sustained memory use',
            'psi_memory' => 'Sustained memory stalls',
            'psi_io' => 'Sustained I/O stalls',
            'hub_http' => 'Hub HTTP unavailable',
            'hub_scheduler' => 'Hub scheduler heartbeat stale',
            'hub_urgent_worker' => 'Hub urgent worker heartbeat stale',
            default => 'Observation needs attention',
        };
        $result = $this->telegram->send($claimed->target_name, $condition, $claimed->opened_at);
        if ($result['delivered']) {
            $this->finish($id, $lease, ['delivered_at' => now()->toIso8601String(), 'last_error' => null]);
        } else {
            $attempts = DB::table('incident_outbox')->where('incident_id', $id)->value('attempts');
            $retry = $result['retry_after'] ?? min(300, 10 * (2 ** min(5, max(0, $attempts - 1))));
            $this->finish($id, $lease, ['due_at' => now()->addSeconds($retry)->toIso8601String(), 'last_error' => $result['error']]);
        }
    }

    /** @param array<string, mixed> $values */
    private function finish(string $id, string $lease, array $values): void
    {
        DB::table('incident_outbox')->where('incident_id', $id)->where('lease_token', $lease)->update($values + ['lease_token' => null, 'lease_until' => null]);
    }
}
