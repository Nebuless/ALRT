<?php

namespace App\Livewire;

use App\Actions\ActionException;
use App\Actions\CancelReboot;
use App\Actions\QueueReboot;
use App\Actions\RebootState;
use App\Auth\ActionConfirmation;
use App\History\Retention;
use App\Incidents\IncidentReadModel;
use App\Models\Machine;
use App\ReadModels\FleetSummary;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class HostDetail extends Component
{
    #[Locked]
    public Machine $machine;

    #[Url]
    public ?int $sample = null;

    public function queueReboot(string $password = '', string $code = ''): void
    {
        $this->resetErrorBag();
        try {
            app(RebootState::class)->primary();
            $capability = app(RebootState::class)->capability($this->machine);
            if (! $capability['available']) {
                $this->addError('reboot', $capability['reason']);

                return;
            }
            $limit = 'live-reboot-confirmation:'.request()->user()?->id;
            if (RateLimiter::tooManyAttempts($limit, 5)) {
                $this->addError('confirmation', 'Too many confirmation attempts. Try again in a minute.');

                return;
            }
            RateLimiter::hit($limit, 60);
            $proof = app(ActionConfirmation::class)->issue(request(), $this->machine->id, $password, $code);
            app(QueueReboot::class)->queue(request(), $this->machine, $proof);
            $this->dispatch('reboot-queued');
        } catch (ActionException $error) {
            $this->addError('reboot', str_replace('_', ' ', $error->errorCode));
        }
    }

    public function cancelReboot(string $id): void
    {
        $this->resetErrorBag();
        try {
            app(CancelReboot::class)->cancel($this->machine, $id);
        } catch (ActionException $error) {
            $this->addError('reboot', str_replace('_', ' ', $error->errorCode));
        }
    }

    public function render(): View
    {
        $read = app(FleetSummary::class);
        $fleet = $read->fleet();
        $host = $read->host($this->machine, new Retention(Carbon::parse($fleet['observed_at'])));
        $to = Carbon::parse($fleet['observed_at']);
        $retention = new Retention($to);

        return view('livewire.host-detail', [
            'host' => $host,
            'fleet' => $fleet,
            'timeline' => $read->timeline($this->machine, $to->copy()->subHours(4), $to, 'raw', $retention),
            'incidents' => app(IncidentReadModel::class)->today($this->machine->id),
            'snapshot' => $this->sample === null ? null : $read->snapshot($this->machine, $this->sample),
        ])->layout('layouts.observatory', ['title' => $host['name']]);
    }
}
