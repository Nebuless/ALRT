<?php

namespace App\Actions;

use App\Models\Machine;
use Illuminate\Support\Facades\DB;

class CancelReboot
{
    public function __construct(private readonly RebootState $state) {}

    /** @return array<string, mixed> */
    public function cancel(Machine $machine, string $id): array
    {
        return $this->state->transaction(function () use ($machine, $id): array {
            $this->state->synchronize($machine);
            $action = $this->state->action($machine, $id);
            if (in_array($action->state, ['authorized', 'attempted'], true)) {
                throw new ActionException('too_late_to_cancel');
            }
            if (! in_array($action->state, ['queued', 'picked_up', 'canceled'], true)) {
                throw new ActionException('action_terminal');
            }
            DB::table('reboot_actions')->where('id', $id)->whereIn('state', ['queued', 'picked_up'])->update(['state' => 'canceled']);

            return $this->state->present($this->state->action($machine, $id));
        });
    }
}
