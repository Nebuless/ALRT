<?php

namespace App\Actions;

use App\Models\HubIdentity;
use App\Models\Machine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RebootState
{
    public const PENDING = ['queued', 'picked_up', 'authorized', 'attempted'];

    public function primary(): HubIdentity
    {
        $hub = HubIdentity::find(1);
        if (! $hub || $hub->role !== 'primary' || $hub->name !== 'Primary-Hub') {
            throw new ActionException('primary_required', 403);
        }

        return $hub;
    }

    /** SQLite IMMEDIATE transactions serialize eligibility checks across connections. */
    public function transaction(callable $operation): mixed
    {
        return DB::transaction(function () use ($operation) {
            $this->primary();

            return $operation();
        });
    }

    /**
     * @param  array{hub: ?HubIdentity, contact: ?\stdClass, claim: ?\stdClass}|null  $preloaded
     * @return array<string, mixed>
     */
    public function capability(Machine $machine, ?array $preloaded = null): array
    {
        $hub = $preloaded !== null ? $preloaded['hub'] : HubIdentity::find(1);
        $contact = $preloaded !== null ? $preloaded['contact'] : DB::table('telemetry_contacts')->where('machine_id', $machine->id)->first();
        $claim = $preloaded !== null ? $preloaded['claim'] : DB::table('reboot_capabilities')->where('machine_id', $machine->id)->first();
        $reason = match (true) {
            $machine->name === 'Primary-Hub', $machine->name === $hub?->name => 'A hub cannot reboot its own host.',
            $hub?->role !== 'primary', $hub->name !== 'Primary-Hub' => 'Reboot requires the primary Primary-Hub hub.',
            ! ($preloaded !== null ? $machine->reboot_enabled : $machine->fresh()?->reboot_enabled) => 'Reboot execution capability has not been enrolled locally.',
            ! $contact => 'A known boot is required before queuing reboot.',
            ! $claim || ! $claim->available || $claim->boot_id !== $contact->boot_id => 'The protected reboot helper is unavailable or has not been verified for this boot.',
            ! app(AuthorizeReboot::class)->signingReady() => 'Primary reboot signing is unavailable.',
            default => null,
        };

        return ['available' => $reason === null, 'reason' => $reason, 'observed_at' => $claim->observed_at ?? null, 'boot_id' => $contact->boot_id ?? null];
    }

    /** Reconcile only from the committed current-contact projection, never replay samples. */
    public function synchronize(Machine $machine): void
    {
        $boot = DB::table('telemetry_contacts')->where('machine_id', $machine->id)->value('boot_id');
        foreach (DB::table('reboot_actions')->where('machine_id', $machine->id)->whereIn('state', self::PENDING)->get() as $action) {
            $changes = $this->changes($action, $boot);
            if ($changes !== []) {
                DB::table('reboot_actions')->where('id', $action->id)->where('state', $action->state)->update($changes);
            }
        }
    }

    /** @return array<string, mixed> */
    private function changes(\stdClass $action, ?string $boot): array
    {
        if (! in_array($action->state, self::PENDING, true)) {
            return [];
        }
        if ($boot && $boot !== $action->boot_id) {
            return ['state' => in_array($action->state, ['authorized', 'attempted'], true) ? 'new_boot_observed' : 'boot_changed', 'observed_boot_id' => $boot, 'observed_boot_at' => now()->toIso8601String()];
        }
        if (Carbon::parse($action->expires_at)->lte(now())) {
            $authorized = in_array($action->state, ['authorized', 'attempted'], true);
            if (! $authorized || Carbon::parse($action->expires_at)->addSeconds(10)->lte(now())) {
                return ['state' => $authorized ? 'unknown' : 'expired'];
            }
        }

        return [];
    }

    /** Persist deadlines on the scheduler writer path, never an observation read. */
    public function reconcile(): void
    {
        $hub = HubIdentity::find(1);
        if ($hub?->role !== 'primary' || $hub->name !== 'Primary-Hub') {
            return;
        }
        $ids = DB::table('reboot_actions')->whereIn('state', self::PENDING)->distinct()->pluck('machine_id');
        if ($ids->isNotEmpty()) {
            $this->transaction(function () use ($ids): void {
                foreach (Machine::whereIn('id', $ids)->get() as $machine) {
                    $this->synchronize($machine);
                }
            });
        }
    }

    public function action(Machine $machine, string $id): \stdClass
    {
        $action = DB::table('reboot_actions')->where(['id' => $id, 'machine_id' => $machine->id])->first();
        if (! $action) {
            throw new ActionException('action_not_found', 404);
        }

        return $action;
    }

    public function targetBoot(Machine $machine, string $boot): void
    {
        if ($machine->name === 'Primary-Hub' || ! $machine->fresh()?->reboot_enabled || DB::table('telemetry_contacts')->where('machine_id', $machine->id)->value('boot_id') !== $boot) {
            throw new ActionException('target_unavailable');
        }
    }

    /** @return array<string, mixed> */
    public function present(\stdClass $action, ?string $boot = null): array
    {
        $action = (object) array_replace((array) $action, $this->changes($action, $boot));
        $data = (array) $action;
        if ($action->state === 'picked_up') {
            $data['state'] = Carbon::parse($action->cancel_until)->lte(now()) ? 'awaiting_primary' : 'cancel_window';
        }
        $data['cancelable'] = in_array($action->state, ['queued', 'picked_up'], true) && Carbon::parse($action->expires_at)->gt(now());
        $data['too_late_to_cancel'] = in_array($action->state, ['authorized', 'attempted'], true);

        return $data;
    }
}
