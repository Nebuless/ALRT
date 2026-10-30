<?php

namespace App\ReadModels;

use App\History\ReadTimeline;
use App\History\Retention;
use App\Incidents\Heartbeats;
use App\Incidents\IncidentReadModel;
use App\Models\HubIdentity;
use App\Models\Machine;
use App\Telemetry\DeliveryState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FleetSummary
{
    /** @return array<string, mixed> */
    public function fleet(): array
    {
        $retention = new Retention;
        $machines = Machine::orderBy('name')->get();
        $ids = array_values($machines->map(fn (Machine $machine): string => $machine->id)->all());
        $contacts = DB::table('telemetry_contacts')->whereIn('machine_id', $ids)->get()->keyBy('machine_id');
        $missing = array_values(array_diff($ids, $contacts->keys()->all()));
        $history = $missing === [] ? [] : DB::table('telemetry_streams')->whereIn('machine_id', $missing)->distinct()->pluck('machine_id')->all();
        $identity = HubIdentity::find(1);
        $hub = ['id' => $identity->hub_uuid ?? null, 'name' => $identity->name ?? config('nebuless.hub_name'), 'role' => $identity->role ?? config('nebuless.role')];
        $incidents = app(IncidentReadModel::class)->fleet();
        $health = app(IncidentReadModel::class)->hosts($ids, $incidents);
        $delivery = app(DeliveryState::class)->fleet($ids);
        $actions = app(RebootActions::class)->fleet($machines, $contacts, $identity);
        $hosts = $machines->map(fn (Machine $machine) => $this->host($machine, $retention, [
            'contact' => $contacts->get($machine->id), 'history' => in_array($machine->id, $history, true),
            'hub' => $hub, 'health' => $health[$machine->id], 'delivery' => $delivery[$machine->id] ?? null,
            'actions' => $actions[$machine->id],
        ]))->all();

        $ordered = $hosts;
        $attention = count(array_filter($hosts, fn (array $host): bool => $host['observation']['state'] !== 'healthy'));
        foreach ($incidents as $incident) {
            if ($incident['kind'] !== 'hub') {
                continue;
            }
            $attention++;
            $ordered[] = ['id' => $incident['id'], 'name' => $incident['target_name'], 'observation' => [
                'priority' => 2, 'sentence' => Observation::hubServiceSentence($incident['target_name'], $incident['rule']), 'detail' => null,
            ]];
        }
        usort($ordered, fn (array $a, array $b): int => [$a['observation']['priority'], $a['name'], $a['id']] <=> [$b['observation']['priority'], $b['name'], $b['id']]);

        return [
            'verdict' => $attention > 0 ? $ordered[0]['observation']['sentence'] : ($hosts === [] ? 'Waiting for your fleet.' : 'Nothing’s wrong.'),
            'verdict_detail' => $ordered[0]['observation']['detail'] ?? null,
            'attention_count' => $attention,
            'state' => $hosts === [] ? 'awaiting_enrollment' : 'observing',
            'observed_at' => $retention->now->toIso8601String(),
            'hub' => $hub,
            'hosts' => $hosts,
            'incidents' => $incidents,
            'peer_hubs' => app(IncidentReadModel::class)->peerHubs(),
            'services' => app(Heartbeats::class)->ages(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $preloaded  Fleet-call-local observations; never retained on the read model.
     * @return array<string, mixed>
     */
    public function host(Machine $machine, ?Retention $retention = null, ?array $preloaded = null): array
    {
        $retention ??= new Retention;
        $contact = $preloaded !== null ? $preloaded['contact'] : DB::table('telemetry_contacts')->where('machine_id', $machine->id)->first();
        $hasHistory = ! $contact && ($preloaded !== null ? $preloaded['history'] : DB::table('telemetry_streams')->where('machine_id', $machine->id)->exists());
        $report = $contact ? $retention->evidence($contact->sampled_at, $contact->payload) : null;
        $missing = $contact ? ($report === null ? 'observation_unavailable' : null) : ($hasHistory ? 'no_live_contact' : 'no_report');

        $host = [
            'id' => $machine->id,
            'name' => $machine->name,
            'data_policy' => $machine->data_policy,
            'state' => $contact ? 'observed' : ($hasHistory ? 'awaiting_contact' : 'awaiting_report'),
            'observed_at' => $retention->now->toIso8601String(),
            'contact_at' => $contact->last_contact_at ?? null,
            'contact_age_seconds' => $this->age($contact->last_contact_at ?? null, $retention->now),
            'sampled_at' => $contact->sampled_at ?? null,
            'sample_age_seconds' => $this->age($contact->sampled_at ?? null, $retention->now),
            'scope' => $report['scope'] ?? null,
            'provenance' => [
                'kind' => $contact ? 'direct_contact' : 'unobserved',
                'hub' => $preloaded !== null ? $preloaded['hub'] : $this->hub(),
                'stream_id' => $contact->stream_id ?? null,
                'boot_id' => $contact->boot_id ?? null,
                'sequence' => $contact->sequence ?? null,
                'generation' => $contact->generation ?? null,
            ],
            'unavailable_reason' => $missing,
            'metrics' => $report['metrics'] ?? null,
            'capabilities' => $report['capabilities'] ?? null,
            'coverage' => $report['coverage'] ?? null,
            'processes' => $report['processes'] ?? null,
            'delivery' => $preloaded !== null ? $preloaded['delivery'] : app(DeliveryState::class)->observation($machine),
            'health' => $preloaded !== null ? $preloaded['health'] : app(IncidentReadModel::class)->host($machine->id),
            'actions' => $preloaded !== null ? $preloaded['actions'] : app(RebootActions::class)->host($machine),
        ];

        return $host + ['observation' => Observation::describe($host)];
    }

    /** @return array<string, mixed> */
    public function processes(Machine $machine): array
    {
        return $this->observation($machine, ['processes', 'coverage']);
    }

    /** @return array<string, mixed> */
    public function capabilities(Machine $machine): array
    {
        return $this->observation($machine, ['capabilities']);
    }

    /** @return array<string, mixed> */
    public function paths(Machine $machine): array
    {
        $receipt = DB::table('telemetry_streams')->where('machine_id', $machine->id)->max('last_received_at');
        $contact = DB::table('telemetry_contacts')->where('machine_id', $machine->id)->value('last_contact_at');
        $latest = max($receipt ?? '', $contact ?? '') ?: null;

        $indirect = app(DeliveryState::class)->paths($machine);

        return [
            'machine_id' => $machine->id,
            'observed_at' => now()->toIso8601String(),
            'paths' => [[
                'source' => $machine->id,
                'target' => $this->hub(),
                'provenance' => $latest ? 'direct_receipt' : 'unobserved',
                'observed_at' => $latest,
                'age_seconds' => $this->age($latest),
                'contact_age_seconds' => $this->age($contact),
                'state' => $latest ? ($this->age($latest) > 30 ? 'stale' : 'observed') : 'unknown',
                'unavailable_reason' => $latest ? null : 'no_receipt',
            ]],
            'indirect_paths' => $indirect,
            'indirect_unavailable_reason' => $indirect === [] ? 'no_collector_ack_claims' : null,
        ];
    }

    /**
     * Retention order cursor, not wall-clock order: late replay remains queryable.
     *
     * @return array<string, mixed>
     */
    public function history(Machine $machine, int $limit = 50, ?int $before = null): array
    {
        $retention = new Retention;
        $limit = max(1, min(128, $limit));
        $query = DB::table('telemetry_samples')->where('machine_id', $machine->id)
            ->whereBetween('sampled_at', [$retention->cutoff->toIso8601String(), $retention->now->toIso8601String()])->orderByDesc('id');
        if ($before !== null) {
            $query->where('id', '<', $before);
        }
        $rows = $query->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $data = $rows->take($limit)->map(fn (object $row): array => [
            'id' => $row->id,
            'received_at' => $row->received_at,
            'receipt_age_seconds' => $this->age($row->received_at, $retention->now),
            'sample_age_seconds' => $this->age($row->sampled_at, $retention->now),
            'scope' => $row->scope,
            'provenance' => ['kind' => 'direct_receipt', 'hub' => $this->hub()],
            'report' => json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR),
        ])->values()->all();

        return [
            'data' => $data,
            'next_before' => $more ? $rows->take($limit)->last()?->id : null,
            'unavailable_reason' => $data === [] ? 'no_retained_samples' : null,
            'order' => 'receipt_desc',
        ];
    }

    /** @return array<string, mixed> */
    public function timeline(Machine $machine, Carbon $from, Carbon $to, ?string $resolution = null, ?Retention $retention = null): array
    {
        return app(ReadTimeline::class)->timeline($machine, $from, $to, $resolution, $retention);
    }

    /** @return array<string, mixed>|null */
    public function snapshot(Machine $machine, int $sample): ?array
    {
        return app(ReadTimeline::class)->snapshot($machine, $sample);
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function observation(Machine $machine, array $fields): array
    {
        return array_intersect_key($this->host($machine), array_flip(array_merge([
            'id', 'observed_at', 'contact_at', 'contact_age_seconds', 'sampled_at', 'sample_age_seconds', 'scope', 'provenance', 'unavailable_reason',
        ], $fields)));
    }

    private function age(?string $timestamp, ?Carbon $now = null): ?int
    {
        $age = $timestamp === null ? null : Carbon::parse($timestamp)->diffInSeconds($now ?? now(), false);

        return $age === null || $age < 0 ? null : (int) $age;
    }

    /** @return array{id: ?string, name: string, role: string} */
    private function hub(): array
    {
        $identity = HubIdentity::find(1);

        return ['id' => $identity->hub_uuid ?? null, 'name' => $identity->name ?? config('nebuless.hub_name'), 'role' => $identity->role ?? config('nebuless.role')];
    }
}
