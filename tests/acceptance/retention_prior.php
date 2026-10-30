<?php

use App\History\Retention;
use App\Jobs\PruneHistory;
use App\Models\HubIdentity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TelemetryDatabase;
use Tests\TestCase;

uses(TestCase::class, TelemetryDatabase::class);

it('runs the retained compatibility reader evaluator and host component against candidate pruned state', function () {
    $this->prepareTelemetryDatabase();
    $this->travelTo(Carbon::parse('2026-10-20T00:00:00Z'));
    Queue::fake();
    Http::preventStrayRequests();
    HubIdentity::create(['id' => 1, 'hub_uuid' => '33333333-3333-4333-8333-333333333333', 'name' => 'Primary-Hub', 'role' => 'primary', 'peers' => []]);
    [$machine, $token] = $this->telemetryMachine();
    $report = $this->telemetryReport();
    $report['sampled_at'] = now()->toIso8601String();
    $this->freshContact($machine, $token, $report)->assertOk();
    $before = (array) DB::table('telemetry_contacts')->sole();
    $this->travel(5)->hours();
    app(PruneHistory::class)->handle();
    DB::purge('sqlite');
    $redacted = array_replace($before, ['payload' => json_encode(Retention::unavailable(), JSON_THROW_ON_ERROR)]);
    expect((array) DB::table('telemetry_contacts')->sole())->toEqual($redacted);

    $root = dirname(__DIR__, 2);
    $source = function (string $path) use ($root): string {
        $file = $root.'/tests/fixtures/retention-prior/'.substr($path, strlen('apps/hub/'));
        $content = file_get_contents($file);
        if ($content === false) {
            throw new RuntimeException('Compatibility fixture unavailable');
        }

        return $content;
    };
    $directory = sys_get_temp_dir().'/nebuless-retention-prior-'.bin2hex(random_bytes(12));
    mkdir($directory.'/livewire', 0700, true);
    mkdir($directory.'/layouts', 0700);
    mkdir($directory.'/components', 0700);
    $this->beforeApplicationDestroyed(function () use ($directory): void {
        unlink($directory.'/livewire/host-detail.blade.php');
        rmdir($directory.'/livewire');
        unlink($directory.'/layouts/live.blade.php');
        rmdir($directory.'/layouts');
        unlink($directory.'/components/nebula.blade.php');
        rmdir($directory.'/components');
        foreach (glob($directory.'/*.php') as $file) {
            unlink($file);
        }
        rmdir($directory);
    });
    // Only class names change, so the historical implementations can coexist with the candidate writer.
    $names = ['FleetSummary' => 'RetentionPriorFleetSummary', 'ReadTimeline' => 'RetentionPriorReadTimeline',
        'EvaluateFleet' => 'RetentionPriorEvaluateFleet', 'HostDetail' => 'RetentionPriorHostDetail'];
    foreach (['History/ReadTimeline.php', 'ReadModels/FleetSummary.php', 'Incidents/EvaluateFleet.php', 'Livewire/HostDetail.php'] as $path) {
        $file = $directory.'/'.basename($path);
        file_put_contents($file, strtr($source('apps/hub/app/'.$path), $names));
        require $file;
    }
    file_put_contents($directory.'/history.php', $source('apps/hub/config/history.php'));
    config(['history' => require $directory.'/history.php']);
    file_put_contents($directory.'/livewire/host-detail.blade.php', $source('apps/hub/resources/views/livewire/host-detail.blade.php'));
    file_put_contents($directory.'/layouts/live.blade.php', $source('apps/hub/resources/views/layouts/live.blade.php'));
    file_put_contents($directory.'/components/nebula.blade.php', $source('apps/hub/resources/views/components/nebula.blade.php'));
    \Illuminate\Support\Facades\Blade::anonymousComponentPath($directory.'/components');
    $this->withoutVite();
    app('view')->getFinder()->prependLocation($directory);

    app(App\Incidents\RetentionPriorEvaluateFleet::class)->run();
    $read = app(App\ReadModels\RetentionPriorFleetSummary::class);
    $host = $read->host($machine);
    expect($host['metrics'])->toEqual(Retention::unavailable()['metrics'])
        ->and($host['contact_age_seconds'])->toBe(18000)
        ->and($host['provenance']['boot_id'])->toBe($before['boot_id'])
        ->and($read->history($machine)['data'])->toBe([])
        ->and($read->timeline($machine, now()->subHours(6), now(), 'raw')['points'])->toBe([]);
    Livewire::test(App\Livewire\RetentionPriorHostDetail::class, ['machine' => $machine])->assertSuccessful();
    expect((array) DB::table('telemetry_contacts')->sole())->toEqual($redacted);
    Http::assertNothingSent();
});
