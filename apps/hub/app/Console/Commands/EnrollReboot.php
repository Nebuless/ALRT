<?php

namespace App\Console\Commands;

use App\Actions\RebootState;
use App\Models\Machine;
use Illuminate\Console\Command;

class EnrollReboot extends Command
{
    protected $signature = 'fleet:enroll-reboot {machine} {--revoke}';

    protected $description = 'Record local reboot enrollment on Primary; actual protected helper capability remains mandatory';

    public function handle(): int
    {
        try {
            app(RebootState::class)->primary();
            $id = $this->argument('machine');
            if (! is_string($id)) {
                throw new \RuntimeException;
            }
            $machine = Machine::find($id);
            if (! $machine || $machine->name === 'Primary-Hub') {
                throw new \RuntimeException;
            }
            $machine->forceFill(['reboot_enabled' => ! $this->option('revoke')])->save();
            $this->info('Local enrollment recorded. The authenticated protected-helper probe still controls availability.');

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Use a non-Primary fleet machine on the primary hub.');

            return self::FAILURE;
        }
    }
}
