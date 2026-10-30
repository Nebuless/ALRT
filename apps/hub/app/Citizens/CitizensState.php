<?php

namespace App\Citizens;

use App\Models\HubIdentity;
use App\Models\Machine;
use App\Telemetry\CurrentTransaction;
use App\Telemetry\TelemetryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

class CitizensState
{
    public function __construct(private readonly CurrentTransaction $transaction) {}

    public function primary(): bool
    {
        $hub = HubIdentity::find(1);

        return $hub?->role === 'primary' && $hub->name === 'Primary-Hub';
    }

    /** @return array{nonce: string, expires_at: string} */
    public function challenge(Machine $machine): array
    {
        return $this->transaction->run(function () use ($machine): array {
            $nonce = Str::random(64);
            $expires = now()->addSeconds(10)->format('Y-m-d\TH:i:s.u\Z');
            DB::table('citizen_challenges')->updateOrInsert(['machine_id' => $machine->id], [
                'nonce_hash' => hash('sha256', $nonce),
                'generation' => DB::table('citizen_contacts')->where('machine_id', $machine->id)->value('generation') ?? 0,
                'expires_at' => $expires, 'consumed_at' => null,
            ]);

            return ['nonce' => $nonce, 'expires_at' => $expires];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{accepted: true, commands: list<array<string, mixed>>}
     */
    public function exchange(Machine $machine, array $data): array
    {
        return $this->transaction->run(function () use ($machine, $data): array {
            // Recheck freshness inside the writer lock, not only during HTTP decoding.
            if (! $this->fresh($data['sampled_at'])) {
                throw new TelemetryException('stale_citizens', 409);
            }
            $current = DB::table('citizen_contacts')->where('machine_id', $machine->id)->first();
            $challenge = DB::table('citizen_challenges')->where('machine_id', $machine->id)->first();
            if (! $challenge || $challenge->consumed_at !== null ||
                ! hash_equals($challenge->nonce_hash, hash('sha256', $data['nonce'])) ||
                Carbon::parse($challenge->expires_at)->lte(now()) || $challenge->generation !== ($current->generation ?? 0)) {
                throw new TelemetryException('stale_challenge', 409);
            }
            $incarnation = DB::table('citizen_incarnations')->where(['machine_id' => $machine->id, 'bridge_id' => $data['bridge_id']])->first();
            if (($incarnation && ($incarnation->retired || $incarnation->boot_id !== $data['boot_id'] || $incarnation->sequence >= $data['sequence'])) ||
                ($current && $current->boot_id !== $data['boot_id'] && DB::table('citizen_incarnations')->where(['machine_id' => $machine->id, 'boot_id' => $data['boot_id']])->exists()) ||
                ($current && $current->bridge_id === $data['bridge_id'] && Carbon::parse($data['sampled_at'])->lt(Carbon::parse($current->sampled_at)))) {
                throw new TelemetryException('replayed_citizens', 409);
            }
            $this->results($machine, $data);
            if ($current && $current->bridge_id !== $data['bridge_id']) {
                DB::table('citizen_incarnations')->where(['machine_id' => $machine->id, 'bridge_id' => $current->bridge_id])->update(['retired' => true]);
            }
            if ($current && $current->boot_id !== $data['boot_id']) {
                DB::table('citizen_incarnations')->where(['machine_id' => $machine->id, 'boot_id' => $current->boot_id])->update(['retired' => true]);
            }
            DB::table('citizen_incarnations')->updateOrInsert(['machine_id' => $machine->id, 'bridge_id' => $data['bridge_id']], [
                'boot_id' => $data['boot_id'], 'sequence' => $data['sequence'], 'retired' => false,
            ]);
            $generation = ($current->generation ?? 0) + 1;
            DB::table('citizen_contacts')->updateOrInsert(['machine_id' => $machine->id], [
                'generation' => $generation, 'boot_id' => $data['boot_id'], 'bridge_id' => $data['bridge_id'],
                'sequence' => $data['sequence'], 'sampled_at' => $data['sampled_at'], 'last_contact_at' => now()->toISOString(), 'coverage' => $data['coverage'],
            ]);
            if ($data['coverage'] === 'complete') {
                DB::table('citizen_observations')->where('machine_id', $machine->id)->whereNotIn('citizen_id', array_column($data['citizens'], 'id'))->delete();
            }
            foreach ($data['citizens'] as $row) {
                $payload = json_encode($row, JSON_THROW_ON_ERROR);
                DB::table('citizen_observations')->updateOrInsert(['machine_id' => $machine->id, 'citizen_id' => $row['id']], [
                    'generation' => $generation, 'observed_at' => $row['observed_at'], 'payload' => $payload,
                    'revision' => hash('sha256', json_encode([$machine->id, $data['boot_id'], $data['bridge_id'], $row['id'], $row['process'], $row['attribution']], JSON_THROW_ON_ERROR)),
                ]);
            }
            $this->prune();
            DB::table('citizen_challenges')->where('machine_id', $machine->id)->update(['consumed_at' => now()->toISOString()]);
            $contact = DB::table('citizen_contacts')->where('machine_id', $machine->id)->first();
            $this->synchronize($machine, $contact);
            $commands = [];
            if ($this->primary()) {
                $rows = $this->rows($machine, $contact, true);
                foreach (DB::table('citizen_actions')->where(['machine_id' => $machine->id, 'status' => 'requested'])->whereNull('dispatched_at')->get() as $action) {
                    $row = $rows[$action->citizen_id] ?? null;
                    if (! $row || ! $row['can_terminate'] || ! $this->sameProcess($action, $row)) {
                        DB::table('citizen_actions')->where('id', $action->id)->update(['status' => 'expired']);

                        continue;
                    }
                    // Commit dispatch before returning authority. A lost response never retries.
                    DB::table('citizen_actions')->where('id', $action->id)->whereNull('dispatched_at')->update(['status' => 'dispatched', 'dispatched_at' => now()->toISOString()]);
                    $commands[] = [
                        'id' => $action->id, 'citizen_id' => $action->citizen_id, 'boot_id' => $action->boot_id,
                        'bridge_id' => $action->bridge_id, 'pid' => $action->pid, 'start_ticks' => $action->start_ticks, 'expires_at' => $action->expires_at,
                    ];
                }
            }

            return ['accepted' => true, 'commands' => $commands];
        });
    }

    /** @return array{id: string, status: string} */
    public function terminate(Machine $machine, string $citizen, string $revision): array
    {
        return $this->transaction->run(function () use ($machine, $citizen, $revision): array {
            if (! $this->primary()) {
                throw new TelemetryException('primary_required', 403);
            }
            $contact = DB::table('citizen_contacts')->where('machine_id', $machine->id)->first();
            $this->synchronize($machine, $contact);
            $row = $this->rows($machine, $contact, true)[$citizen] ?? null;
            if (! $contact || ! $row || ! $row['can_terminate'] || ! hash_equals($row['revision'], $revision)) {
                throw new TelemetryException('citizen_unavailable', 409);
            }
            // Citizen aliases cannot bypass uncertain process outcomes.
            if (DB::table('citizen_actions')->where('machine_id', $machine->id)->where('boot_id', $row['process']['boot_id'])
                ->where('pid', $row['process']['pid'])->where('start_ticks', $row['process']['start_ticks'])
                ->whereIn('status', ['requested', 'dispatched', 'unknown'])->exists()) {
                throw new TelemetryException('action_pending', 409);
            }
            $id = (string) Str::uuid();
            DB::table('citizen_actions')->insert([
                'id' => $id, 'machine_id' => $machine->id, 'citizen_id' => $citizen, 'revision' => $revision,
                'boot_id' => $contact->boot_id, 'bridge_id' => $contact->bridge_id,
                'pid' => $row['process']['pid'], 'start_ticks' => $row['process']['start_ticks'],
                'status' => 'requested', 'requested_at' => now()->toISOString(), 'expires_at' => now()->addSeconds(5)->toISOString(),
            ]);

            return ['id' => $id, 'status' => 'requested'];
        });
    }

    /** @return array<string, mixed> */
    public function roster(): array
    {
        $primary = $this->primary();
        $hosts = [];
        foreach (Machine::orderBy('name')->get() as $machine) {
            $contact = DB::table('citizen_contacts')->where('machine_id', $machine->id)->first();
            $hosts[] = [
                'id' => $machine->id, 'name' => $machine->name, 'last_contact_at' => $contact->last_contact_at ?? null,
                'coverage' => $contact && $this->fresh($contact->sampled_at) && $this->fresh($contact->last_contact_at) ? $contact->coverage : 'unavailable',
                'citizens' => array_values($this->rows($machine, $contact, $primary)),
            ];
        }

        return ['observed_at' => now()->toISOString(), 'primary' => $primary, 'hosts' => $hosts];
    }

    /** Only observations are subject to the inclusive four-hour window. */
    public function prune(): void
    {
        $ids = DB::table('citizen_observations')->where('observed_at', '<', now()->subHours(4)->toISOString())->limit(128)->pluck('rowid');
        if ($ids->isNotEmpty()) {
            DB::table('citizen_observations')->whereIn('rowid', $ids)->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function results(Machine $machine, array $data): void
    {
        foreach ($data['results'] as $result) {
            $action = DB::table('citizen_actions')->where(['id' => $result['id'], 'machine_id' => $machine->id])->first();
            if (! $action || $action->dispatched_at === null || $action->boot_id !== $data['boot_id'] || $action->bridge_id !== $data['bridge_id'] ||
                ($action->result_at !== null && $action->status !== $result['status'])) {
                throw new TelemetryException('invalid_citizen_result', 409);
            }
            if ($action->result_at === null) {
                DB::table('citizen_actions')->where('id', $action->id)->update(['status' => $result['status'], 'result_at' => now()->toISOString()]);
            }
        }
    }

    private function synchronize(Machine $machine, ?stdClass $contact): void
    {
        foreach (DB::table('citizen_actions')->where('machine_id', $machine->id)->whereIn('status', ['requested', 'dispatched'])->get() as $action) {
            $status = $this->actionStatus($action, $contact);
            if ($status !== $action->status) {
                DB::table('citizen_actions')->where('id', $action->id)->update(['status' => $status]);
            }
        }
    }

    private function actionStatus(stdClass $action, ?stdClass $contact): string
    {
        if (in_array($action->status, ['requested', 'dispatched'], true) &&
            (Carbon::parse($action->expires_at)->lte(now()) || ! $contact || $action->bridge_id !== $contact->bridge_id || $action->boot_id !== $contact->boot_id)) {
            return $action->dispatched_at === null ? 'expired' : 'unknown';
        }

        return $action->status;
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(Machine $machine, ?stdClass $contact, bool $primary): array
    {
        $stored = DB::table('citizen_observations')->where('machine_id', $machine->id)->where('observed_at', '>=', now()->subHours(4)->toISOString())->orderBy('citizen_id')->get();
        $rows = [];
        $runtimeCounts = $processCounts = [];
        foreach ($stored as $observation) {
            $row = json_decode($observation->payload, true, flags: JSON_THROW_ON_ERROR);
            $runtimeCounts[$row['runtime_id']] = ($runtimeCounts[$row['runtime_id']] ?? 0) + 1;
            if ($row['process'] !== null) {
                $key = json_encode($row['process'], JSON_THROW_ON_ERROR);
                $processCounts[$key] = ($processCounts[$key] ?? 0) + 1;
            }
        }
        $seenRuntimes = $seenProcesses = [];
        foreach ($stored as $observation) {
            $row = json_decode($observation->payload, true, flags: JSON_THROW_ON_ERROR);
            $row['revision'] = $observation->revision;
            $processKey = $row['process'] !== null ? json_encode($row['process'], JSON_THROW_ON_ERROR) : null;
            if ($runtimeCounts[$row['runtime_id']] > 1 || ($processKey !== null && $processCounts[$processKey] > 1)) {
                $row['attribution'] = 'shared';
            }
            $fresh = $contact && $contact->coverage !== 'unavailable' && $observation->generation === $contact->generation &&
                $this->fresh($contact->sampled_at) && $this->fresh($contact->last_contact_at) && $this->fresh($row['observed_at']);
            if (! $fresh) {
                $row['state'] = in_array($row['state'], ['stopped', 'failed'], true) ? $row['state'] : 'unknown';
                $row['cpu_percent'] = $row['rss_bytes'] = null;
            }
            $row['can_terminate'] = $primary && $fresh && $row['can_terminate'] && $row['attribution'] === 'dedicated' && $row['process'] !== null &&
                $row['process']['boot_id'] === $contact->boot_id && ! in_array($row['state'], ['stopped', 'failed', 'unknown'], true);
            if (isset($seenRuntimes[$row['runtime_id']]) || ($processKey !== null && isset($seenProcesses[$processKey]))) {
                $row['cpu_percent'] = $row['rss_bytes'] = null;
            } elseif ($row['cpu_percent'] !== null || $row['rss_bytes'] !== null) {
                $seenRuntimes[$row['runtime_id']] = true;
                if ($processKey !== null) {
                    $seenProcesses[$processKey] = true;
                }
            }
            $action = DB::table('citizen_actions')->where(['machine_id' => $machine->id, 'citizen_id' => $row['id']])->orderByDesc('rowid')->first();
            if ($action) {
                $row['action'] = ['id' => $action->id, 'status' => $this->actionStatus($action, $contact)];
            }
            $rows[$row['id']] = $row;
        }

        return $rows;
    }

    /** @param  array<string, mixed>  $row */
    private function sameProcess(stdClass $action, array $row): bool
    {
        return $row['process'] !== null && $action->boot_id === $row['process']['boot_id'] && $action->pid === $row['process']['pid'] && $action->start_ticks === $row['process']['start_ticks'];
    }

    private function fresh(string $timestamp): bool
    {
        $time = Carbon::parse($timestamp);

        return $time->lte(now()) && $time->gte(now()->subSeconds(10));
    }
}
