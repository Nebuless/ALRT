<?php

// Long storage acceptance: invoke with bin/php; never loaded by the ordinary unit-test gate.
use App\History\ReadTimeline;
use App\Jobs\PruneHistory;
use App\Models\Machine;
use App\ReadModels\FleetSummary;
use App\Telemetry\IngestReport;
use App\Telemetry\RecordContact;
use App\Telemetry\ValidateReport;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/apps/hub/vendor/autoload.php';
$options = getopt('', ['days:', 'backlog:', 'directory:', 'reuse-directory:', 'worker:', 'anchor:', 'output:', 'run-id:']);
$completed = false;
register_shutdown_function(static function () use (&$completed): void {
    if (! $completed) {
        fwrite(STDERR, 'History workload did not complete.'.PHP_EOL);
        exit(1);
    }
});
$days = (int) ($options['days'] ?? 7);
$backlog = (int) ($options['backlog'] ?? 360);
if ($days < 1 || $days > 7 || $backlog < 1 || $backlog > 8640) {
    throw new RuntimeException('Use days 1..7 and backlog 1..8640.');
}
$reuse = isset($options['reuse-directory']);
$directory = $options['reuse-directory'] ?? $options['directory'] ?? sys_get_temp_dir().'/nebuless-history-load-'.bin2hex(random_bytes(8));
if (realpath(dirname($directory)) !== realpath(sys_get_temp_dir()) || ! str_starts_with(basename($directory), 'nebuless-history-load-')) {
    throw new RuntimeException('Only a dedicated workload directory directly under the system temporary directory is allowed.');
}
$worker = $options['worker'] ?? null;
$runId = $options['run-id'] ?? bin2hex(random_bytes(4));
if (! preg_match('/^[a-f0-9]{8}$/D', $runId)) {
    throw new RuntimeException('Invalid workload run ID.');
}
$fixture = $reuse ? json_decode(file_get_contents($directory.'/fixture.json'), true, flags: JSON_THROW_ON_ERROR) : null;
if ($reuse && (($fixture['version'] ?? null) !== 1 || ($fixture['days'] ?? null) !== $days || ($fixture['seed_records'] ?? null) !== $days * 8640 * 3)) {
    throw new RuntimeException('Reusable fixture manifest is invalid.');
}
$anchor = Carbon::parse($options['anchor'] ?? $fixture['anchor'] ?? 'now')->utc()->startOfSecond();
if ($worker === null && ! $reuse) {
    mkdir($directory, 0700, true);
    file_put_contents($directory.'/.env', '');
    touch($directory.'/hub.sqlite');
}
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE='.$directory.'/hub.sqlite');
$app = require dirname(__DIR__, 2).'/apps/hub/bootstrap/app.php';
$app->useEnvironmentPath($directory);
$app->make(Kernel::class)->bootstrap();
config(['database.connections.sqlite.database' => $directory.'/hub.sqlite', 'logging.default' => 'stderr']);
DB::purge('sqlite');
if (DB::connection()->getDatabaseName() !== $directory.'/hub.sqlite') {
    throw new RuntimeException('Load database isolation failed.');
}
Carbon::setTestNow($anchor);
$template = json_decode(file_get_contents(dirname(__DIR__, 2).'/contracts/fixtures/telemetry/valid-sample.json'), true, flags: JSON_THROW_ON_ERROR);
$machineIds = ['22222222-2222-4222-8222-222222222222', '33333333-3333-4333-8333-333333333333', '44444444-4444-4444-8444-444444444444'];
$makeReport = static function (int $host, int $sequence, Carbon $at, string $stream = 'load-history') use ($template, $machineIds): array {
    $report = $template;
    $report['machine_id'] = $machineIds[$host];
    $report['stream_id'] = $stream;
    $report['sequence'] = $sequence;
    $report['sampled_at'] = $at->toIso8601String();
    $report['metrics']['cpu_percent']['value'] = $sequence % 79 === 0 ? 100 : ($sequence % 37);
    $items = [];
    for ($i = 0; $i < 10; $i++) {
        $process = $template['processes']['top_cpu']['items'][0];
        $process['identity']['pid'] = 200 + $i;
        $process['identity']['start_ticks'] = 1000 + $i;
        $process['name'] = 'worker-'.$i;
        $items[] = $process;
    }
    foreach (['top_cpu', 'top_rss', 'longest_running'] as $category) {
        $report['processes'][$category]['items'] = array_slice($items, 0, $sequence % 3 === 0 ? 3 : 10);
        $report['processes'][$category]['total_observed'] = 600;
        $report['processes'][$category]['completeness'] = 'partial';
        $report['processes'][$category]['reason'] = 'category_limit';
    }

    return $report;
};
$percentile = static function (array $values): float {
    sort($values);

    return $values[(int) ceil(count($values) * 0.95) - 1];
};
$retry = static function (callable $operation): array {
    $started = hrtime(true);
    $retries = 0;
    while (true) {
        try {
            $operation();

            return [(hrtime(true) - $started) / 1e6, $retries];
        } catch (PDOException $error) {
            if (! str_contains($error->getMessage(), 'database is locked') || (hrtime(true) - $started) > 10_000_000_000) {
                throw $error;
            }
            $retries++;
            usleep(1000 * min(50, $retries));
        }
    }
};
$retryIngest = static function (Machine $machine, array $reports) use ($retry): array {
    return $retry(static function () use ($machine, $reports): void {
        $acks = app(IngestReport::class)->batch($machine, $reports);
        if (array_column($acks, 'sequence') !== array_column($reports, 'sequence')) {
            throw new RuntimeException('ACK identities mismatch.');
        }
    });
};
$current = static function (Machine $machine, array $report) use ($retry): array {
    return $retry(static function () use ($machine, $report): void {
        $service = app(RecordContact::class);
        $challenge = $service->challenge($machine);
        $ack = $service->record($machine, $challenge['nonce'], $report);
        if ($ack['accepted'][0]['sequence'] !== $report['sequence']) {
            throw new RuntimeException('Current ACK identity mismatch.');
        }
    });
};
if ($worker !== null) {
    file_put_contents($directory.'/ready-'.$runId.'-'.$worker, 'ready');
    $deadline = microtime(true) + 30;
    while (! is_file($directory.'/start-gate-'.$runId)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Load barrier timed out.');
        }
        usleep(10000);
    }
    $latencies = [];
    $batchBytes = [];
    $retries = 0;
    if ($worker === 'prune') {
        for ($i = 0; $i < 177; $i++) {
            [$elapsed, $attempts] = $retry(static fn () => app(PruneHistory::class)->handle());
            $latencies[] = $elapsed;
            $retries += $attempts;
            if (! DB::table('telemetry_accepted')->where('sampled_at', '<', $anchor->copy()->subHours(4)->toIso8601String())->exists()
                && ! DB::table('history_buckets')->exists()) {
                break;
            }
        }
    } else {
        $host = (int) $worker;
        $machine = Machine::findOrFail($machineIds[$host]);
        for ($offset = 0; $offset < $backlog;) {
            $reports = [];
            while ($offset < $backlog && count($reports) < 128) {
                $report = $makeReport($host, $offset + 1, $anchor->copy()->subHours(3)->addSeconds($offset * 10), 'load-backlog-'.$runId);
                $candidate = [...$reports, $report];
                if (strlen(json_encode(['reports' => $candidate], JSON_THROW_ON_ERROR)) > 262144) {
                    if ($reports === []) {
                        throw new RuntimeException('A workload report exceeds the real batch envelope.');
                    }
                    break;
                }
                $reports = $candidate;
                $offset++;
            }
            $batchBytes[] = strlen(json_encode(['reports' => $reports], JSON_THROW_ON_ERROR));
            [$elapsed, $attempts] = $retryIngest($machine, $reports);
            $latencies[] = $elapsed;
            $retries += $attempts;
        }
    }
    file_put_contents($directory.'/worker-'.$runId.'-'.$worker.'.json', json_encode(['latencies_ms' => $latencies, 'batch_bytes' => $batchBytes, 'retries' => $retries, 'peak_rss_kib' => getrusage()['ru_maxrss']], JSON_THROW_ON_ERROR));
    $completed = true;
    exit(0);
}
if (! $reuse) {
    Artisan::call('migrate', ['--force' => true]);
    foreach ($machineIds as $i => $id) {
        Machine::create(['id' => $id, 'name' => ['Alpha-Hub', 'Beta-Restricted', 'Primary-Hub'][$i], 'data_policy' => Machine::HEALTH_PROCESSES]);
    }
}
if ($reuse && (DB::table('users')->exists() || DB::table('hub_identities')->exists()
    || ! DB::getSchemaBuilder()->hasColumn('telemetry_accepted', 'sampled_at'))) {
    throw new RuntimeException('Reusable database must be an owner-free workload fixture with the current migration schema.');
}
$seedStart = hrtime(true);
$samples = $days * 8640;
$payloads = [];
foreach ($machineIds as $host => $id) {
    $machine = Machine::findOrFail($id);
    if ($reuse) {
        $seed = DB::table('telemetry_samples')->where(['machine_id' => $id, 'stream_id' => 'load-history'])->selectRaw('COUNT(*) AS records, MIN(sequence) AS first_sequence, MAX(sequence) AS last_sequence, MIN(sampled_at) AS first_at, MAX(sampled_at) AS last_at')->first();
        if ($seed->records !== $samples || $seed->first_sequence !== 1 || $seed->last_sequence !== $samples
            || $seed->first_at !== $anchor->copy()->subDays($days)->addSeconds(10)->toIso8601String() || $seed->last_at !== $anchor->toIso8601String()) {
            throw new RuntimeException('Reusable seed fixture does not match its seven-day identity/timestamp contract.');
        }

        continue;
    }
    for ($offset = 0; $offset < $samples; $offset += 64) {
        $reports = [];
        for ($i = $offset; $i < min($offset + 64, $samples); $i++) {
            $reports[] = $makeReport($host, $i + 1, $anchor->copy()->subDays($days)->addSeconds(($i + 1) * 10));
        }
        if ($offset === 0) {
            foreach (array_slice($reports, 0, 3) as $report) {
                app(ValidateReport::class)->validate($machine, json_decode(json_encode($report, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR));
                $payloads[] = strlen(app(IngestReport::class)->canonical($report));
            }
        }
        // Historical frozen receipt recreates a pre-reduction fixture without weakening admission.
        Carbon::setTestNow(Carbon::parse($reports[array_key_last($reports)]['sampled_at']));
        app(IngestReport::class)->batch($machine, $reports);
        Carbon::setTestNow($anchor);
    }
    fwrite(STDERR, 'Seeded host '.($host + 1).' / 3'.PHP_EOL);
}
$seedSeconds = (hrtime(true) - $seedStart) / 1e9;
if ($payloads === []) {
    foreach (range(1, 3) as $sequence) {
        $payloads[] = strlen(app(IngestReport::class)->canonical($makeReport(0, $sequence, $anchor)));
    }
}
if (! $reuse) {
    file_put_contents($directory.'/fixture.json', json_encode(['version' => 1, 'anchor' => $anchor->toIso8601String(), 'days' => $days, 'seed_records' => $samples * 3], JSON_THROW_ON_ERROR));
}
$baselineMembership = DB::table('telemetry_accepted')->count() + (int) DB::table('history_accepted_intervals')->selectRaw('COALESCE(SUM(last_sequence - first_sequence + 1), 0) AS total')->value('total');
$baselineCompacted = (int) DB::table('history_accepted_intervals')->selectRaw('COALESCE(SUM(last_sequence - first_sequence + 1), 0) AS total')->value('total');
if (DB::table('telemetry_samples')->where('stream_id', '!=', 'load-history')->where('sampled_at', '<', $anchor->copy()->subHours(4)->toIso8601String())->exists()) {
    throw new RuntimeException('Clean any prior-run expired fixture using the actual pruning job before reuse.');
}
$legacyExpired = DB::table('telemetry_samples')->where('sampled_at', '<', $anchor->copy()->subHours(4)->toIso8601String())->count();
$expiredSeed = 512 * 3;
foreach ($machineIds as $host => $id) {
    $machine = Machine::findOrFail($id);
    $current($machine, $makeReport($host, 1, $anchor->copy()->subSeconds(10), 'load-current-'.$runId));
    Carbon::setTestNow($anchor->copy()->subDays(2));
    for ($offset = 0; $offset < 512; $offset += 64) {
        $reports = [];
        for ($i = $offset; $i < $offset + 64; $i++) {
            $reports[] = $makeReport($host, $i + 1, $anchor->copy()->subDays(8)->addSeconds($i * 10), 'load-prunable-'.$runId);
        }
        Carbon::setTestNow(Carbon::parse($reports[array_key_last($reports)]['sampled_at']));
        $retryIngest($machine, $reports);
    }
    Carbon::setTestNow($anchor);
}
$expiredBefore = DB::table('telemetry_samples')->where('sampled_at', '<', $anchor->copy()->subHours(4)->toIso8601String())->count();
if ($expiredBefore !== $legacyExpired + $expiredSeed || DB::table('telemetry_contacts')->count() !== 3) {
    throw new RuntimeException('Current projection or maintenance fixture is incomplete.');
}
$processes = [];
foreach ([0, 1, 2, 'prune'] as $host) {
    $command = [PHP_BINARY, __FILE__, '--directory='.$directory, '--worker='.$host, '--days='.$days, '--backlog='.$backlog, '--anchor='.$anchor->toIso8601String(), '--run-id='.$runId];
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory.'/worker-'.$host.'.out', 'w'], 2 => ['file', $directory.'/worker-'.$host.'.err', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start load worker.');
    }
    $processes[$host] = $process;
}
$deadline = microtime(true) + 30;
while (count(glob($directory.'/ready-'.$runId.'-*')) !== 4) {
    if (microtime(true) > $deadline) {
        foreach ($processes as $process) {
            proc_terminate($process);
        }
        throw new RuntimeException('Not all backlog and pruning workers became ready.');
    }
    usleep(10000);
}
file_put_contents($directory.'/start-gate-'.$runId, 'start');
$live = $reads = $hosts = [];
$liveRetries = 0;
$machine = Machine::findOrFail($machineIds[0]);
for ($i = 0; $i < 36; $i++) {
    [$elapsed, $retries] = $current($machine, $makeReport(0, $i + 2, $anchor->copy()->subSeconds(10), 'load-current-'.$runId));
    $live[] = $elapsed;
    $liveRetries += $retries;
    $started = hrtime(true);
    app(ReadTimeline::class)->timeline($machine, $anchor->copy()->subHours(4), $anchor);
    $reads[] = (hrtime(true) - $started) / 1e6;
    $started = hrtime(true);
    app(FleetSummary::class)->host($machine);
    $hosts[] = (hrtime(true) - $started) / 1e6;
}
$workers = [];
foreach ($processes as $host => $process) {
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Backlog worker failed; inspect '.$directory.'/worker-'.$host.'.err');
    }
    $workers[] = json_decode(file_get_contents($directory.'/worker-'.$runId.'-'.$host.'.json'), true, flags: JSON_THROW_ON_ERROR);
}
$expected = $baselineMembership + $backlog * 3 + 36 + 3 + $expiredSeed;
$compacted = (int) DB::table('history_accepted_intervals')->selectRaw('COALESCE(SUM(last_sequence - first_sequence + 1), 0) AS total')->value('total');
$actual = DB::table('telemetry_accepted')->count() + $compacted;
$expiredAfter = DB::table('telemetry_samples')->where('sampled_at', '<', $anchor->copy()->subHours(4)->toIso8601String())->count();
$result = ['reference' => 'direct_service_storage_see_supervisor_resource_evidence',
    'measurement_boundary' => 'direct_storage_services_excludes_http_parsing_validation_and_network',
    'directory' => $directory, 'run_id' => $runId, 'reused_seed' => $reuse, 'baseline_membership' => $baselineMembership,
    'days' => $days, 'seed_records' => $samples * 3, 'seed_seconds' => $seedSeconds,
    'backlog_records_each' => $backlog, 'accepted_expected' => $expected, 'accepted_actual' => $actual, 'payload_bytes' => ['min' => min($payloads), 'max' => max($payloads)],
    'live_ingest_p95_ms' => $percentile($live), 'history_read_p95_ms' => $percentile($reads), 'host_read_p95_ms' => $percentile($hosts),
    'pruning' => ['expired_raw_before' => $expiredBefore, 'expired_raw_after' => $expiredAfter, 'compacted_identities_added' => $compacted - $baselineCompacted],
    'backlog_batch_bytes' => array_map(fn ($item) => $item['batch_bytes'] === [] ? null : ['min' => min($item['batch_bytes']), 'max' => max($item['batch_bytes'])], $workers),
    'live_lock_retries' => $liveRetries, 'backlog_and_prune_batch_p95_ms' => array_map(fn ($item) => $percentile($item['latencies_ms']), $workers),
    'backlog_lock_retries' => array_column($workers, 'retries'), 'peak_rss_kib_parent' => getrusage()['ru_maxrss'], 'peak_rss_kib_workers' => array_column($workers, 'peak_rss_kib'),
    'database_bytes' => filesize($directory.'/hub.sqlite'), 'wal_bytes' => is_file($directory.'/hub.sqlite-wal') ? filesize($directory.'/hub.sqlite-wal') : 0,
    'budgets_pass' => $actual === $expected && $expiredAfter === 0 && $compacted - $baselineCompacted === $legacyExpired + $expiredSeed && max($percentile($live), $percentile($reads), $percentile($hosts)) < 1000];
$encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
if (isset($options['output'])) {
    file_put_contents($options['output'], $encoded);
}
echo $encoded;
$completed = true;
exit($result['budgets_pass'] ? 0 : 1);
