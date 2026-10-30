<?php

namespace App\Incidents;

use App\Models\HubIdentity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class IncidentStore
{
    /** @return array<string, mixed> */
    public function watch(string $target, string $rule): array
    {
        $row = DB::table('incident_watches')->where(['target' => $target, 'rule' => $rule])->first();

        return $row ? (array) $row : [
            'target' => $target, 'rule' => $rule, 'state' => 'unknown', 'last_generation' => null,
            'last_sample_at' => null, 'last_contact_at' => null, 'healthy_anchor' => null,
            'condition_since' => null, 'clear_since' => null, 'incident_id' => null, 'recovery_count' => 0,
        ];
    }

    /** @param array<string, mixed> $watch */
    public function save(array $watch): void
    {
        $watch['evaluated_at'] = now()->toIso8601String();
        DB::table('incident_watches')->updateOrInsert(['target' => $watch['target'], 'rule' => $watch['rule']], $watch);
    }

    /** @param array<string, mixed> $watch */
    public function open(array &$watch, string $name, string $kind, string $anchor, string $openedAt): void
    {
        if ($watch['incident_id'] !== null) {
            return;
        }
        $id = hash('sha256', implode('|', [$watch['target'], $watch['rule'], $anchor]));
        DB::table('incidents')->insertOrIgnore([
            'id' => $id, 'target' => $watch['target'], 'target_name' => $name, 'kind' => $kind,
            'rule' => $watch['rule'], 'anchor' => $anchor, 'opened_at' => $openedAt,
        ]);
        DB::table('incident_transitions')->insert(['incident_id' => $id, 'state' => 'opened', 'observed_at' => now()->toIso8601String()]);
        $hub = HubIdentity::find(1);
        $offset = $hub?->role === 'primary' ? 0 : ($hub?->name === 'Beta-Restricted' ? 20 : 10);
        DB::table('incident_outbox')->insertOrIgnore([
            'incident_id' => $id, 'due_at' => Carbon::parse($openedAt)->addSeconds($offset)->toIso8601String(),
        ]);
        $watch['incident_id'] = $id;
    }

    /** @param array<string, mixed> $watch */
    public function resolve(array &$watch): void
    {
        if ($watch['incident_id'] !== null) {
            $at = now()->toIso8601String();
            DB::table('incidents')->where('id', $watch['incident_id'])->whereNull('resolved_at')->update(['resolved_at' => $at]);
            DB::table('incident_transitions')->insert(['incident_id' => $watch['incident_id'], 'state' => 'resolved', 'observed_at' => $at]);
            DB::table('incident_outbox')->where('incident_id', $watch['incident_id'])->whereNull('delivered_at')->update(['cancelled_at' => $at]);
        }
        $watch['incident_id'] = null;
        $watch['condition_since'] = null;
        $watch['clear_since'] = null;
        $watch['recovery_count'] = 0;
    }
}
