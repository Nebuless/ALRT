<?php

namespace App\Actions;

use App\Auth\ActionConfirmation;
use App\Models\Machine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QueueReboot
{
    public function __construct(private readonly RebootState $state, private readonly ActionConfirmation $confirmation) {}

    /** @return array<string, mixed> */
    public function queue(Request $request, Machine $machine, string $proof): array
    {
        return $this->state->transaction(function () use ($request, $machine, $proof): array {
            $capability = $this->state->capability($machine);
            if (! $capability['available']) {
                throw new ActionException('reboot_unavailable');
            }
            $this->confirmation->consume($request, $machine->id, $proof);
            $this->state->synchronize($machine);
            $existing = DB::table('reboot_actions')->where('machine_id', $machine->id)->whereIn('state', RebootState::PENDING)->first();
            if ($existing) {
                return $this->state->present($existing);
            }
            $id = (string) Str::uuid();
            DB::table('reboot_actions')->insert(['id' => $id, 'machine_id' => $machine->id, 'boot_id' => $capability['boot_id'], 'state' => 'queued', 'queued_at' => now()->toIso8601String(), 'expires_at' => now()->addHours(6)->toIso8601String()]);

            return $this->state->present($this->state->action($machine, $id));
        });
    }
}
