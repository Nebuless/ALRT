<?php

// Isolated subprocess entry point, never routed or installed.
use App\Actions\ActionException;
use App\Actions\AuthorizeReboot;
use App\Actions\CancelReboot;
use App\Actions\QueueReboot;
use App\Auth\BetterAuth;
use App\Models\Machine;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$input = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
$app = require __DIR__.'/../../bootstrap/app.php';
$app->useEnvironmentPath($input['environment']);
$app->make(Kernel::class)->bootstrap();
config(['database.connections.sqlite.database' => $input['database'], 'database.connections.sqlite.busy_timeout' => 5000, 'actions.private_key' => $input['private_key'], 'actions.key_id' => 'primary-test', 'better-auth' => $input['auth_config']]);
DB::purge('sqlite');
if (DB::selectOne('PRAGMA database_list')->file !== $input['database']) {
    exit(4);
}
Carbon::setTestNow($input['now']);
$machine = Machine::findOrFail($input['machine']);
$request = Request::create('/test-race', 'POST');
$session = new Store('reboot-test', new ArraySessionHandler(60));
$session->setId($input['session_id']);
$session->replace($input['session']);
$request->setLaravelSession($session);
$provider = app(BetterAuth::class)->current($request);
$owner = User::findOrFail($input['owner']);
$owner->provider_enrolled = $provider['enrolled'] ?? false;
$request->setUserResolver(fn () => $owner);
$request->attributes->set('owner.provider_session', $provider);
echo "ready\n";
flush();
if (fgets(STDIN) !== "go\n") {
    throw new RuntimeException('Missing race start signal');
}
try {
    $result = match ($input['operation']) {
        'queue' => app(QueueReboot::class)->queue($request, $machine, $input['proof']),
        'cancel' => app(CancelReboot::class)->cancel($machine, $input['action']),
        'authorize' => app(AuthorizeReboot::class)->permit($machine, $input['action'], $input['boot'], str_repeat('a', 64)),
    };
    echo json_encode(['won' => true, 'id' => $result['id'] ?? $input['action']], JSON_THROW_ON_ERROR);
} catch (ActionException $error) {
    echo json_encode(['won' => false, 'error' => $error->errorCode], JSON_THROW_ON_ERROR);
}
