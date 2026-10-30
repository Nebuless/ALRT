<?php

namespace Tests\Support;

use App\Models\Machine;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

trait TelemetryDatabase
{
    use BetterAuthService;

    private string $telemetryDirectory;

    public function createApplication()
    {
        $this->telemetryDirectory = sys_get_temp_dir().'/nebuless-telemetry-'.bin2hex(random_bytes(12));
        mkdir($this->telemetryDirectory, 0700, true);
        file_put_contents($this->telemetryDirectory.'/.env', '');
        touch($this->telemetryDirectory.'/hub.sqlite');
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->useEnvironmentPath($this->telemetryDirectory);
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('database.connections.sqlite.database', $this->telemetryDirectory.'/hub.sqlite');
        $app['db']->purge('sqlite');

        return $app;
    }

    public function prepareTelemetryDatabase(): void
    {
        $this->freezeTime();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        expect(DB::selectOne('PRAGMA journal_mode')->journal_mode)->toBe('wal');
        expect(DB::connection()->getDatabaseName())->toBe($this->telemetryDirectory.'/hub.sqlite');
        expect(DB::selectOne('PRAGMA database_list')->file)->toBe($this->telemetryDirectory.'/hub.sqlite');
        $directory = $this->telemetryDirectory;
        $this->beforeApplicationDestroyed(function () use ($directory): void {
            DB::disconnect();
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            unlink($directory.'/.env');
            rmdir($directory);
        });
    }

    public function telemetryMachine(string $name = 'Alpha-Hub'): array
    {
        $machine = Machine::create(['id' => '22222222-2222-4222-8222-222222222222', 'name' => $name, 'data_policy' => Machine::HEALTH_PROCESSES]);

        return [$machine, $machine->rotateToken()];
    }

    public function telemetryReport(int $sequence = 1, string $stream = 'collector-one', ?string $boot = null): array
    {
        $report = json_decode(file_get_contents(dirname(__DIR__, 4).'/contracts/fixtures/telemetry/valid-sample.json'), true, flags: JSON_THROW_ON_ERROR);
        $report['sequence'] = $sequence;
        $report['stream_id'] = $stream;
        $report['sampled_at'] = now()->subSeconds(120)->toIso8601String();
        if ($boot !== null) {
            $report['boot_id'] = $boot;
        }

        return $report;
    }

    public function collectorPath(Machine $machine, string $suffix): string
    {
        return '/api/v1/collector/'.$machine->id.'/'.$suffix;
    }

    public function freshContact(Machine $machine, string $token, array $report)
    {
        $nonce = $this->withToken($token)->postJson($this->collectorPath($machine, 'challenge'))->assertCreated()->json('nonce');

        return $this->withToken($token)->postJson($this->collectorPath($machine, 'contact'), ['nonce' => $nonce, 'report' => $report]);
    }

    public function telemetryOwner(): User
    {
        [$owner] = $this->signInBetterOwner($this->provisionBetterOwner('telemetry-owner@example.test'));

        return $owner;
    }
}
