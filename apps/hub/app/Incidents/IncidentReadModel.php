<?php

namespace App\Incidents;

use App\Models\HubIdentity;
use Illuminate\Support\Facades\DB;

class IncidentReadModel
{
    /** @return array<string, mixed> */
    public function host(string $machine): array
    {
        $watch = DB::table('incident_watches')->where(['target' => $machine, 'rule' => 'silence'])->first();
        $incidents = $this->active($machine);

        return $this->health($watch, $incidents);
    }

    /**
     * @param  list<string>  $machines
     * @param  list<array<string, mixed>>  $incidents
     * @return array<string, array<string, mixed>>
     */
    public function hosts(array $machines, array $incidents): array
    {
        $watches = DB::table('incident_watches')->whereIn('target', $machines)->where('rule', 'silence')->get()->keyBy('target');
        $grouped = [];
        foreach ($incidents as $incident) {
            $grouped[$incident['target']][] = $incident;
        }
        $hosts = [];
        foreach ($machines as $machine) {
            $hosts[$machine] = $this->health($watches->get($machine), $grouped[$machine] ?? []);
        }

        return $hosts;
    }

    /**
     * @param  list<array<string, mixed>>  $incidents
     * @return array<string, mixed>
     */
    private function health(?\stdClass $watch, array $incidents): array
    {
        $state = $watch->state ?? 'awaiting_evaluation';
        if ($state === 'healthy' && $incidents !== []) {
            $state = 'pressure';
        }
        $evaluatedAt = $watch->evaluated_at ?? null;
        $age = $evaluatedAt === null ? null : Heartbeats::age($evaluatedAt);
        $stale = $age !== null && $age > (int) config('incidents.heartbeat_stale_seconds');

        return ['state' => $evaluatedAt === null ? 'awaiting_evaluation' : ($stale ? 'unknown' : $state), 'observed_at' => now()->toIso8601String(),
            'evaluated_at' => $evaluatedAt, 'evaluation_age_seconds' => $age,
            'unavailable_reason' => $stale ? 'evaluation_stale' : ($evaluatedAt === null ? 'awaiting_evaluation' : null),
            'incidents' => $incidents];
    }

    /** @return list<array<string, mixed>> */
    public function fleet(): array
    {
        return $this->active();
    }

    /** @return list<array<string, mixed>> */
    public function today(string $machine): array
    {
        $start = now()->utc()->startOfDay()->toIso8601String();

        return array_values(DB::table('incidents')->where('target', $machine)
            ->where(fn ($query) => $query->where('opened_at', '>=', $start)->orWhere('resolved_at', '>=', $start)->orWhereNull('resolved_at'))
            ->orderBy('opened_at')->orderBy('id')->get()->map(fn (object $row): array => [
                'id' => $row->id, 'target' => $row->target, 'target_name' => $row->target_name, 'kind' => $row->kind,
                'rule' => $row->rule, 'opened_at' => $row->opened_at, 'resolved_at' => $row->resolved_at,
                'provenance' => 'local_incident_evaluation', 'observed_at' => now()->toIso8601String(),
            ])->all());
    }

    /** @return list<array<string, mixed>> */
    public function peerHubs(): array
    {
        $rows = DB::table('peer_hub_observations')->orderBy('name')->get()->map(fn (object $row): array => [
            'hub_id' => $row->hub_id, 'name' => $row->name, 'http_state' => $row->http_state,
            'observed_at' => $row->observed_at, 'observation_age_seconds' => Heartbeats::age($row->observed_at),
            'peer_observed_at' => $row->peer_observed_at,
            'scheduler_age_seconds' => $row->scheduler_age_seconds,
            'urgent_worker_age_seconds' => $row->urgent_worker_age_seconds,
            'provenance' => 'authenticated_peer_probe',
        ])->all();
        $observedNames = array_column($rows, 'name');
        foreach (HubIdentity::find(1)->peers ?? [] as $name => $origin) {
            if (! in_array($name, $observedNames, true)) {
                $rows[] = ['hub_id' => null, 'name' => $name, 'http_state' => 'unknown', 'observed_at' => null,
                    'observation_age_seconds' => null, 'peer_observed_at' => null, 'scheduler_age_seconds' => null,
                    'urgent_worker_age_seconds' => null, 'provenance' => 'unobserved', 'unavailable_reason' => 'no_authenticated_peer_observation'];
            }
        }
        usort($rows, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function active(?string $target = null): array
    {
        $query = DB::table('incidents')->leftJoin('incident_outbox', 'incident_outbox.incident_id', '=', 'incidents.id')
            ->select('incidents.*', 'incident_outbox.delivered_at', 'incident_outbox.suppressed_at', 'incident_outbox.due_at', 'incident_outbox.attempts', 'incident_outbox.last_error')
            ->whereNull('incidents.resolved_at');
        if ($target !== null) {
            $query->where('incidents.target', $target);
        }

        return array_values($query->orderByRaw("CASE WHEN incidents.rule = 'silence' THEN 0 WHEN incidents.rule LIKE 'hub_%' THEN 1 WHEN incidents.rule LIKE 'psi_%' THEN 2 ELSE 3 END")
            ->orderBy('incidents.opened_at')->orderBy('incidents.target')->orderBy('incidents.rule')->get()->map(fn (object $row): array => [
                'id' => $row->id, 'target' => $row->target, 'target_name' => $row->target_name,
                'kind' => $row->kind, 'rule' => $row->rule, 'opened_at' => $row->opened_at,
                'age_seconds' => Heartbeats::age($row->opened_at), 'provenance' => 'local_incident_evaluation',
                'alert' => ['delivered_at' => $row->delivered_at ?? null, 'suppressed_at' => $row->suppressed_at ?? null,
                    'next_attempt_at' => $row->due_at ?? null, 'attempts' => $row->attempts ?? 0, 'last_error' => $row->last_error ?? null],
            ])->all());
    }
}
