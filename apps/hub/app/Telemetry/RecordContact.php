<?php

namespace App\Telemetry;

use App\Actions\RebootState;
use App\History\AcceptedIntervals;
use App\History\Retention;
use App\Models\HubIdentity;
use App\Models\Machine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecordContact
{
    public function __construct(private readonly IngestReport $ingest, private readonly DeliveryState $deliveryState) {}

    /** @return array{nonce: string, expires_at: string} */
    public function challenge(Machine $machine): array
    {
        return app(CurrentTransaction::class)->run(function () use ($machine): array {
            $nonce = Str::random(64);
            $expires = now()->addSeconds(30);
            DB::table('telemetry_challenges')->updateOrInsert(['machine_id' => $machine->id], [
                'nonce_hash' => hash('sha256', $nonce),
                'generation' => DB::table('telemetry_contacts')->where('machine_id', $machine->id)->value('generation') ?? 0,
                'issued_at' => now()->toIso8601String(),
                'expires_at' => $expires->toIso8601String(),
                'consumed_at' => null,
            ]);

            return ['nonce' => $nonce, 'expires_at' => $expires->toIso8601String()];
        });
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>|null  $delivery
     * @return array{accepted: list<array{stream_id: string, sequence: int}>, contact_at: string}
     */
    public function record(Machine $machine, string $nonce, array $report, ?array $delivery = null): array
    {
        $retention = new Retention;

        return app(CurrentTransaction::class)->run(function () use ($machine, $nonce, $report, $delivery, $retention): array {
            if (Carbon::parse($report['sampled_at'])->utc()->startOfSecond()->lt($retention->cutoff)
                || app(AcceptedIntervals::class)->contains($machine->id, $report['stream_id'], $report['sequence'])) {
                throw new TelemetryException('expired_contact', 409);
            }
            $challenge = DB::table('telemetry_challenges')->where('machine_id', $machine->id)->first();
            $current = DB::table('telemetry_contacts')->where('machine_id', $machine->id)->first();
            if (! $challenge || $challenge->consumed_at !== null ||
                ! hash_equals($challenge->nonce_hash, hash('sha256', $nonce)) ||
                Carbon::parse($challenge->expires_at)->lte($retention->now) ||
                $challenge->generation !== ($current->generation ?? 0)) {
                throw new TelemetryException('stale_challenge', 409);
            }
            $stream = DB::table('telemetry_streams')->where(['machine_id' => $machine->id, 'stream_id' => $report['stream_id']])->first();
            $boot = DB::table('telemetry_boots')->where(['machine_id' => $machine->id, 'boot_id' => $report['boot_id']])->first();
            if (($stream->retired ?? false) || ($boot->retired ?? false)) {
                throw new TelemetryException('retired_stream', 409);
            }
            if (($stream && $report['sequence'] < $stream->highest_sequence) ||
                ($current && $current->stream_id === $report['stream_id'] && $report['sequence'] <= $current->sequence)) {
                throw new TelemetryException('stale_contact', 409);
            }
            $ack = $this->ingest->accept($machine, $report, $retention);
            if ($current && $current->stream_id !== $report['stream_id']) {
                DB::table('telemetry_streams')->where(['machine_id' => $machine->id, 'stream_id' => $current->stream_id])->update(['retired' => true]);
            }
            if ($current && $current->boot_id !== $report['boot_id']) {
                DB::table('telemetry_boots')->where(['machine_id' => $machine->id, 'boot_id' => $current->boot_id])->update(['retired' => true]);
                DB::table('telemetry_streams')->where(['machine_id' => $machine->id, 'boot_id' => $current->boot_id])->update(['retired' => true]);
            }
            $received = $retention->now->toIso8601String();
            DB::table('telemetry_contacts')->updateOrInsert(['machine_id' => $machine->id], [
                'generation' => ($current->generation ?? 0) + 1,
                'stream_id' => $report['stream_id'],
                'boot_id' => $report['boot_id'],
                'sequence' => $report['sequence'],
                'last_contact_at' => $received,
                'sampled_at' => Carbon::parse($report['sampled_at'])->utc()->toIso8601String(),
                'payload' => $this->ingest->canonical($retention->eligible($report['sampled_at']) ? $report : Retention::unavailable()),
            ]);
            if ($delivery !== null) {
                $this->deliveryState->record($machine, $delivery);
            }
            DB::table('telemetry_challenges')->where('machine_id', $machine->id)->update(['consumed_at' => $received]);

            $hub = HubIdentity::find(1);
            if ($hub?->role === 'primary' && $hub->name === 'Primary-Hub') {
                app(RebootState::class)->synchronize($machine);
            }

            return ['accepted' => [$ack], 'contact_at' => $received];
        });
    }
}
