<?php

use App\Citizens\CitizensState;
use App\Citizens\ValidateCitizens;
use App\Http\Controllers\CitizensController;
use App\Models\HubIdentity;
use App\Models\Machine;
use App\Telemetry\TelemetryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\Support\TelemetryDatabase;

uses(TelemetryDatabase::class);

function citizenFixture(array $changes = []): array
{
    return array_replace([
        'id' => str_repeat('a', 64), 'parent_id' => null, 'tool' => 'pi', 'state' => 'working',
        'observed_at' => now()->toISOString(), 'runtime_id' => str_repeat('b', 64),
        'attribution' => 'dedicated', 'process' => ['boot_id' => '11111111-1111-4111-8111-111111111111', 'pid' => 4321, 'start_ticks' => 12345],
        'cpu_percent' => null, 'rss_bytes' => 4096, 'can_terminate' => true,
    ], $changes);
}

function citizensEnvelope(array $changes = []): array
{
    return array_replace([
        'boot_id' => '11111111-1111-4111-8111-111111111111', 'bridge_id' => '22222222-2222-4222-8222-222222222222',
        'sequence' => 1, 'sampled_at' => now()->toISOString(), 'coverage' => 'complete',
        'citizens' => [citizenFixture()], 'results' => [],
    ], $changes);
}

beforeEach(function () {
    $this->prepareTelemetryDatabase();
    $this->travelTo(now()->startOfSecond()->addMicroseconds(123456));
    config(['nebuless.auth_mode' => 'tailscale', 'app.url' => 'https://hub.example.test:8444']);
    Http::preventStrayRequests();
    Process::preventStrayProcesses();
    HubIdentity::create(['id' => 1, 'hub_uuid' => '33333333-3333-4333-8333-333333333333', 'name' => 'Primary-Hub', 'role' => 'primary', 'peers' => []]);
    [$this->target, $this->token] = $this->telemetryMachine();
    // Bind only inside this test. Production route ownership belongs to the lead.
    Route::middleware('api')->prefix('api/v1/collector/{machine}')->middleware('collector')->group(function () {
        Route::post('citizens/challenge', [CitizensController::class, 'challenge']);
        Route::post('citizens', [CitizensController::class, 'exchange']);
    });
    Route::middleware(['api', 'web', 'auth', 'owner'])->prefix('api/v1')->group(function () {
        Route::get('citizens', [CitizensController::class, 'index']);
        Route::post('citizens/{machine}/{citizen}/terminate', [CitizensController::class, 'terminate'])->middleware('throttle:owner-action-confirmation');
    });
    $this->edge = function (array $overrides = []) {
        URL::forceRootUrl('https://hub.example.test:8444');
        URL::forceScheme('https');

        return $this->withHeader('Authorization', '')->withCredentials()->withCookie(config('session.cookie'), session()->getId())->withServerVariables(array_replace([
            'NEBULESS_TAILSCALE_INGRESS' => '1', 'REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on',
            'SERVER_PORT' => 8444, 'HTTP_HOST' => 'hub.example.test:8444', 'HTTP_TAILSCALE_USER_LOGIN' => 'owner@example.test',
        ], $overrides));
    };
    $this->send = function (array $body = [], ?string $nonce = null) {
        $nonce ??= $this->withToken($this->token)->postJson($this->collectorPath($this->target, 'citizens/challenge'), [], [], JSON_FORCE_OBJECT)->assertCreated()->json('nonce');

        return $this->withToken($this->token)->postJson($this->collectorPath($this->target, 'citizens'), ['nonce' => $nonce] + citizensEnvelope($body));
    };
    $this->roster = fn () => ($this->edge)()->getJson('/api/v1/citizens');
    $this->kill = function (?string $revision = null, array $changes = []) {
        $revision ??= ($this->roster)()->assertOk()->json('hosts.0.citizens.0.revision');

        return ($this->edge)()->postJson('/api/v1/citizens/'.$this->target->id.'/'.str_repeat('a', 64).'/terminate', array_replace(['revision' => $revision, 'confirmation' => true], $changes));
    };
});

it('keeps confirmation revisions stable across fresh metrics but invalidates changed process identity', function () {
    ($this->send)()->assertOk();
    $revision = ($this->roster)()->json('hosts.0.citizens.0.revision');
    $this->travel(1)->seconds();
    ($this->send)(['sequence' => 2, 'citizens' => [citizenFixture(['cpu_percent' => 45, 'rss_bytes' => 8192])]])->assertOk();
    expect(($this->roster)()->json('hosts.0.citizens.0.revision'))->toBe($revision);
    ($this->kill)($revision)->assertCreated();
    $changed = citizenFixture();
    $changed['process']['start_ticks']++;
    ($this->send)(['sequence' => 3, 'citizens' => [$changed]])->assertOk();
    expect(($this->roster)()->json('hosts.0.citizens.0.revision'))->not->toBe($revision);
});

it('prunes expired citizens in bounded maintenance batches without changing action safety records', function () {
    ($this->send)()->assertOk();
    ($this->kill)()->assertCreated();
    $observation = (array) DB::table('citizen_observations')->first();
    $rows = [];
    for ($index = 0; $index < 257; $index++) {
        $rows[] = array_replace($observation, ['citizen_id' => hash('sha256', 'old-'.$index), 'observed_at' => now()->subHours(5)->toISOString()]);
    }
    DB::table('citizen_observations')->insert($rows);
    app(CitizensState::class)->prune();
    expect(DB::table('citizen_observations')->count())->toBe(130);
    expect(DB::table('citizen_actions')->count())->toBe(1);
    expect(DB::table('citizen_incarnations')->count())->toBe(1);
    app(CitizensState::class)->prune();
    app(CitizensState::class)->prune();
    expect(DB::table('citizen_observations')->count())->toBe(1);
});

it('retains a fresh shared measurement carrier after a stale first member', function () {
    $stale = citizenFixture(['attribution' => 'shared', 'can_terminate' => false, 'observed_at' => now()->subSeconds(11)->toISOString()]);
    $fresh = citizenFixture(['id' => str_repeat('b', 64), 'attribution' => 'shared', 'can_terminate' => false]);
    ($this->send)(['citizens' => [$stale, $fresh]])->assertOk();
    $rows = ($this->roster)()->json('hosts.0.citizens');
    expect($rows[0]['cpu_percent'])->toBeNull();
    expect($rows[1]['cpu_percent'])->toEqual($fresh['cpu_percent']);
    expect($rows[1]['rss_bytes'])->toEqual($fresh['rss_bytes']);
});

it('integrates private owner roster and exactly once identity bound dispatch and receipts', function () {
    ($this->send)()->assertExactJson(['accepted' => true, 'commands' => []]);
    $roster = ($this->roster)()->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('primary', true);
    expect($roster->json('hosts.0.citizens.0.can_terminate'))->toBeTrue();
    $action = ($this->kill)()->assertCreated()->json('action');
    expect($action['status'])->toBe('requested');
    ($this->kill)()->assertConflict();
    $command = ($this->send)(['sequence' => 2])->assertOk()->json('commands.0');
    expect($command)->toMatchArray(['id' => $action['id'], 'citizen_id' => str_repeat('a', 64), 'pid' => 4321, 'start_ticks' => 12345]);
    expect(array_keys($command))->toBe(['id', 'citizen_id', 'boot_id', 'bridge_id', 'pid', 'start_ticks', 'expires_at']);
    ($this->send)(['sequence' => 3])->assertExactJson(['accepted' => true, 'commands' => []]);
    ($this->send)(['sequence' => 4, 'results' => [['id' => $action['id'], 'status' => 'terminated']]])->assertOk();
    ($this->roster)()->assertJsonPath('hosts.0.citizens.0.action', ['id' => $action['id'], 'status' => 'terminated']);
    ($this->send)(['sequence' => 5, 'results' => [['id' => $action['id'], 'status' => 'terminated']]])->assertOk();
    ($this->send)(['sequence' => 6, 'results' => [['id' => $action['id'], 'status' => 'permission_denied']]])->assertConflict();
});

it('preserves real owner authentication CSRF and collector machine binding', function () {
    $this->getJson('/api/v1/citizens')->assertUnauthorized();
    $this->postJson('/api/v1/citizens/'.$this->target->id.'/'.str_repeat('a', 64).'/terminate', ['revision' => str_repeat('a', 64), 'confirmation' => true])->assertUnauthorized();
    $this->postJson($this->collectorPath($this->target, 'citizens/challenge'), [], [], JSON_FORCE_OBJECT)->assertUnauthorized();
    $this->withToken($this->token)->withHeader('X-Machine-ID', '99999999-9999-4999-8999-999999999999')->postJson($this->collectorPath($this->target, 'citizens/challenge'), [], [], JSON_FORCE_OBJECT)->assertUnauthorized();
    $this->flushHeaders();
    ($this->send)()->assertOk();
    ($this->roster)()->assertOk();
    ($this->edge)(['NEBULESS_TAILSCALE_INGRESS' => null])->getJson('/api/v1/citizens')->assertUnauthorized();
    ($this->roster)()->assertOk();
    $this->app->detectEnvironment(fn () => 'local');
    ($this->kill)()->assertStatus(419);
    expect(DB::table('citizen_actions')->count())->toBe(0);
});

it('rejects private extras invalid enums identities and bounded payloads without consuming nonce', function (string $case) {
    $body = citizensEnvelope();
    match ($case) {
        'secret' => $body['citizens'][0]['prompt'] = 'secret-canary',
        'process secret' => $body['citizens'][0]['process']['argv'] = 'secret-canary',
        'top secret' => $body['cwd'] = 'secret-canary',
        'state' => $body['citizens'][0]['state'] = 'thinking',
        'tool' => $body['citizens'][0]['tool'] = 'other',
        'coverage' => $body['coverage'] = 'ready',
        'attribution' => $body['citizens'][0]['attribution'] = 'guessed',
        'cpu' => $body['citizens'][0]['cpu_percent'] = -1,
        'infinite' => $body['citizens'][0]['cpu_percent'] = '1e999',
        'rss' => $body['citizens'][0]['rss_bytes'] = -1,
        'pid' => $body['citizens'][0]['process']['pid'] = 0,
        'ticks' => $body['citizens'][0]['process']['start_ticks'] = 0,
        'boot' => $body['citizens'][0]['process']['boot_id'] = '44444444-4444-4444-8444-444444444444',
        'future row' => $body['citizens'][0]['observed_at'] = now()->addMicrosecond()->toISOString(),
        'duplicate' => $body['citizens'][] = $body['citizens'][0],
        'citizen cap' => $body['citizens'] = array_fill(0, 129, $body['citizens'][0]),
        'result cap' => $body['results'] = array_fill(0, 65, ['id' => '44444444-4444-4444-8444-444444444444', 'status' => 'expired']),
        'bytes' => $body['secret'] = str_repeat('x', 262144),
    };
    $nonce = $this->withToken($this->token)->postJson($this->collectorPath($this->target, 'citizens/challenge'), [], [], JSON_FORCE_OBJECT)->assertCreated()->json('nonce');
    $response = ($this->send)($body, $nonce);
    $response->assertStatus($case === 'bytes' ? 413 : 422);
    expect(DB::table('citizen_contacts')->count())->toBe(0)->and(DB::table('citizen_challenges')->value('consumed_at'))->toBeNull();
    ($this->send)([], $nonce)->assertOk();
})->with(['secret', 'process secret', 'top secret', 'state', 'tool', 'coverage', 'attribution', 'cpu', 'infinite', 'rss', 'pid', 'ticks', 'boot', 'future row', 'duplicate', 'citizen cap', 'result cap', 'bytes']);

it('rejects sample future and stale times without rounding fractional timestamps', function () {
    ($this->send)(['sampled_at' => now()->addMicrosecond()->toISOString()])->assertConflict();
    ($this->send)(['sampled_at' => now()->subSeconds(10)->subMicrosecond()->toISOString(), 'citizens' => []])->assertConflict();
    ($this->send)(['sampled_at' => now()->subSeconds(10)->toISOString(), 'citizens' => []])->assertOk();
    ($this->send)(['sequence' => 2])->assertOk();
    ($this->roster)()->assertJsonPath('hosts.0.citizens.0.can_terminate', true);
    $this->travel(10)->seconds();
    ($this->roster)()->assertJsonPath('hosts.0.citizens.0.can_terminate', true);
    $this->travel(1)->microseconds();
    ($this->roster)()->assertJsonPath('hosts.0.coverage', 'unavailable')->assertJsonPath('hosts.0.citizens.0.cpu_percent', null)->assertJsonPath('hosts.0.citizens.0.rss_bytes', null)->assertJsonPath('hosts.0.citizens.0.can_terminate', false);
    ($this->kill)()->assertConflict();
});

it('enforces nonce single use strict expiry and retired bridge and boot rejection', function () {
    $nonce = $this->withToken($this->token)->postJson($this->collectorPath($this->target, 'citizens/challenge'), [], [], JSON_FORCE_OBJECT)->json('nonce');
    ($this->send)([], $nonce)->assertOk();
    ($this->send)(['sequence' => 2], $nonce)->assertConflict();
    ($this->send)()->assertConflict();
    $nonce = $this->withToken($this->token)->postJson($this->collectorPath($this->target, 'citizens/challenge'), [], [], JSON_FORCE_OBJECT)->json('nonce');
    $this->travel(10)->seconds();
    ($this->send)(['sequence' => 2], $nonce)->assertConflict();
    $next = '44444444-4444-4444-8444-444444444444';
    ($this->send)(['bridge_id' => $next])->assertOk();
    ($this->send)(['sequence' => 99])->assertConflict();
    ($this->send)(['bridge_id' => '55555555-5555-4555-8555-555555555555', 'boot_id' => $next, 'citizens' => []])->assertOk();
    ($this->send)(['bridge_id' => '66666666-6666-4666-8666-666666666666', 'sequence' => 100])->assertConflict();
});

it('masks partial unavailable missing and stale observations and distinguishes valid empty', function () {
    ($this->send)()->assertOk();
    ($this->send)(['sequence' => 2, 'coverage' => 'partial', 'citizens' => []])->assertOk();
    ($this->roster)()->assertJsonPath('hosts.0.coverage', 'partial')->assertJsonPath('hosts.0.citizens.0.state', 'unknown')->assertJsonPath('hosts.0.citizens.0.can_terminate', false);
    ($this->send)(['sequence' => 3, 'coverage' => 'unavailable', 'citizens' => []])->assertOk();
    ($this->roster)()->assertJsonPath('hosts.0.coverage', 'unavailable')->assertJsonPath('hosts.0.citizens.0.rss_bytes', null);
    ($this->send)(['sequence' => 4, 'citizens' => []])->assertOk();
    ($this->roster)()->assertJsonPath('hosts.0.coverage', 'complete')->assertJsonCount(0, 'hosts.0.citizens');
    ($this->send)(['sequence' => 5, 'citizens' => [citizenFixture(['observed_at' => now()->subSeconds(11)->toISOString()])]])->assertOk();
    ($this->roster)()->assertJsonPath('hosts.0.citizens.0.state', 'unknown')->assertJsonPath('hosts.0.citizens.0.can_terminate', false);
});

it('refuses shared controls and does not double count runtime or process resources', function () {
    $rows = [citizenFixture(['attribution' => 'shared', 'can_terminate' => false, 'cpu_percent' => 50]), citizenFixture(['id' => str_repeat('c', 64), 'attribution' => 'shared', 'can_terminate' => false, 'cpu_percent' => 50])];
    ($this->send)(['citizens' => $rows])->assertOk();
    $citizens = ($this->roster)()->json('hosts.0.citizens');
    expect(array_sum(array_column($citizens, 'rss_bytes')))->toBe(4096)->and(array_sum(array_column($citizens, 'cpu_percent')))->toBe(50);
    ($this->kill)()->assertConflict();
    $rows = [citizenFixture(), citizenFixture(['id' => str_repeat('c', 64), 'runtime_id' => str_repeat('d', 64)])];
    ($this->send)(['sequence' => 2, 'citizens' => $rows])->assertOk();
    ($this->roster)()->assertJsonPath('hosts.0.citizens.0.attribution', 'shared')->assertJsonPath('hosts.0.citizens.0.can_terminate', false);
    ($this->kill)()->assertConflict();
});

it('requires primary exact current revision confirmation and current capability', function () {
    ($this->send)()->assertOk();
    $revision = ($this->roster)()->json('hosts.0.citizens.0.revision');
    ($this->kill)($revision, ['confirmation' => false])->assertUnprocessable();
    ($this->kill)($revision, ['pid' => 123])->assertUnprocessable();
    ($this->kill)(str_repeat('f', 64))->assertConflict();
    ($this->send)(['sequence' => 2, 'citizens' => [citizenFixture(['attribution' => 'unavailable', 'process' => null, 'can_terminate' => false])]])->assertOk();
    ($this->kill)($revision)->assertConflict();
    HubIdentity::find(1)->update(['role' => 'standby']);
    ($this->roster)()->assertJsonPath('primary', false)->assertJsonPath('hosts.0.citizens.0.can_terminate', false);
    ($this->kill)()->assertForbidden();
    ($this->send)(['sequence' => 3])->assertExactJson(['accepted' => true, 'commands' => []]);
    expect(DB::table('citizen_actions')->count())->toBe(0);
});

it('expires disconnected unissued actions but never retries dispatched unknown outcomes', function () {
    ($this->send)()->assertOk();
    $first = ($this->kill)()->assertCreated()->json('action.id');
    $this->travel(5)->seconds();
    ($this->send)(['sequence' => 2])->assertJsonCount(0, 'commands');
    ($this->roster)()->assertJsonPath('hosts.0.citizens.0.action.status', 'expired');
    expect(DB::table('citizen_actions')->where('id', $first)->value('dispatched_at'))->toBeNull();
    $second = ($this->kill)()->assertCreated()->json('action.id');
    ($this->send)(['sequence' => 3])->assertJsonPath('commands.0.id', $second);
    $this->travel(5)->seconds();
    ($this->send)(['sequence' => 4])->assertJsonCount(0, 'commands');
    ($this->roster)()->assertJsonPath('hosts.0.citizens.0.action.status', 'unknown');
    ($this->kill)()->assertConflict();
    ($this->send)(['sequence' => 5, 'results' => [['id' => $second, 'status' => 'unknown']]])->assertOk();
    ($this->kill)()->assertConflict();
});

it('denies old bridge commands and receipts and validates result subsets atomically', function () {
    ($this->send)()->assertOk();
    $id = ($this->kill)()->assertCreated()->json('action.id');
    ($this->send)(['sequence' => 2, 'results' => [['id' => $id, 'status' => 'terminated']]])->assertConflict();
    ($this->send)(['sequence' => 2])->assertJsonPath('commands.0.id', $id);
    ($this->send)(['sequence' => 3, 'results' => [['id' => '99999999-9999-4999-8999-999999999999', 'status' => 'terminated']]])->assertConflict();
    expect(DB::table('citizen_contacts')->value('sequence'))->toBe(2);
    ($this->send)(['bridge_id' => '44444444-4444-4444-8444-444444444444', 'results' => [['id' => $id, 'status' => 'terminated']]])->assertConflict();
    ($this->send)(['bridge_id' => '44444444-4444-4444-8444-444444444444'])->assertJsonCount(0, 'commands');
    ($this->roster)()->assertJsonPath('hosts.0.citizens.0.action.status', 'unknown');
});

it('rolls back nonce inventory receipts and dispatch together on SQLite write failure', function () {
    ($this->send)()->assertOk();
    ($this->kill)()->assertCreated();
    DB::unprepared("CREATE TRIGGER fail_citizen_dispatch BEFORE UPDATE OF dispatched_at ON citizen_actions BEGIN SELECT RAISE(ABORT, 'private canary'); END");
    $nonce = $this->withToken($this->token)->postJson($this->collectorPath($this->target, 'citizens/challenge'), [], [], JSON_FORCE_OBJECT)->json('nonce');
    ($this->send)(['sequence' => 2], $nonce)->assertStatus(503)->assertExactJson(['error' => 'citizens_unavailable']);
    expect(DB::table('citizen_contacts')->value('sequence'))->toBe(1)->and(DB::table('citizen_challenges')->value('consumed_at'))->toBeNull()->and(DB::table('citizen_actions')->value('dispatched_at'))->toBeNull();
    DB::unprepared('DROP TRIGGER fail_citizen_dispatch');
    ($this->send)(['sequence' => 2], $nonce)->assertJsonCount(1, 'commands');
});

it('prunes four hour activity inclusively without losing action or replay safety records', function () {
    ($this->send)()->assertOk();
    ($this->kill)()->assertCreated();
    ($this->send)(['sequence' => 2])->assertJsonCount(1, 'commands');
    $this->travel(4)->hours();
    app(CitizensState::class)->prune();
    expect(DB::table('citizen_observations')->count())->toBe(1);
    $this->travel(1)->microseconds();
    app(CitizensState::class)->prune();
    expect(DB::table('citizen_observations')->count())->toBe(0)->and(DB::table('citizen_actions')->count())->toBe(1)->and(DB::table('citizen_incarnations')->count())->toBe(1);
    ($this->roster)()->assertJsonCount(0, 'hosts.0.citizens');
});

it('serializes competing owner requests and nonce exchanges across independent SQLite connections', function () {
    ($this->send)()->assertOk();
    $revision = ($this->roster)()->json('hosts.0.citizens.0.revision');
    $race = function (Closure $operation): array {
        // Close inherited PDO before fork; each worker opens its own WAL connection.
        DB::purge();
        $workers = [];
        foreach (range(1, 2) as $unused) {
            [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Cannot fork SQLite race worker.');
            }
            if ($pid === 0) {
                fclose($parent);
                stream_set_timeout($child, 10);
                fwrite($child, "ready\n");
                if (fgets($child) !== "go\n") {
                    exit(2);
                }
                try {
                    $result = ['accepted' => true, 'result' => $operation()];
                } catch (TelemetryException $error) {
                    $result = ['accepted' => false, 'error' => $error->errorCode];
                } catch (Throwable $error) {
                    $result = ['accepted' => false, 'unexpected' => $error->getMessage()];
                }
                fwrite($child, json_encode($result, JSON_THROW_ON_ERROR)."\n");
                fclose($child);
                exit(0);
            }
            fclose($child);
            stream_set_timeout($parent, 10);
            expect(fgets($parent))->toBe("ready\n");
            $workers[] = [$pid, $parent];
        }
        foreach ($workers as [$pid, $socket]) {
            fwrite($socket, "go\n");
        }
        $results = [];
        foreach ($workers as [$pid, $socket]) {
            $results[] = json_decode(fgets($socket), true, flags: JSON_THROW_ON_ERROR);
            fclose($socket);
            pcntl_waitpid($pid, $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        }

        return $results;
    };
    $requests = $race(fn () => app(CitizensState::class)->terminate($this->target, str_repeat('a', 64), $revision));
    foreach ($requests as $result) {
        expect($result['unexpected'] ?? null)->toBeNull();
    }
    expect(array_column($requests, 'accepted'))->toContain(true, false)->and(DB::table('citizen_actions')->count())->toBe(1);
    $nonce = $this->withToken($this->token)->postJson($this->collectorPath($this->target, 'citizens/challenge'), [], [], JSON_FORCE_OBJECT)->json('nonce');
    $body = app(ValidateCitizens::class)->exchange(json_decode(json_encode(['nonce' => $nonce] + citizensEnvelope(['sequence' => 2]), JSON_THROW_ON_ERROR)));
    $exchanges = $race(fn () => app(CitizensState::class)->exchange($this->target, $body));
    expect(array_column($exchanges, 'accepted'))->toContain(true, false)->and(DB::table('citizen_contacts')->value('sequence'))->toBe(2);
    $commands = array_merge(...array_map(fn ($result) => $result['result']['commands'] ?? [], $exchanges));
    expect($commands)->toHaveCount(1)->and(DB::table('citizen_actions')->whereNotNull('dispatched_at')->count())->toBe(1);
});

it('denies pending commands after boot change', function () {
    ($this->send)()->assertOk();
    $id = ($this->kill)()->assertCreated()->json('action.id');
    ($this->send)(['boot_id' => '77777777-7777-4777-8777-777777777777', 'bridge_id' => '88888888-8888-4888-8888-888888888888', 'citizens' => []])->assertJsonCount(0, 'commands');
    expect(DB::table('citizen_actions')->where('id', $id)->value('status'))->toBe('expired');
    ($this->send)(['sequence' => 3])->assertConflict();
});

it('rejects another machine receipt and malformed challenge bodies', function () {
    foreach (['[]', '{"prompt":"secret-canary"}', 'null', '{'] as $body) {
        $this->call('POST', $this->collectorPath($this->target, 'citizens/challenge'), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: $body)->assertUnprocessable();
    }
    ($this->send)()->assertOk();
    $id = ($this->kill)()->assertCreated()->json('action.id');
    ($this->send)(['sequence' => 2])->assertJsonPath('commands.0.id', $id);
    $other = Machine::create(['name' => 'Other', 'data_policy' => Machine::HEALTH_PROCESSES]);
    $token = $other->rotateToken();
    $nonce = $this->withToken($token)->postJson($this->collectorPath($other, 'citizens/challenge'), [], [], JSON_FORCE_OBJECT)->assertCreated()->json('nonce');
    $this->withToken($token)->postJson($this->collectorPath($other, 'citizens'), ['nonce' => $nonce] + citizensEnvelope(['results' => [['id' => $id, 'status' => 'terminated']]]))->assertConflict();
    expect(DB::table('citizen_actions')->where('id', $id)->value('result_at'))->toBeNull();
});

it('refuses unproven unsupported stale or terminal citizens', function (array $changes) {
    ($this->send)(['citizens' => [citizenFixture($changes)]])->assertOk();
    ($this->roster)()->assertJsonPath('hosts.0.citizens.0.can_terminate', false);
    ($this->kill)()->assertConflict();
})->with([
    'unsupported' => [['can_terminate' => false]],
    'no identity' => [['process' => null]],
    'unavailable attribution' => [['attribution' => 'unavailable']],
    'unknown state' => [['state' => 'unknown']],
    'stopped' => [['state' => 'stopped']],
    'failed' => [['state' => 'failed']],
]);

it('does not dispatch a reused PID or dispatch queued work on standby', function () {
    ($this->send)()->assertOk();
    $id = ($this->kill)()->assertCreated()->json('action.id');
    $row = citizenFixture();
    $row['process']['start_ticks']++;
    ($this->send)(['sequence' => 2, 'citizens' => [$row]])->assertJsonCount(0, 'commands');
    expect(DB::table('citizen_actions')->where('id', $id)->value('status'))->toBe('expired');
    $id = ($this->kill)()->assertCreated()->json('action.id');
    ($this->roster)()->assertJsonPath('hosts.0.citizens.0.action.id', $id);
    HubIdentity::find(1)->update(['role' => 'standby']);
    ($this->send)(['sequence' => 3, 'citizens' => [$row]])->assertJsonCount(0, 'commands');
    expect(DB::table('citizen_actions')->where('id', $id)->value('dispatched_at'))->toBeNull();
});

it('records only enumerated factual outcomes without automatically repeating a command', function (string $status) {
    ($this->send)()->assertOk();
    $id = ($this->kill)()->assertCreated()->json('action.id');
    ($this->send)(['sequence' => 2])->assertJsonCount(1, 'commands');
    ($this->send)(['sequence' => 3, 'results' => [['id' => $id, 'status' => $status]]])->assertExactJson(['accepted' => true, 'commands' => []]);
    ($this->roster)()->assertJsonPath('hosts.0.citizens.0.action.status', $status);
    expect(DB::table('citizen_actions')->where('id', $id)->value('result_at'))->not->toBeNull();
})->with(['terminated', 'already_exited', 'identity_changed', 'permission_denied', 'unsupported', 'expired', 'unknown']);
