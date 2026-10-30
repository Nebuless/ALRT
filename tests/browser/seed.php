<?php

// Disposable browser data only. Use the same ingest and action services as the
// collector; no fixture routes or production configuration are installed.
require getcwd().'/apps/hub/vendor/autoload.php';
$app = require getcwd().'/apps/hub/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$directory = realpath(getenv('BROWSER_SCRATCH'));
$database = getenv('DB_DATABASE');
if (! $directory || dirname($directory) !== '/tmp' || ! str_starts_with(basename($directory), 'nebuless-browser-') || $database !== $directory.'/hub.sqlite' || Illuminate\Support\Facades\DB::selectOne('PRAGMA database_list')->file !== $database) {
    throw new RuntimeException('Disposable browser database required.');
}
$template = json_decode(file_get_contents(getcwd().'/contracts/fixtures/telemetry/valid-sample.json'), true, flags: JSON_THROW_ON_ERROR);
foreach (['Alpha-Hub', 'Beta-Restricted', 'Primary-Hub'] as $index => $name) {
    $id = ['22222222-2222-4222-8222-222222222222', '33333333-3333-4333-8333-333333333333', '44444444-4444-4444-8444-444444444444'][$index];
    $machine = App\Models\Machine::create(['id' => $id, 'name' => $name, 'data_policy' => App\Models\Machine::HEALTH_PROCESSES]);
    $machine->rotateToken();
    // Alpha-Hub has old history and a silent contact. Primary has no evidence, leaving
    // truthful unknown graph paths alongside Beta-Restricted's received evidence.
    if ($index === 2) {
        continue;
    }
    try {
        if ($index === 0) {
            Illuminate\Support\Carbon::setTestNow(now()->subMinutes(20));
        }
        $report = $template;
        $report['machine_id'] = $id;
        $report['stream_id'] = 'browser-seed';
        $report['sampled_at'] = now()->toIso8601String();
        $report['sequence'] = 1;
        $contact = app(App\Telemetry\RecordContact::class);
        $contact->record($machine, $contact->challenge($machine)['nonce'], $report);
    } finally {
        Illuminate\Support\Carbon::setTestNow();
    }
}
app(App\Incidents\EvaluateFleet::class)->run();
// Enroll only the synthetic machine. No collector/helper or OS permission is
// installed, so queued actions have no possible path to an actual reboot.
Illuminate\Support\Facades\Artisan::call('fleet:enroll-reboot', ['machine' => '22222222-2222-4222-8222-222222222222']);
$machine = App\Models\Machine::findOrFail('22222222-2222-4222-8222-222222222222');
app(App\Actions\RebootExchange::class)->pickup($machine, $template['boot_id'], true, null);
if (! app(App\Actions\RebootState::class)->capability($machine)['available']) {
    throw new RuntimeException('Synthetic reboot capability was not enrolled.');
}
