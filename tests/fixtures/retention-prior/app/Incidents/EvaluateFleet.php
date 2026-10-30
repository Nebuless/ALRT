<?php

namespace App\Incidents;

use App\Models\Machine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EvaluateFleet
{
    public function __construct(private readonly IncidentStore $store, private readonly AlertOutbox $outbox) {}

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (Machine::orderBy('id')->get() as $machine) {
                $contact = DB::table('telemetry_contacts')->where('machine_id', $machine->id)->first();
                $this->silence($machine, $contact);
                if ($contact !== null) {
                    $this->resources($machine, $contact);
                }
            }
        });
        $this->outbox->dispatchDue();
    }

    private function silence(Machine $machine, ?\stdClass $contact): void
    {
        $watch = $this->store->watch($machine->id, 'silence');
        if ($contact === null) {
            $watch['state'] = 'awaiting_report';
            $this->store->save($watch);

            return;
        }
        $age = Heartbeats::age($contact->last_contact_at);
        $suspect = (int) config('incidents.suspect_seconds');
        $silent = (int) config('incidents.silent_seconds');
        $anchor = $contact->boot_id.':'.$contact->stream_id.':'.$contact->sequence;
        if ($age >= $silent) {
            $watch['state'] = 'silent';
            $watch['recovery_count'] = 0;
            $this->store->open($watch, $machine->name, 'host', $anchor, Carbon::parse($contact->last_contact_at)->addSeconds($silent)->toIso8601String());
        } elseif ($age >= $suspect) {
            $watch['state'] = $watch['incident_id'] === null ? 'suspect' : 'silent';
            $watch['recovery_count'] = 0;
        } elseif (in_array($watch['state'], ['suspect', 'silent', 'recovering'], true)) {
            if ($watch['last_generation'] !== $contact->generation) {
                $continuous = $watch['last_contact_at'] !== null && Carbon::parse($watch['last_contact_at'])->diffInSeconds(Carbon::parse($contact->last_contact_at)) <= $suspect;
                $watch['recovery_count'] = $continuous ? $watch['recovery_count'] + 1 : 1;
            }
            $watch['state'] = 'recovering';
            if ($watch['recovery_count'] >= 2) {
                $this->store->resolve($watch);
                $watch['state'] = 'healthy';
                $watch['healthy_anchor'] = $anchor;
            }
        } else {
            $watch['state'] = 'healthy';
            $watch['healthy_anchor'] = $anchor;
        }
        $watch['last_generation'] = $contact->generation;
        $watch['last_contact_at'] = $contact->last_contact_at;
        $this->store->save($watch);
    }

    private function resources(Machine $machine, \stdClass $contact): void
    {
        $report = json_decode($contact->payload, true, flags: JSON_THROW_ON_ERROR);
        $metrics = $report['metrics'];
        $cpu = ($metrics['cpu_percent']['status'] ?? '') === 'available' ? $metrics['cpu_percent']['value'] : null;
        $memory = $metrics['memory'];
        $memoryPercent = ($memory['total_bytes']['status'] ?? '') === 'available' && ($memory['used_bytes']['status'] ?? '') === 'available' && $memory['total_bytes']['value'] > 0
            ? 100 * $memory['used_bytes']['value'] / $memory['total_bytes']['value'] : null;
        $signals = [
            'cpu' => [$cpu, (float) config('incidents.cpu_threshold'), (int) config('incidents.resource_seconds')],
            'memory' => [$memoryPercent, (float) config('incidents.memory_threshold'), (int) config('incidents.resource_seconds')],
        ];
        foreach (['memory', 'io'] as $kind) {
            $pressure = $metrics['pressure'][$kind]['some'];
            $signals['psi_'.$kind] = [($pressure['status'] ?? '') === 'available' ? $pressure['avg10'] : null, (float) config('incidents.psi_threshold'), (int) config('incidents.psi_seconds')];
        }
        foreach ($signals as $rule => [$value, $threshold, $duration]) {
            $this->resource($machine, $contact, $rule, $value, $threshold, $duration);
        }
    }

    private function resource(Machine $machine, \stdClass $contact, string $rule, int|float|null $value, float $threshold, int $duration): void
    {
        $watch = $this->store->watch($machine->id, $rule);
        $sample = Carbon::parse($contact->sampled_at);
        $freshLimit = (int) config('incidents.metric_fresh_seconds');
        $sampleAge = $sample->diffInSeconds(now(), false);
        if ($sampleAge < 0 || $sampleAge > $freshLimit || Heartbeats::age($contact->last_contact_at) > $freshLimit || $value === null) {
            $watch['condition_since'] = null;
            $watch['clear_since'] = null;
            $watch['state'] = $watch['incident_id'] === null ? 'unknown' : 'active';
            $this->store->save($watch);

            return;
        }
        if ($watch['last_generation'] === $contact->generation) {
            return;
        }
        $continuous = $watch['last_sample_at'] !== null && Carbon::parse($watch['last_sample_at'])->lt($sample) && Carbon::parse($watch['last_sample_at'])->diffInSeconds($sample) <= $freshLimit;
        if (! $continuous) {
            $watch['condition_since'] = null;
            $watch['clear_since'] = null;
        }
        $anchor = $contact->boot_id.':'.$contact->stream_id.':'.$contact->sequence;
        if ($value >= $threshold) {
            $watch['clear_since'] = null;
            $watch['healthy_anchor'] ??= $anchor;
            $watch['condition_since'] ??= $contact->sampled_at;
            if (Carbon::parse($watch['condition_since'])->diffInSeconds($sample) >= $duration) {
                $this->store->open($watch, $machine->name, 'host', $watch['healthy_anchor'] ?? $anchor, Carbon::parse($watch['condition_since'])->addSeconds($duration)->toIso8601String());
            }
            $watch['state'] = $watch['incident_id'] === null ? 'pending' : 'active';
        } else {
            $watch['condition_since'] = null;
            $watch['healthy_anchor'] = $anchor;
            $watch['clear_since'] ??= $contact->sampled_at;
            if ($watch['incident_id'] !== null && Carbon::parse($watch['clear_since'])->diffInSeconds($sample) >= (int) config('incidents.clear_seconds')) {
                $this->store->resolve($watch);
            }
            $watch['state'] = $watch['incident_id'] === null ? 'healthy' : 'clearing';
        }
        $watch['last_sample_at'] = $contact->sampled_at;
        $watch['last_generation'] = $contact->generation;
        $this->store->save($watch);
    }
}
