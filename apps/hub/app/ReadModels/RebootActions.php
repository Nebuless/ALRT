<?php

namespace App\ReadModels;

use App\Actions\RebootState;
use App\Models\HubIdentity;
use App\Models\Machine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

class RebootActions
{
    public function __construct(private readonly RebootState $state) {}

    /**
     * @param  Collection<int, Machine>  $machines
     * @param  SupportCollection<string, \stdClass>  $contacts
     * @return array<string, array<string, mixed>>
     */
    public function fleet(Collection $machines, SupportCollection $contacts, ?HubIdentity $hub): array
    {
        $ids = $machines->modelKeys();
        $latest = DB::table('reboot_actions')->whereIn('rowid', DB::table('reboot_actions')->selectRaw('MAX(rowid)')->whereIn('machine_id', $ids)->groupBy('machine_id'))->get()->keyBy('machine_id');
        $claims = DB::table('reboot_capabilities')->whereIn('machine_id', $ids)->get()->keyBy('machine_id');

        return $machines->mapWithKeys(function (Machine $machine) use ($contacts, $hub, $latest, $claims): array {
            $contact = $contacts->get($machine->id);
            $action = $latest->get($machine->id);

            return [$machine->id => [
                'capability' => $this->state->capability($machine, ['hub' => $hub, 'contact' => $contact, 'claim' => $claims->get($machine->id)]),
                'latest' => $action ? $this->state->present($action, $contact->boot_id ?? null) : null,
            ]];
        })->all();
    }

    /** @return array<string, mixed> */
    public function host(Machine $machine): array
    {
        // SQLite insertion order remains stable if wall time steps backwards.
        $latest = DB::table('reboot_actions')->where('machine_id', $machine->id)->orderByDesc('rowid')->first();

        return ['capability' => $this->state->capability($machine), 'latest' => $latest ? $this->state->present($latest, DB::table('telemetry_contacts')->where('machine_id', $machine->id)->value('boot_id')) : null];
    }
}
