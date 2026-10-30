<?php

namespace App\ReadModels;

use App\History\ReadTimeline;
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
        $hosts = Machine::orderBy('name')->get()->map(fn (Machine $machine) => $this->host($machine))->all();
        $incidents = app(IncidentReadModel::class)->fleet();

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
            'observed_at' => now()->toIso8601String(),
            'hub' => $this->hub(),
            'hosts' => $hosts,
            'incidents' => $incidents,
            'peer_hubs' => app(IncidentReadModel::class)->peerHubs(),
            'services' => app(Heartbeats::class)->ages(),
        ];
    }

    /** @return array<string, mixed> */
    public function host(Machine $machine): array
    {
        $contact = DB::table('telemetry_contacts')->where('machine_id', $machine->id)->first();
        $hasHistory = DB::table('telemetry_accepted')->where('machine_id', $machine->id)->exists();
        $report = $contact ? json_decode($contact->payload, true, flags: JSON_THROW_ON_ERROR) : null;
        $missing = $contact ? null : ($hasHistory ? 'no_live_contact' : 'no_report');

        $host = [
            'id' => $machine->id,
            'name' => $machine->name,
            'data_policy' => $machine->data_policy,
            'state' => $contact ? 'observed' : ($hasHistory ? 'awaiting_contact' : 'awaiting_report'),
            'observed_at' => now()->toIso8601String(),
            'contact_at' => $contact->last_contact_at ?? null,
            'contact_age_seconds' => $this->age($contact->last_contact_at ?? null),
            'sampled_at' => $contact->sampled_at ?? null,
            'sample_age_seconds' => $this->age($contact->sampled_at ?? null),
            'scope' => $report['scope'] ?? null,
            'provenance' => [
                'kind' => $contact ? 'direct_contact' : 'unobserved',
                'hub' => $this->hub(),
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
            'delivery' => app(DeliveryState::class)->observation($machine),
            'health' => app(IncidentReadModel::class)->host($machine->id),
            'actions' => app(RebootActions::class)->host($machine),
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
        $limit = max(1, min(128, $limit));
        $query = DB::table('telemetry_samples')->where('machine_id', $machine->id)->orderByDesc('id');
        if ($before !== null) {
            $query->where('id', '<', $before);
        }
        $rows = $query->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $data = $rows->take($limit)->map(fn (object $row): array => [
            'id' => $row->id,
            'received_at' => $row->received_at,
            'receipt_age_seconds' => $this->age($row->received_at),
            'sample_age_seconds' => $this->age($row->sampled_at),
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
    public function timeline(Machine $machine, Carbon $from, Carbon $to, ?string $resolution = null): array
    {
        return app(ReadTimeline::class)->timeline($machine, $from, $to, $resolution);
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

    private function age(?string $timestamp): ?int
    {
        $age = $timestamp === null ? null : Carbon::parse($timestamp)->diffInSeconds(now(), false);

        return $age === null || $age < 0 ? null : (int) $age;
    }

    /** @return array{id: ?string, name: string, role: string} */
    private function hub(): array
    {
        $identity = HubIdentity::find(1);

        return ['id' => $identity->hub_uuid ?? null, 'name' => $identity->name ?? config('nebuless.hub_name'), 'role' => $identity->role ?? config('nebuless.role')];
    }
}
