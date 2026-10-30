<?php

use App\Models\HubIdentity;
use App\Models\Machine;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\BetterAuthService;
use Tests\Support\IdentityEnvironment;

uses(RefreshDatabase::class, IdentityEnvironment::class, BetterAuthService::class);

it('binds hashed revocable credentials to machine identity and policy without exposing secrets', function () {
    Route::post('/api/test-collector/{machine}', fn (Request $request) => ['id' => $request->attributes->get('collector')->id, 'policy' => $request->attributes->get('collector')->data_policy])->middleware('collector');
    $machine = Machine::create(['name' => 'Beta-Restricted', 'data_policy' => Machine::HEALTH_PROCESSES]);
    $token = $machine->rotateToken();
    expect($machine->token_hash)->toBe(hash('sha256', $token));
    expect($machine->toJson())->not->toContain($token, 'token_hash');
    $path = '/api/test-collector/'.$machine->id;
    $this->withToken($token)->postJson($path)->assertOk()->assertJson(['id' => $machine->id, 'policy' => 'health_processes']);
    $this->withToken($token)->postJson('/api/test-collector/'.Str::uuid())->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    $this->withToken($token)->withHeader('X-Machine-ID', (string) Str::uuid())->postJson($path)->assertUnauthorized();
    $this->withHeader('X-Machine-ID', $machine->id)->withToken('invalid')->postJson($path)->assertUnauthorized();
    $machine->revokeToken();
    $this->withToken($token)->postJson($path)->assertUnauthorized();
    $replacement = $machine->rotateToken();
    expect($replacement)->not->toBe($token);
    $this->withToken($token)->postJson($path)->assertUnauthorized();
    $this->withToken($replacement)->postJson($path)->assertOk();
    expect(fn () => $machine->update(['data_policy' => 'titles']))->toThrow(InvalidArgumentException::class);
});

it('enrolls once unless rotation is explicit and supports local revocation', function () {
    $id = (string) Str::uuid();
    Artisan::call('fleet:enroll-machine', ['name' => 'Beta-Restricted', '--id' => $id]);
    $printed = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    expect($printed['machine_id'])->toBe($id)->and($printed['data_policy'])->toBe('health_processes');
    expect(Machine::findOrFail($id)->token_hash)->toBe(hash('sha256', $printed['token']));
    $hash = Machine::findOrFail($id)->token_hash;
    $this->artisan('fleet:enroll-machine', ['name' => 'Beta-Restricted', '--id' => $id])->assertFailed();
    expect(Machine::findOrFail($id)->token_hash)->toBe($hash);
    $this->artisan('fleet:enroll-machine', ['name' => 'Beta-Restricted', '--rotate' => true])->assertSuccessful();
    expect(Machine::findOrFail($id)->token_hash)->not->toBe($hash);
    $this->artisan('fleet:enroll-machine', ['name' => 'Beta-Restricted', '--revoke' => true])->assertSuccessful();
    expect(Machine::findOrFail($id)->token_revoked_at)->not->toBeNull();
    $this->artisan('fleet:enroll-machine', ['name' => 'Beta-Restricted', '--policy' => 'titles', '--rotate' => true])->assertFailed();
});

it('provisions one local Better Auth owner and revokes both provider and PHP sessions during recovery', function () {
    $this->startBetterAuth();
    $this->artisan('fleet:provision-owner', ['email' => 'owner@example.test', '--hub-name' => 'Alpha-Hub', '--role' => 'standby', '--peer' => ['Primary-Hub=https://primary.example.test'], '--telegram-chat' => '123', '--telegram' => true, '--primary-signing-key' => 'pinned-public-key'])
        ->expectsQuestion('New owner password (at least 12 characters)', 'correct-password')
        ->expectsQuestion('Confirm new owner password', 'correct-password')
        ->expectsQuestion('Telegram bot token', 'private-bot-token')->assertSuccessful();
    expect(User::count())->toBe(1);
    $hub = HubIdentity::findOrFail(1);
    expect($hub->role)->toBe('standby')->and($hub->peers)->toBe(['Primary-Hub' => 'https://primary.example.test']);
    expect(DB::table('hub_identities')->value('telegram_bot_token'))->not->toBe('private-bot-token');
    expect($hub->toJson())->not->toContain('private-bot-token');
    [$owner] = $this->signInBetterOwner(User::firstOrFail());
    $this->get('/')->assertOk();
    $this->artisan('fleet:provision-owner', ['email' => 'other@example.test'])->assertFailed();
    $subject = $owner->auth_subject;
    $this->provisionBetterOwner($owner->email, true, 'replacement-password');
    expect(User::count())->toBe(1)->and($owner->refresh()->auth_subject)->not->toBe($subject)->and($owner->auth_version)->toBe(2);
    $this->get('/owner/enroll')->assertRedirect('/login');
});

it('keeps hub machine credentials and encrypted settings independent across local databases and keys', function () {
    $id = (string) Str::uuid();
    $first = Machine::create(['id' => $id, 'name' => 'Alpha-Hub', 'data_policy' => Machine::TITLES]);
    $firstToken = $first->rotateToken();
    $encrypted = Crypt::encryptString('private-bot-token');
    $firstKey = config('app.key');
    config(['database.connections.standby' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true], 'app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    DB::purge('standby');
    DB::setDefaultConnection('standby');
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
    try {
        $this->artisan('migrate', ['--database' => 'standby', '--force' => true])->assertSuccessful();
        expect(fn () => Crypt::decryptString($encrypted))->toThrow(DecryptException::class);
        $second = Machine::create(['id' => $id, 'name' => 'Alpha-Hub', 'data_policy' => Machine::TITLES]);
        $secondToken = $second->rotateToken();
        expect($secondToken)->not->toBe($firstToken);
        expect(Machine::where('token_hash', hash('sha256', $firstToken))->exists())->toBeFalse();
    } finally {
        DB::setDefaultConnection('sqlite');
        DB::purge('standby');
        config(['app.key' => $firstKey]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
    }
});
