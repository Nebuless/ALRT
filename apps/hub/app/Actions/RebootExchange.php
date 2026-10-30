<?php

namespace App\Actions;

use App\Models\Machine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RebootExchange
{
    public function __construct(private readonly RebootState $state) {}

    /** @return array<string, mixed>|null */
    public function pickup(Machine $machine, string $boot, bool $available, ?string $reason): ?array
    {
        return $this->state->transaction(function () use ($machine, $boot, $available, $reason): ?array {
            $this->state->targetBoot($machine, $boot);
            DB::table('reboot_capabilities')->updateOrInsert(['machine_id' => $machine->id], ['boot_id' => $boot, 'available' => $available, 'reason' => $reason, 'observed_at' => now()->toIso8601String()]);
            $this->state->synchronize($machine);
            if (! $this->state->capability($machine)['available']) {
                return null;
            }
            $action = DB::table('reboot_actions')->where('machine_id', $machine->id)->whereIn('state', ['queued', 'picked_up', 'authorized'])->first();

            return $action ? $this->state->present($action) : null;
        });
    }

    /** @return array<string, mixed> */
    public function acknowledge(Machine $machine, string $id, string $boot): array
    {
        return $this->state->transaction(function () use ($machine, $id, $boot): array {
            $this->state->targetBoot($machine, $boot);
            $this->state->synchronize($machine);
            $action = $this->state->action($machine, $id);
            if ($action->boot_id !== $boot || ! in_array($action->state, ['queued', 'picked_up', 'authorized'], true) || Carbon::parse($action->expires_at)->lte(now())) {
                throw new ActionException('pickup_refused');
            }
            DB::table('reboot_actions')->where('id', $id)->where('state', 'queued')->update(['state' => 'picked_up', 'picked_up_at' => now()->toIso8601String(), 'cancel_until' => now()->addSeconds(60)->toIso8601String()]);

            return $this->state->present($this->state->action($machine, $id));
        });
    }

    public function result(Machine $machine, string $id, string $boot, string $status): void
    {
        $this->state->transaction(function () use ($machine, $id, $boot, $status): void {
            $action = $this->state->action($machine, $id);
            if ($action->boot_id !== $boot || ! in_array($action->state, ['authorized', 'attempted'], true)) {
                throw new ActionException('result_refused');
            }
            DB::table('reboot_actions')->where('id', $id)->update(['state' => $status === 'attempted' ? 'attempted' : $action->state, 'result' => $status, 'result_at' => now()->toIso8601String()]);
        });
    }
}
