<?php

namespace App\ReadModels;

use App\Incidents\IncidentReadModel;
use App\Models\HubIdentity;
use App\Models\Machine;

class ReportingPaths
{
    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $identity = HubIdentity::find(1);
        $paths = [];
        $peers = app(IncidentReadModel::class)->peerHubs();
        foreach (Machine::orderBy('name')->get() as $machine) {
            $evidence = app(FleetSummary::class)->paths($machine);
            foreach ($evidence['paths'] as $direct) {
                $paths[] = $direct + ['source_name' => $machine->name, 'target_name' => $direct['target']['name'], 'target_hub_id' => $direct['target']['id'], 'target_role' => $direct['target']['role']];
            }
            $claims = array_column($evidence['indirect_paths'], null, 'target_hub_id');
            foreach ($peers as $peer) {
                $claim = $peer['hub_id'] === null ? null : ($claims[$peer['hub_id']] ?? null);
                unset($claims[$peer['hub_id']]);
                $paths[] = ($claim ?? $this->unknown($machine->id, $peer['hub_id'])) + ['source_name' => $machine->name, 'target_name' => $peer['name'], 'target_role' => $peer['name'] === 'Primary-Hub' ? 'primary' : 'standby'];
            }
            foreach ($claims as $claim) {
                if ($claim['target_hub_id'] !== $identity?->hub_uuid) {
                    $paths[] = $claim + ['source_name' => $machine->name, 'target_name' => $claim['target_hub_id'], 'target_role' => 'unknown'];
                }
            }
        }

        return $paths;
    }

    /** @return array<string, mixed> */
    private function unknown(string $source, ?string $target): array
    {
        return ['source' => $source, 'target_hub_id' => $target, 'provenance' => 'unobserved', 'state' => 'unknown', 'observed_at' => null,
            'age_seconds' => null, 'observation_age_seconds' => null, 'claim_age_seconds' => null, 'unavailable_reason' => 'no_collector_ack_claim'];
    }
}
