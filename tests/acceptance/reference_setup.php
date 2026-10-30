<?php

// Isolated fixture setup only. Never routed by the application.
use App\Models\HubIdentity;
use App\Models\Machine;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/apps/hub/vendor/autoload.php';
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$app = require dirname(__DIR__, 2).'/apps/hub/bootstrap/app.php';
$app->useEnvironmentPath($input['directory']);
$app->make(Kernel::class)->bootstrap();
if (realpath(DB::connection()->getDatabaseName()) !== realpath($input['directory'].'/hub.sqlite')
    || realpath(DB::selectOne('PRAGMA database_list')->file) !== realpath($input['directory'].'/hub.sqlite')) {
    throw new RuntimeException('Fixture database isolation failed.');
}
if (DB::table('users')->exists() || DB::table('hub_identities')->exists()) {
    throw new RuntimeException('Fixture must be an owner-free synthetic database.');
}
Artisan::call('migrate', ['--force' => true]);
$machines = [];
foreach (Machine::orderBy('name')->get() as $machine) {
    $machines[] = ['id' => $machine->id, 'name' => $machine->name, 'token' => $machine->rotateToken()];
}
if (count($machines) !== 3) {
    throw new RuntimeException('Expected three synthetic hosts.');
}
(new User)->forceFill(['name' => 'Synthetic reference owner', 'email' => $input['email'], 'auth_provider' => $input['provider'], 'auth_subject' => $input['subject'], 'owner_slot' => 1, 'auth_version' => 1])->save();
HubIdentity::create(['id' => 1, 'hub_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'name' => 'Reference', 'role' => 'standby', 'peers' => []]);
file_put_contents($input['directory'].'/machines.json', json_encode($machines, JSON_THROW_ON_ERROR));
chmod($input['directory'].'/machines.json', 0600);
echo json_encode(['isolated' => true, 'machines' => count($machines)], JSON_THROW_ON_ERROR).PHP_EOL;
