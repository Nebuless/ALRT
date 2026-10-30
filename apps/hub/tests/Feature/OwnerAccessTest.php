<?php

use App\Auth\ActionConfirmation;
use App\Auth\BetterAuth;
use App\Models\Machine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Symfony\Component\Process\Process;
use Tests\Support\BetterAuthService;
use Tests\Support\FleetAccessProbe;
use Tests\Support\IdentityEnvironment;

uses(RefreshDatabase::class, IdentityEnvironment::class, BetterAuthService::class);

it('uses Better Auth password and confirmed TOTP independently of Primary', function () {
    Http::preventStrayRequests();
    config(['nebuless.role' => 'standby']);
    $owner = $this->provisionBetterOwner();
    expect($owner->auth_provider)->toBe('better-auth')->and($owner->auth_subject)->not->toBeNull();
    $this->get('/')->assertRedirect('/login');
    $this->get('/login')->assertOk()->assertSee('Sign in');
    $this->post('/login', ['email' => $owner->email, 'password' => 'wrong-password'])->assertSessionHasErrors('email');
    $this->post('/login', ['email' => $owner->email, 'password' => 'correct-password'])->assertRedirect('/owner/enroll');
    $this->get('/')->assertRedirect('/owner/enroll');
    $this->getJson('/')->assertForbidden();
    $this->get('/owner/enroll')->assertOk();
    $this->post('/owner/enroll', ['password' => 'wrong-password'])->assertSessionHasErrors('confirmation');
    $this->post('/owner/enroll', ['password' => 'correct-password'])->assertRedirect('/owner/enroll');
    $enrollment = session('owner.enrollment');
    $this->get('/')->assertRedirect('/owner/enroll');
    $this->post('/owner/enroll/confirm', ['code' => $this->betterTotp($enrollment['totpURI'])])->assertRedirect('/owner/enroll');
    $this->get('/')->assertOk();
    $this->get('/owner/enroll')->assertOk()->assertSee('Recovery codes');
    $this->post('/owner/enroll', ['password' => 'correct-password'])->assertForbidden();
    $this->post('/logout')->assertRedirect('/login');
    foreach (['/register', '/forgot-password', '/api/auth/sign-up/email', '/api/auth/two-factor/disable', '/user/two-factor-authentication'] as $path) {
        $this->get($path)->assertNotFound();
        $this->post($path)->assertNotFound();
    }
});

it('requires real TOTP after password and allows a backup code once without granting action confirmation', function () {
    [$owner, $enrollment] = $this->signInBetterOwner();
    $this->post('/logout');
    $this->post('/login', ['email' => $owner->email, 'password' => 'correct-password'])->assertRedirect('/two-factor-challenge');
    $this->get('/')->assertRedirect('/login');
    $this->post('/two-factor-challenge', ['code' => 'not-a-code'])->assertSessionHasErrors('code');
    $this->post('/two-factor-challenge', ['recovery_code' => $enrollment['backupCodes'][0]])->assertRedirect('/');
    $this->get('/')->assertOk();
    $target = Machine::create(['name' => 'Alpha-Hub', 'data_policy' => 'titles']);
    $other = Machine::create(['name' => 'Primary-Hub', 'data_policy' => 'titles']);
    $this->postJson('/owner/action-confirmation', ['target' => $target->id, 'password' => 'correct-password', 'code' => $enrollment['backupCodes'][1]])->assertUnprocessable();
    $this->postJson('/owner/action-confirmation', ['target' => $target->id, 'password' => 'wrong-password', 'code' => $this->betterTotp($enrollment['totpURI'])])->assertUnprocessable();
    $proof = $this->postJson('/owner/action-confirmation', ['target' => $target->id, 'password' => 'correct-password', 'code' => $this->betterTotp($enrollment['totpURI'])])->assertCreated()->json('proof');
    $request = request();
    $request->setUserResolver(fn () => $owner);
    $request->setLaravelSession(app('session.store'));
    $request->attributes->set('owner.provider_session', app(BetterAuth::class)->current($request));
    $confirmation = app(ActionConfirmation::class);
    expect(fn () => $confirmation->consume($request, $other->id, $proof))->toThrow(ValidationException::class);
    $originalSessionId = $request->session()->getId();
    $request->session()->setId(Str::random(40));
    expect(fn () => $confirmation->consume($request, $target->id, $proof))->toThrow(ValidationException::class);
    $request->session()->setId($originalSessionId);
    $providerSession = $request->attributes->get('owner.provider_session');
    $request->attributes->set('owner.provider_session', [...$providerSession, 'id' => 'another-provider-session']);
    expect(fn () => $confirmation->consume($request, $target->id, $proof))->toThrow(ValidationException::class);
    $request->attributes->set('owner.provider_session', $providerSession);
    $confirmation->consume($request, $target->id, $proof);
    expect(fn () => $confirmation->consume($request, $target->id, $proof))->toThrow(ValidationException::class);
    $second = $confirmation->issue($request, $target->id, 'correct-password', $this->betterTotp($enrollment['totpURI']));
    $this->travel(301)->seconds();
    expect(fn () => $confirmation->consume($request, $target->id, $second))->toThrow(ValidationException::class);
    $this->travelBack();
    $this->post('/logout');
    $this->post('/login', ['email' => $owner->email, 'password' => 'correct-password']);
    $this->post('/two-factor-challenge', ['recovery_code' => $enrollment['backupCodes'][0]])->assertSessionHasErrors('code');
});

it('rechecks authoritative enrollment and revocation during Livewire hydration', function () {
    Livewire\Livewire::component('identity-fleet-probe', FleetAccessProbe::class);
    Route::get('/identity-fleet-probe', fn () => Blade::render('@livewire("identity-fleet-probe")'))->middleware(['web', 'auth', 'owner']);
    [$owner] = $this->signInBetterOwner();
    $page = $this->get('/identity-fleet-probe')->assertOk();
    preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
    $snapshot = html_entity_decode($matches[1], ENT_QUOTES);
    $this->provisionBetterOwner($owner->email, true, 'replacement-password');
    $this->withHeader('X-Livewire', 'true')->postJson(app(HandleRequests::class)->getUpdateUri(), [
        'components' => [['snapshot' => $snapshot, 'updates' => (object) [], 'calls' => [['method' => 'inspectFleet', 'params' => [], 'path' => '']]]],
    ])->assertUnauthorized();
    expect(session()->has('identity.probe_ran'))->toBeFalse();
    $this->getJson('/api/v1/fleet')->assertUnauthorized();
});

it('fails closed when the local auth service is unavailable and rate limits sensitive confirmation', function () {
    [$owner, $enrollment] = $this->signInBetterOwner();
    $target = Machine::create(['name' => 'Alpha-Hub', 'data_policy' => 'titles']);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/owner/action-confirmation', ['target' => $target->id, 'password' => 'wrong-password', 'code' => $this->betterTotp($enrollment['totpURI'])])->assertUnprocessable();
    }
    $this->postJson('/owner/action-confirmation', ['target' => $target->id, 'password' => 'correct-password', 'code' => $this->betterTotp($enrollment['totpURI'])])->assertTooManyRequests();
    config(['better-auth.bridge_url' => 'http://127.0.0.1:1']);
    Http::allowStrayRequests(['http://127.0.0.1:1/*']);
    $this->getJson('/api/v1/fleet')->assertServiceUnavailable();
});

it('rejects a Livewire update when authoritative TOTP enrollment is revoked', function () {
    Livewire\Livewire::component('identity-fleet-probe', FleetAccessProbe::class);
    Route::get('/identity-fleet-probe', fn () => Blade::render('@livewire("identity-fleet-probe")'))->middleware(['web', 'auth', 'owner']);
    [$owner] = $this->signInBetterOwner();
    $page = $this->get('/identity-fleet-probe')->assertOk();
    preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
    $snapshot = html_entity_decode($matches[1], ENT_QUOTES);
    $script = <<<'JS'
import { DatabaseSync } from 'node:sqlite';
const db = new DatabaseSync(process.argv[1]);
db.prepare('UPDATE "user" SET "twoFactorEnabled" = 0 WHERE id = ?').run(process.argv[2]);
db.close();
JS;
    $process = new Process(['node', '--input-type=module', '-e', $script, config('better-auth.database'), $owner->auth_subject]);
    $process->mustRun();
    $this->withHeader('X-Livewire', 'true')->postJson(app(HandleRequests::class)->getUpdateUri(), [
        'components' => [['snapshot' => $snapshot, 'updates' => (object) [], 'calls' => [['method' => 'inspectFleet', 'params' => [], 'path' => '']]]],
    ])->assertForbidden();
    expect(session()->has('identity.probe_ran'))->toBeFalse();
});

it('requires CSRF tokens for browser authentication and action routes', function () {
    app()->instance('env', 'production');
    foreach (['/login', '/two-factor-challenge', '/owner/enroll', '/owner/enroll/confirm', '/logout', '/owner/action-confirmation'] as $path) {
        $this->post($path)->assertStatus(419);
    }
});

it('keeps local auth credentials off ambient HTTP proxies', function (): void {
    $this->startBetterAuth();
    $capture = sys_get_temp_dir().'/nebuless-auth-proxy-'.bin2hex(random_bytes(8));
    $proxy = new Process(['node', dirname(__DIR__).'/Support/capturing-proxy.mjs'], env: ['CAPTURE_FILE' => $capture]);
    $proxy->start();
    $proxy->waitUntil(fn (string $type, string $output): bool => str_contains($output, '"ready":true'));
    $ready = json_decode(trim($proxy->getOutput()), true, flags: JSON_THROW_ON_ERROR);
    $settings = ['HTTP_PROXY' => 'http://127.0.0.1:'.$ready['port'], 'http_proxy' => 'http://127.0.0.1:'.$ready['port'], 'NO_PROXY' => '', 'no_proxy' => ''];
    $old = [];
    foreach ($settings as $key => $value) {
        $old[$key] = [getenv($key), $_SERVER[$key] ?? null, $_ENV[$key] ?? null];
        putenv($key.'='.$value);
        $_SERVER[$key] = $_ENV[$key] = $value;
    }
    try {
        // A genuine local provider rejects this synthetic account. A diverted
        // request gets the capturing proxy's503 instead and exposes the bridge.
        $response = $this->post('/login', ['email' => 'proxy-canary@example.test', 'password' => 'synthetic-private-password']);
        expect(is_file($capture) ? json_decode(file_get_contents($capture), true)['requests'] : 0)->toBe(0);
        $response->assertSessionHasErrors();
    } finally {
        foreach ($old as $key => [$environment, $server, $env]) {
            $environment === false ? putenv($key) : putenv($key.'='.$environment);
            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
            if ($env === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }
        }
        $proxy->stop(1);
        if (is_file($capture)) {
            unlink($capture);
        }
    }
});

it('clears local owner authority on CSRF-valid logout despite a provider outage', function (string $failure) {
    $this->signInBetterOwner();
    $cookies = session('owner.provider_cookies');
    $oldId = session()->getId();
    $oldToken = session()->token();
    $this->withSession(['owner.enrollment' => ['private'], 'owner.challenge' => true, 'owner.recovery_codes' => ['private']]);
    Http::fake(function ($request) use ($failure) {
        if (str_ends_with($request->url(), '/session') || $failure === 'bridge') {
            return Http::failedConnection();
        }

        return Http::response([], 503);
    });
    app()->instance('env', 'production');
    $this->post('/logout')->assertStatus(419);
    Http::assertNothingSent();
    expect(session('owner.provider_cookies'))->toBe($cookies);
    $this->post('/logout', ['_token' => $oldToken])->assertRedirect('/login')
        ->assertSessionHasErrors(['logout' => 'Signed out locally. Provider session revocation could not be confirmed.']);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sign-out') && (array) $request['cookies'] === $cookies);
    foreach (['provider_cookies', 'auth_version', 'enrollment', 'challenge', 'recovery_codes'] as $key) {
        expect(session()->has('owner.'.$key))->toBeFalse();
    }
    expect(session()->getId())->not->toBe($oldId)
        ->and(session()->token())->not->toBe($oldToken);
    // Restore the real bridge; local cleanup does not revoke copied credentials.
    Http::swap(new Factory);
    Http::allowStrayRequests([config('better-auth.bridge_url').'/*']);
    $this->getJson('/api/v1/fleet')->assertUnauthorized();
})->with(['bridge', 'sign-out']);
