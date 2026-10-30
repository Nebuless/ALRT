<?php

namespace App\Telemetry;

use App\Models\Machine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DeliveryState
{
    /** Validated collector facts, called only inside the challenged-contact transaction.
     * @param  array<string, mixed>  $delivery
     */
    public function record(Machine $machine, array $delivery): void
    {
        DB::table('telemetry_delivery')->updateOrInsert(['machine_id' => $machine->id], [
            'observed_at' => now()->toIso8601String(),
            'payload' => json_encode($delivery, JSON_THROW_ON_ERROR),
        ]);
        foreach ($delivery['acknowledgements'] as $ack) {
            $key = ['machine_id' => $machine->id, 'hub_id' => $ack['hub_id']];
            $existing = DB::table('telemetry_delivery_acks')->where($key)->first();
            if ($existing && $existing->acknowledged_at === $ack['acknowledged_at'] && $existing->stream_id === $ack['stream_id'] && $existing->sequence === $ack['sequence']) {
                continue;
            }
            DB::table('telemetry_delivery_acks')->updateOrInsert($key, [
                'stream_id' => $ack['stream_id'], 'sequence' => $ack['sequence'],
                'acknowledged_at' => $ack['acknowledged_at'], 'observed_at' => now()->toIso8601String(),
            ]);
        }
        DB::table('telemetry_delivery_acks')->where('machine_id', $machine->id)
            ->whereNotIn('hub_id', array_column($delivery['acknowledgements'], 'hub_id'))->delete();
    }

    /** @return array<string, mixed>|null */
    public function observation(Machine $machine): ?array
    {
        $row = DB::table('telemetry_delivery')->where('machine_id', $machine->id)->first();

        return $row ? $this->present($row) : null;
    }

    /**
     * @param  list<string>  $machines
     * @return array<string, array<string, mixed>>
     */
    public function fleet(array $machines): array
    {
        return DB::table('telemetry_delivery')->whereIn('machine_id', $machines)->get()->mapWithKeys(fn (object $row): array => [$row->machine_id => $this->present($row)])->all();
    }

    /** @return array<string, mixed> */
    private function present(\stdClass $row): array
    {
        return json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR) + [
            'provenance' => 'collector_claim', 'observed_at' => $row->observed_at,
            'observation_age_seconds' => $this->age($row->observed_at),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function paths(Machine $machine): array
    {
        return array_values(DB::table('telemetry_delivery_acks')->where('machine_id', $machine->id)->orderBy('hub_id')->get()->map(fn (object $row): array => [
            'source' => $machine->id, 'target_hub_id' => $row->hub_id,
            'provenance' => 'collector_claim', 'stream_id' => $row->stream_id, 'sequence' => $row->sequence,
            'acknowledged_at' => $row->acknowledged_at, 'claim_age_seconds' => $this->age($row->acknowledged_at),
            'observed_at' => $row->observed_at, 'observation_age_seconds' => $this->age($row->observed_at),
            'state' => Carbon::parse($row->acknowledged_at)->gt(now()) ? 'unknown' : ($this->age($row->acknowledged_at) > 30 || $this->age($row->observed_at) > 30 ? 'stale' : 'reported'),
        ])->all());
    }

    private function age(string $timestamp): int
    {
        return (int) max(0, Carbon::parse($timestamp)->diffInSeconds(now(), false));
    }
}
