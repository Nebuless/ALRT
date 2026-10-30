<?php

namespace Tests\Support;

use Livewire\Component;

class FleetAccessProbe extends Component
{
    public function inspectFleet(): void
    {
        session()->put('identity.probe_ran', true);
    }

    public function render(): string
    {
        return '<div>Fleet access probe</div>';
    }
}
