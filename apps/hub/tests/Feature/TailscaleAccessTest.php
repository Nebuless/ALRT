<?php

use App\Auth\ActionConfirmation;
use App\Models\HubIdentity;
use App\Models\Machine;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Tests\Support\FleetAccessProbe;
use Tests\Support\TelemetryDatabase;

uses(TelemetryDatabase::class);

function taggedNodeFixture(array $overrides = []): array
{
    return ['Node' => array_replace([
        'StableID' => 'nPhoneFixture', 'Tags' => ['tag:phone'],
        'Addresses' => ['100.80.0.2/32', 'fd7a:115c:a1e0::2/128'],
    ], $overrides)];
}

beforeEach(function () {
    $this->prepareTelemetryDatabase();
    config(['nebuless.auth_mode' => 'tailscale', 'app.url' => 'https://hub.example.test:8444', 'better-auth.bridge_url' => 'http://127.0.0.1:1']);
    Http::preventStrayRequests();
    $this->edge = function (string $login = 'owner@example.test', array $overrides = []) {
        $server = array_replace([
            'NEBULESS_TAILSCALE_INGRESS' => '1', 'REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on',
            'SERVER_PORT' => 8444, 'HTTP_HOST' => 'hub.example.test:8444', 'HTTP_TAILSCALE_USER_LOGIN' => $login,
        ], $overrides);
        $scheme = $server['HTTPS'] === 'on' ? 'https' : 'http';
        URL::forceRootUrl($scheme.'://'.$server['HTTP_HOST']);
        URL::forceScheme($scheme);

        return $this->withCredentials()->withCookie(config('session.cookie'), session()->getId())->withServerVariables($server);
    };
    HubIdentity::create(['id' => 1, 'hub_uuid' => '33333333-3333-4333-8333-333333333333', 'name' => 'Primary-Hub', 'role' => 'primary', 'peers' => []]);
});

it('opens the fleet directly for a verified tailnet human without Better Auth or enrollment', function () {
    expect(User::count())->toBe(0);
    ($this->edge)()->get('/')->assertOk()->assertSee('Tailscale access')->assertDontSee('Sign out');
    ($this->edge)()->getJson('/api/v1/fleet')->assertOk();
    ($this->edge)()->get('/login')->assertOk()->assertSee('Open fleet')->assertSee('Connected as owner@example.test.')->assertSee('https://hub.example.test:8444/login', false)->assertDontSee('type="password"', false);
    $identity = User::first();
    expect($identity->auth_provider)->toBe('tailscale')->and($identity->auth_subject)->toBe('owner@example.test')->and($identity->owner_slot)->toBeNull();
    ($this->edge)()->get('/')->assertOk();
    expect(User::count())->toBe(1);
});

it('opens the fleet for a tagged device verified by the local Tailscale daemon', function (string $address) {
    Process::preventStrayProcesses();
    Process::fake(['*' => Process::result(output: json_encode(taggedNodeFixture()))]);
    ($this->edge)('', ['NEBULESS_TAILSCALE_SOURCE' => $address])->get('/')->assertOk();
    ($this->edge)('', ['NEBULESS_TAILSCALE_SOURCE' => $address])->getJson('/api/v1/fleet')->assertOk();
    expect(User::sole()->auth_subject)->toBe('device nPhoneFixture')->and(User::sole()->owner_slot)->toBeNull();
    Process::assertRan(fn ($process) => $process->command === ['/usr/bin/tailscale', 'whois', '--json', $address] && $process->timeout === 2);
    Process::assertRanTimes(fn () => true, 2);
})->with(['100.80.0.2', 'fd7a:115c:a1e0::2']);

it('rejects unverified or ambiguous device source claims before consulting Tailscale', function (array $overrides) {
    Process::preventStrayProcesses();
    Process::fake();
    ($this->edge)('', $overrides)->getJson('/api/v1/fleet')->assertUnauthorized();
    Process::assertNothingRan();
})->with([
    'client source header' => [['HTTP_NEBULESS_TAILSCALE_SOURCE' => '100.80.0.2']],
    'ordinary forwarded header' => [['HTTP_X_FORWARDED_FOR' => '100.80.0.2']],
    'multiple addresses' => [['NEBULESS_TAILSCALE_SOURCE' => '100.80.0.2, 100.80.0.3']],
    'public address' => [['NEBULESS_TAILSCALE_SOURCE' => '203.0.113.2']],
    'loopback address' => [['NEBULESS_TAILSCALE_SOURCE' => '127.0.0.1']],
    'funnel' => [['NEBULESS_TAILSCALE_SOURCE' => '100.80.0.2', 'HTTP_TAILSCALE_FUNNEL_REQUEST' => '?1']],
    'no marker' => [['NEBULESS_TAILSCALE_SOURCE' => '100.80.0.2', 'NEBULESS_TAILSCALE_INGRESS' => null]],
]);

it('fails closed when local Tailscale cannot verify the exact current tagged device', function (string $output, int $exit) {
    Process::fake(['*' => Process::result(output: $output, exitCode: $exit)]);
    ($this->edge)('', ['NEBULESS_TAILSCALE_SOURCE' => '100.80.0.2'])->getJson('/api/v1/fleet')->assertUnauthorized();
    expect(User::count())->toBe(0);
})->with([
    'daemon unavailable' => ['', 1],
    'malformed response' => ['not json', 0],
    'unknown device' => ['{}', 0],
    'untagged device' => [json_encode(taggedNodeFixture(['Tags' => []])), 0],
    'different address' => [json_encode(taggedNodeFixture(['Addresses' => ['100.80.0.3/32']])), 0],
    'subnet route only' => [json_encode(taggedNodeFixture(['Addresses' => ['100.80.0.2/24']])), 0],
    'missing stable id' => [json_encode(taggedNodeFixture(['StableID' => ''])), 0],
    'expired device' => [json_encode(taggedNodeFixture(['Expired' => true])), 0],
    'expired key' => [json_encode(taggedNodeFixture(['KeyExpiry' => '2020-01-01T00:00:00Z'])), 0],
    'invalid key expiry' => [json_encode(taggedNodeFixture(['KeyExpiry' => 'invalid'])), 0],
]);

it('clears tagged access when the daemon no longer recognizes the device', function () {
    Process::fake(['*' => Process::sequence()->push(Process::result(output: json_encode(taggedNodeFixture())))->push(Process::result(exitCode: 1))]);
    ($this->edge)('', ['NEBULESS_TAILSCALE_SOURCE' => '100.80.0.2'])->get('/')->assertOk();
    session()->put('identity.private_marker', true);
    ($this->edge)('', ['NEBULESS_TAILSCALE_SOURCE' => '100.80.0.2'])->getJson('/api/v1/fleet')->assertUnauthorized();
    expect(session()->has('identity.private_marker'))->toBeFalse();
});

it('rejects Funnel even with a valid human identity and never substitutes devices for invalid human headers', function (array $overrides) {
    Process::fake();
    ($this->edge)('owner@example.test', $overrides)->getJson('/api/v1/fleet')->assertUnauthorized();
    Process::assertNothingRan();
})->with([
    [['HTTP_TAILSCALE_FUNNEL_REQUEST' => '?1']],
    [['HTTP_TAILSCALE_USER_LOGIN' => 'device nPhoneFixture', 'NEBULESS_TAILSCALE_SOURCE' => '100.80.0.2']],
]);

it('keeps a tagged device action proof bound to its identity across device changes', function () {
    [$machine] = $this->telemetryMachine();
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(output: json_encode(taggedNodeFixture(['KeyExpiry' => '0001-01-01T00:00:00Z']))))
        ->push(Process::result(output: json_encode(taggedNodeFixture(['StableID' => 'nSecondDevice']))))]);
    $proof = ($this->edge)('', ['NEBULESS_TAILSCALE_SOURCE' => '100.80.0.2'])
        ->postJson('/owner/action-confirmation', ['target' => $machine->id])->assertCreated()->json('proof');
    $session = session()->getId();
    ($this->edge)('', ['NEBULESS_TAILSCALE_SOURCE' => '100.80.0.2'])->getJson('/api/v1/fleet')->assertOk();
    expect(session()->getId())->not->toBe($session);
    expect(fn () => app(ActionConfirmation::class)->consume(request(), $machine->id, $proof))->toThrow(ValidationException::class);
    Process::assertRanTimes(fn () => true, 2);
});

it('requires trusted ingress and the configured HTTPS origin instead of trusting client headers or old cookies', function (array $untrusted) {
    ($this->edge)()->get('/')->assertOk();
    ($this->edge)('owner@example.test', $untrusted)->getJson('/api/v1/fleet')->assertUnauthorized();
    ($this->edge)('owner@example.test', $untrusted)->get('/login')->assertOk()->assertSee('Connect Tailscale')->assertDontSee('Open fleet');
})->with([
    'no edge marker' => [['NEBULESS_TAILSCALE_INGRESS' => null]],
    'forged client marker' => [['NEBULESS_TAILSCALE_INGRESS' => null, 'HTTP_NEBULESS_TAILSCALE_INGRESS' => '1']],
    'remote client' => [['REMOTE_ADDR' => '100.101.102.103']],
    'plain HTTP' => [['HTTPS' => 'off']],
    'wrong origin' => [['HTTP_HOST' => 'other.example.test:8444']],
    'no human identity' => [['HTTP_TAILSCALE_USER_LOGIN' => '']],
]);

it('keeps preserved Better Auth and distinct Tailscale humans separate and drops old identity proofs', function () {
    $legacy = new User;
    $legacy->forceFill(['name' => 'Legacy', 'email' => 'owner@example.test', 'owner_slot' => 1, 'auth_provider' => 'better-auth', 'auth_subject' => 'legacy-subject'])->save();
    ($this->edge)()->get('/')->assertOk();
    $first = User::where('auth_provider', 'tailscale')->first();
    $sessionId = session()->getId();
    session()->put('identity.private_marker', true);
    ($this->edge)('second@example.test')->get('/')->assertOk();
    $second = User::where('auth_subject', 'second@example.test')->first();
    expect(User::count())->toBe(3)->and($second->id)->not->toBe($first->id)
        ->and(session()->getId())->not->toBe($sessionId)->and(session()->has('identity.private_marker'))->toBeFalse()
        ->and($legacy->fresh()->auth_subject)->toBe('legacy-subject')->and($legacy->fresh()->email)->toBe('owner@example.test');
});

it('rechecks human identity during Livewire hydration and on API calls', function () {
    Livewire\Livewire::component('tailscale-fleet-probe', FleetAccessProbe::class);
    Route::get('/tailscale-fleet-probe', fn () => Blade::render('@livewire("tailscale-fleet-probe")'))->middleware(['web', 'auth', 'owner']);
    $page = ($this->edge)()->get('/tailscale-fleet-probe')->assertOk();
    preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
    $snapshot = html_entity_decode($matches[1], ENT_QUOTES);
    ($this->edge)('')->withHeader('X-Livewire', 'true')->postJson(app(HandleRequests::class)->getUpdateUri(), [
        'components' => [['snapshot' => $snapshot, 'updates' => (object) [], 'calls' => [['method' => 'inspectFleet', 'params' => [], 'path' => '']]]],
    ])->assertUnauthorized();
    expect(session()->has('identity.probe_ran'))->toBeFalse();
    ($this->edge)('')->getJson('/api/v1/fleet')->assertUnauthorized();
    ($this->edge)('second@example.test')->getJson('/api/v1/fleet')->assertOk();
});

it('disables legacy credential and enrollment endpoints in Tailscale mode', function () {
    foreach (['/login', '/two-factor-challenge', '/logout', '/owner/enroll', '/owner/enroll/confirm'] as $path) {
        ($this->edge)()->postJson($path, ['email' => 'owner@example.test', 'password' => 'unused', 'code' => '123456'])->assertNotFound();
    }
    foreach (['/two-factor-challenge', '/owner/enroll'] as $path) {
        ($this->edge)()->get($path)->assertNotFound();
    }
});

it('keeps collector bearer authentication independent from human tailnet authentication', function () {
    [$machine, $token] = $this->telemetryMachine();
    ($this->edge)()->postJson($this->collectorPath($machine, 'challenge'))->assertUnauthorized();
    ($this->edge)('')->withToken($token)->postJson($this->collectorPath($machine, 'challenge'))->assertCreated();
    ($this->edge)('')->getJson('/api/v1/fleet')->assertUnauthorized();
});

it('does not trust Tailscale headers when Better Auth mode is explicitly selected', function () {
    config(['nebuless.auth_mode' => 'better-auth']);
    ($this->edge)()->get('/')->assertRedirect('/login');
    ($this->edge)()->getJson('/api/v1/fleet')->assertUnauthorized();
    expect(User::count())->toBe(0);
});

it('ignores an old Better Auth cookie jar when no current Tailscale identity is present', function () {
    $owner = new User;
    $owner->forceFill(['name' => 'Legacy', 'email' => 'legacy@example.test', 'owner_slot' => 1, 'auth_provider' => 'better-auth', 'auth_subject' => 'legacy-subject'])->save();
    $this->withSession(['owner.provider_cookies' => ['stale' => 'synthetic-fixture'], 'owner.auth_version' => 1]);
    ($this->edge)('')->getJson('/api/v1/fleet')->assertUnauthorized();
    expect(session()->has('owner.provider_cookies'))->toBeFalse();
});

it('preserves Tailscale identities when separately provisioning a future Better Auth owner', function () {
    ($this->edge)()->get('/')->assertOk();
    $tailscale = User::sole();
    $before = $tailscale->getRawOriginal();
    config(['nebuless.auth_mode' => 'better-auth']);
    $this->startBetterAuth();
    $this->artisan('fleet:provision-owner', ['email' => 'future-owner@example.test'])
        ->expectsQuestion('New owner password (at least 12 characters)', 'synthetic-future-password')
        ->expectsQuestion('Confirm new owner password', 'synthetic-future-password')->assertSuccessful();
    expect($tailscale->fresh()->getRawOriginal())->toEqual($before)
        ->and(User::where('owner_slot', 1)->sole()->auth_provider)->toBe('better-auth')
        ->and(User::count())->toBe(2);
});

it('keeps Tailscale reboot confirmation target bound single use and unavailable on standby', function () {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_ED25519]);
    openssl_pkey_export($key, $pem);
    config(['actions.private_key' => $pem, 'actions.key_id' => 'primary-test']);
    [$machine, $token] = $this->telemetryMachine();
    $this->freshContact($machine, $token, $this->telemetryReport())->assertOk();
    $machine->forceFill(['reboot_enabled' => true])->save();
    $this->withToken($token)->postJson($this->collectorPath($machine, 'actions/pickup'), ['boot_id' => $this->telemetryReport()['boot_id'], 'available' => true])->assertOk();
    $this->withHeader('Authorization', '');
    $proof = ($this->edge)()->postJson('/owner/action-confirmation', ['target' => $machine->id])->assertCreated()->json('proof');
    $other = Machine::create(['name' => 'Beta-Restricted', 'data_policy' => 'health_processes']);
    expect(fn () => app(ActionConfirmation::class)->consume(request(), $other->id, $proof))->toThrow(ValidationException::class);
    ($this->edge)()->postJson('/api/v1/hosts/'.$other->id.'/reboot', ['proof' => $proof])->assertConflict();
    HubIdentity::find(1)->update(['role' => 'standby']);
    ($this->edge)()->postJson('/api/v1/hosts/'.$machine->id.'/reboot', ['proof' => $proof])->assertForbidden();
    HubIdentity::find(1)->update(['role' => 'primary']);
    ($this->edge)()->postJson('/api/v1/hosts/'.$machine->id.'/reboot', ['proof' => $proof])->assertCreated();
    ($this->edge)()->postJson('/api/v1/hosts/'.$machine->id.'/reboot', ['proof' => $proof])->assertUnprocessable();
    expect(DB::table('reboot_actions')->count())->toBe(1);
    $this->travel(61)->seconds();
    $next = ($this->edge)()->postJson('/owner/action-confirmation', ['target' => $machine->id])->assertCreated()->json('proof');
    ($this->edge)('second@example.test')->postJson('/api/v1/hosts/'.$machine->id.'/reboot', ['proof' => $next])->assertUnprocessable();
    ($this->edge)('')->postJson('/api/v1/hosts/'.$machine->id.'/reboot', ['proof' => $next])->assertUnauthorized();
});

it('binds action proof to session auth version expiry and the current edge identity', function () {
    [$machine] = $this->telemetryMachine();
    $proof = ($this->edge)()->postJson('/owner/action-confirmation', ['target' => $machine->id])->assertCreated()->json('proof');
    $request = request();
    $confirmation = app(ActionConfirmation::class);
    $id = $request->session()->getId();
    $request->session()->setId(str_repeat('a', 40));
    expect(fn () => $confirmation->consume($request, $machine->id, $proof))->toThrow(ValidationException::class);
    $request->session()->setId($id);
    $request->headers->set('Tailscale-User-Login', 'second@example.test');
    expect(fn () => $confirmation->consume($request, $machine->id, $proof))->toThrow(ValidationException::class);
    $request->headers->set('Tailscale-User-Login', 'owner@example.test');
    $request->user()->auth_version++;
    expect(fn () => $confirmation->consume($request, $machine->id, $proof))->toThrow(ValidationException::class);
    $request->user()->auth_version--;
    $this->travel(301)->seconds();
    expect(fn () => $confirmation->consume($request, $machine->id, $proof))->toThrow(ValidationException::class);
});

it('requires CSRF even for a verified Tailscale action confirmation', function () {
    app()->instance('env', 'production');
    ($this->edge)()->postJson('/owner/action-confirmation', ['target' => '22222222-2222-4222-8222-222222222222'])->assertStatus(419);
    expect(DB::table('action_confirmation_proofs')->count())->toBe(0);
});

it('preserves the legacy owner and referencing action proofs when making owner slots nullable', function () {
    Schema::table('users', function (Blueprint $table) {
        $table->unsignedTinyInteger('owner_slot')->nullable(false)->default(1)->change();
    });
    $owner = new User;
    $owner->forceFill(['name' => 'Legacy', 'email' => 'legacy@example.test', 'owner_slot' => 1, 'auth_provider' => 'better-auth', 'auth_subject' => 'legacy-subject', 'auth_version' => 7])->save();
    DB::table('action_confirmation_proofs')->insert([
        'token_hash' => str_repeat('a', 64), 'user_id' => $owner->id, 'auth_version' => 7,
        'target' => '22222222-2222-4222-8222-222222222222', 'session_hash' => str_repeat('b', 64), 'expires_at' => now()->addMinutes(5),
    ]);
    $before = [DB::table('users')->get()->toArray(), DB::table('action_confirmation_proofs')->get()->toArray()];
    $migration = require database_path('migrations/2026_10_16_000060_allow_tailscale_identities.php');
    $migration->up();
    expect([DB::table('users')->get()->toArray(), DB::table('action_confirmation_proofs')->get()->toArray()])->toEqual($before)
        ->and(DB::select('PRAGMA foreign_key_check'))->toBe([])
        ->and(DB::selectOne('PRAGMA foreign_keys')->foreign_keys)->toBe(1);
    ($this->edge)()->get('/')->assertOk();
    ($this->edge)('second@example.test')->get('/')->assertOk();
    expect(User::whereNull('owner_slot')->count())->toBe(2)->and(User::where('owner_slot', 1)->sole()->id)->toBe($owner->id);
});

it('renders an explicit Tailscale reboot confirmation without password fields', function () {
    [$machine] = $this->telemetryMachine();
    ($this->edge)()->get('/hosts/'.$machine->id)->assertOk()->assertSee('Confirm and queue')->assertSee('Tailscale identity')->assertDontSee('id="reboot-password"', false)->assertDontSee('id="reboot-code"', false);
});

it('queues through a real Livewire confirmation without password or authenticator arguments', function () {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_ED25519]);
    openssl_pkey_export($key, $pem);
    config(['actions.private_key' => $pem, 'actions.key_id' => 'primary-test']);
    [$machine, $token] = $this->telemetryMachine();
    $this->freshContact($machine, $token, $this->telemetryReport())->assertOk();
    $machine->forceFill(['reboot_enabled' => true])->save();
    $this->withToken($token)->postJson($this->collectorPath($machine, 'actions/pickup'), ['boot_id' => $this->telemetryReport()['boot_id'], 'available' => true])->assertOk();
    $this->withHeader('Authorization', '');
    $page = ($this->edge)()->get('/hosts/'.$machine->id)->assertOk();
    preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
    $snapshot = html_entity_decode($matches[1], ENT_QUOTES);
    ($this->edge)()->withHeader('X-Livewire', 'true')->postJson(app(HandleRequests::class)->getUpdateUri(), [
        'components' => [['snapshot' => $snapshot, 'updates' => (object) [], 'calls' => [['method' => 'queueReboot', 'params' => [], 'path' => '']]]],
    ])->assertOk();
    expect(DB::table('reboot_actions')->count())->toBe(1)
        ->and(DB::table('action_confirmation_proofs')->whereNotNull('consumed_at')->count())->toBe(1);
});
