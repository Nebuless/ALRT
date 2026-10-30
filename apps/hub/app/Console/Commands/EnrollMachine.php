<?php

namespace App\Console\Commands;

use App\Models\HubIdentity;
use App\Models\Machine;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class EnrollMachine extends Command
{
    protected $signature = 'fleet:enroll-machine {name} {--id= : Stable machine UUID shared across hub enrollments} {--policy= : health_processes or titles} {--rotate : Replace the existing credential} {--revoke : Revoke the credential without issuing another}';

    protected $description = 'Enroll one collector on this hub and print its token exactly once';

    public function handle(): int
    {
        $name = $this->argument('name');
        if (! is_string($name)) {
            $this->error('Use a fleet name and its stable machine UUID.');

            return self::FAILURE;
        }
        $id = $this->option('id');
        $machine = Machine::where('name', $name)->first();
        if (! in_array($name, ['Alpha-Hub', 'Beta-Restricted', 'Primary-Hub'], true) || ($id !== null && (! is_string($id) || ! Str::isUuid($id))) ||
            ($machine && $id !== null && $machine->id !== $id) || (! $machine && $id === null)) {
            $this->error('Use a fleet name and its stable machine UUID.');

            return self::FAILURE;
        }
        if ($this->option('revoke')) {
            if (! $machine || $this->option('rotate')) {
                $this->error('Revocation requires an existing machine and cannot also rotate.');

                return self::FAILURE;
            }
            $machine->revokeToken();
            $this->info('Machine credential revoked on this hub.');

            return self::SUCCESS;
        }
        if ($machine && ! $this->option('rotate')) {
            $this->error('Machine already enrolled. Use --rotate to explicitly replace its credential.');

            return self::FAILURE;
        }
        $policy = $this->option('policy') ?: $machine?->data_policy ?: ($name === 'Beta-Restricted' ? Machine::HEALTH_PROCESSES : Machine::TITLES);
        if (! in_array($policy, [Machine::HEALTH_PROCESSES, Machine::TITLES], true) || ($name === 'Beta-Restricted' && $policy !== Machine::HEALTH_PROCESSES)) {
            $this->error('Invalid machine data policy.');

            return self::FAILURE;
        }
        $machine ??= new Machine(['id' => $id, 'name' => $name]);
        $machine->data_policy = $policy;
        $token = $machine->rotateToken();
        $this->line(json_encode([
            'hub_id' => HubIdentity::find(1)?->hub_uuid,
            'machine_id' => $machine->id, 'name' => $machine->name, 'data_policy' => $machine->data_policy,
            'token' => $token,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
