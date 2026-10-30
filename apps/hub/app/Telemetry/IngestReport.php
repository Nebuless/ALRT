<?php

namespace App\Telemetry;

use App\History\AcceptedIntervals;
use App\History\Retention;
use App\Models\Machine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class IngestReport
{
    /**
     * All reports have already passed ValidateReport; ACK only after this transaction returns.
     *
     * @param  list<array<string, mixed>>  $reports
     * @return list<array{stream_id: string, sequence: int}>
     */
    public function batch(Machine $machine, array $reports): array
    {
        $retention = new Retention;

        return DB::transaction(fn (): array => array_map(fn (array $report) => $this->accept($machine, $report, $retention), $reports));
    }

    /**
     * Called inside the accepted-record transaction, including for challenged contacts.
     *
     * @param  array<string, mixed>  $report
     * @return array{stream_id: string, sequence: int}
     */
    public function accept(Machine $machine, array $report, ?Retention $retention = null): array
    {
        $retention ??= new Retention;
        $identity = ['machine_id' => $machine->id, 'stream_id' => $report['stream_id'], 'sequence' => $report['sequence']];
        $payload = $this->canonical($report);
        $hash = hash('sha256', $payload);
        if (app(AcceptedIntervals::class)->contains($machine->id, $report['stream_id'], $report['sequence'])) {
            DB::table('telemetry_streams')->where(['machine_id' => $machine->id, 'stream_id' => $report['stream_id']])->update(['last_received_at' => $retention->now->toIso8601String()]);

            return ['stream_id' => $report['stream_id'], 'sequence' => $report['sequence']];
        }
        $accepted = DB::table('telemetry_accepted')->where($identity)->first();
        if ($accepted) {
            if (! hash_equals($accepted->content_hash, $hash)) {
                throw new TelemetryException('identity_conflict', 409);
            }

            DB::table('telemetry_streams')->where(['machine_id' => $machine->id, 'stream_id' => $report['stream_id']])->update(['last_received_at' => $retention->now->toIso8601String()]);

            return ['stream_id' => $report['stream_id'], 'sequence' => $report['sequence']];
        }
        $streamKey = ['machine_id' => $machine->id, 'stream_id' => $report['stream_id']];
        $stream = DB::table('telemetry_streams')->where($streamKey)->first();
        if ($stream && $stream->boot_id !== $report['boot_id']) {
            throw new TelemetryException('stream_boot_conflict', 409);
        }
        $received = $retention->now->toIso8601String();
        DB::table('telemetry_boots')->insertOrIgnore(['machine_id' => $machine->id, 'boot_id' => $report['boot_id'], 'retired' => false]);
        DB::table('telemetry_streams')->updateOrInsert($streamKey, [
            'boot_id' => $report['boot_id'],
            'retired' => $stream->retired ?? false,
            'highest_sequence' => max($stream->highest_sequence ?? 0, $report['sequence']),
            'first_received_at' => $stream->first_received_at ?? $received,
            'last_received_at' => $received,
        ]);
        $sampled = Carbon::parse($report['sampled_at'])->utc();
        if (! $retention->eligible($report['sampled_at'])) {
            app(AcceptedIntervals::class)->remember($machine->id, $report['stream_id'], $report['sequence']);

            return ['stream_id' => $report['stream_id'], 'sequence' => $report['sequence']];
        }
        $acceptedId = DB::table('telemetry_accepted')->insertGetId($identity + ['content_hash' => $hash, 'accepted_at' => $received, 'sampled_at' => $sampled->toIso8601String()]);
        DB::table('telemetry_samples')->insert($identity + [
            'accepted_id' => $acceptedId,
            'boot_id' => $report['boot_id'],
            'sampled_at' => $sampled->toIso8601String(),
            'received_at' => $received,
            'scope' => $report['scope'],
            'cpu_percent' => $report['metrics']['cpu_percent']['value'],
            'memory_used_bytes' => $report['metrics']['memory']['used_bytes']['value'],
            'memory_total_bytes' => $report['metrics']['memory']['total_bytes']['value'],
            'psi_memory_some_avg10' => $report['metrics']['pressure']['memory']['some']['avg10'],
            'psi_io_some_avg10' => $report['metrics']['pressure']['io']['some']['avg10'],
            'payload' => $payload,
        ]);

        return ['stream_id' => $report['stream_id'], 'sequence' => $report['sequence']];
    }

    /** @param array<string, mixed> $report */
    public function canonical(array $report): string
    {
        return json_encode($this->sortObjects($report), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function sortObjects(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map($this->sortObjects(...), $value);
    }
}
