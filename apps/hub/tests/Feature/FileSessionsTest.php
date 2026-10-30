<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\TelemetryDatabase;

uses(TelemetryDatabase::class);

function nativeSessionDefault(): string
{
    $previous = [getenv('SESSION_DRIVER'), $_SERVER['SESSION_DRIVER'] ?? null, $_ENV['SESSION_DRIVER'] ?? null];
    putenv('SESSION_DRIVER');
    unset($_SERVER['SESSION_DRIVER'], $_ENV['SESSION_DRIVER']);
    try {
        return (require config_path('session.php'))['driver'];
    } finally {
        $previous[0] === false ? putenv('SESSION_DRIVER') : putenv('SESSION_DRIVER='.$previous[0]);
        foreach (['_SERVER' => 1, '_ENV' => 2] as $global => $index) {
            if ($previous[$index] === null) {
                unset($GLOBALS[$global]['SESSION_DRIVER']);
            } else {
                $GLOBALS[$global]['SESSION_DRIVER'] = $previous[$index];
            }
        }
    }
}

beforeEach(function () {
    $this->prepareTelemetryDatabase();
    $directory = sys_get_temp_dir().'/nebuless-file-session-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    config(['session.driver' => nativeSessionDefault(), 'session.files' => $directory]);
    app('session')->forgetDrivers();
    $this->beforeApplicationDestroyed(function () use ($directory): void {
        foreach (glob($directory.'/*') as $path) {
            unlink($path);
        }
        rmdir($directory);
    });
});

it('completes authenticated history and persists an encrypted session while a WAL writer is held', function () {
    [$machine, $token] = $this->telemetryMachine();
    $this->freshContact($machine, $token, $this->telemetryReport())->assertOk();
    [$owner] = $this->signInBetterOwner();
    $id = session()->getId();
    $writer = new PDO('sqlite:'.DB::selectOne('PRAGMA database_list')->file);
    $writer->exec('BEGIN IMMEDIATE');
    try {
        $response = $this->withCredentials()->withCookie(config('session.cookie'), $id)->getJson('/api/v1/hosts/'.$machine->id.'/history?'.http_build_query([
            'from' => now()->subHours(4)->toIso8601String(), 'to' => now()->toIso8601String(), 'resolution' => 'raw',
        ]));
        expect($response->getStatusCode())->toBe(200);
        $response->assertJsonCount(1, 'points');
    } finally {
        $writer->rollBack();
    }
    $path = config('session.files').'/'.session()->getId();
    expect(is_file($path))->toBeTrue()
        ->and(str_contains(file_get_contents($path), $owner->email))->toBeFalse()
        ->and(str_contains(file_get_contents($path), 'owner.provider_cookies'))->toBeFalse()
        ->and(DB::table('sessions')->count())->toBe(0);
});

it('rejects a retained encrypted file session after owner recovery or an auth version change', function (string $change) {
    // Characterize the existing native driver and revocation chain before changing
    // its default; no custom session implementation is needed.
    config(['session.driver' => 'file']);
    app('session')->forgetDrivers();
    [$owner] = $this->signInBetterOwner();
    $id = session()->getId();
    $path = config('session.files').'/'.$id;
    expect(is_file($path))->toBeTrue();
    $retained = file_get_contents($path);
    $version = $owner->auth_version;
    if ($change === 'recovery') {
        $this->provisionBetterOwner($owner->email, true, 'replacement-password');
    } else {
        $owner->increment('auth_version');
    }
    expect(User::first()->auth_version)->toBe($version + 1);
    file_put_contents($path, $retained);
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    $this->withCredentials()->withCookie(config('session.cookie'), $id)->getJson('/api/v1/fleet')->assertUnauthorized();
})->with(['recovery', 'auth_version']);
