<?php

use App\Models\HubIdentity;
use App\Models\Machine;
use App\ReadModels\RebootActions;
use Illuminate\Support\Facades\DB;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\TelemetryDatabase;

uses(TelemetryDatabase::class);

beforeEach(function () {
    $this->prepareTelemetryDatabase();
    HubIdentity::create(['id' => 1, 'hub_uuid' => '33333333-3333-4333-8333-333333333333', 'name' => 'Primary-Hub', 'role' => 'primary', 'peers' => []]);
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_ED25519]);
    openssl_pkey_export($key, $pem);
    config(['actions.private_key' => $pem, 'actions.key_id' => 'primary-test']);
    [$this->target, $this->collectorToken] = $this->telemetryMachine();
    $this->freshContact($this->target, $this->collectorToken, $this->telemetryReport())->assertOk();
    $this->target->forceFill(['reboot_enabled' => true])->save();
    $this->boot = $this->telemetryReport()['boot_id'];
    $this->withToken($this->collectorToken)->postJson($this->collectorPath($this->target, 'actions/pickup'), ['boot_id' => $this->boot, 'available' => true])->assertOk();
    [$this->owner, $this->enrollment] = $this->signInBetterOwner($this->provisionBetterOwner('reboot-owner@example.test'));
    $this->withHeader('Authorization', '');
    $this->proof = fn () => $this->withCredentials()->withCookie(config('session.cookie'), session()->getId())->postJson('/owner/action-confirmation', ['target' => $this->target->id, 'password' => 'correct-password', 'code' => $this->betterTotp($this->enrollment['totpURI'])])->assertCreated()->json('proof');
    $this->queue = fn ($proof) => $this->withCookie(config('session.cookie'), session()->getId())->withHeader('Authorization', '')->postJson('/api/v1/hosts/'.$this->target->id.'/reboot', ['proof' => $proof]);
    $this->exchange = fn ($id, $operation, $body = []) => $this->withToken($this->collectorToken)->postJson($this->collectorPath($this->target, 'actions/'.$id.'/'.$operation), ['boot_id' => $this->boot] + $body);
});

it('queues once and consumes real provider proof in the queue transaction', function () {
    $proof = ($this->proof)();
    $action = ($this->queue)($proof)->assertCreated()->json('action');
    expect($action['state'])->toBe('queued')->and($action['expires_at'])->toBe(now()->addHours(6)->toIso8601String());
    ($this->queue)($proof)->assertUnprocessable();
    ($this->queue)(($this->proof)())->assertCreated()->assertJsonPath('action.id', $action['id']);
    expect(DB::table('reboot_actions')->count())->toBe(1)->and(DB::table('action_confirmation_proofs')->whereNull('consumed_at')->count())->toBe(0);
});

it('starts the cancel window once and a committed cancel prevents final authority', function () {
    $action = ($this->queue)(($this->proof)())->assertCreated()->json('action');
    ($this->exchange)($action['id'], 'ack')->assertOk();
    $deadline = DB::table('reboot_actions')->value('cancel_until');
    $this->travel(30)->seconds();
    ($this->exchange)($action['id'], 'ack')->assertOk();
    expect(DB::table('reboot_actions')->value('cancel_until'))->toBe($deadline);
    ($this->exchange)($action['id'], 'permit', ['nonce' => str_repeat('a', 64)])->assertConflict();
    $this->withHeader('Authorization', '')->postJson('/api/v1/hosts/'.$this->target->id.'/reboots/'.$action['id'].'/cancel')->assertOk();
    $this->travel(31)->seconds();
    ($this->exchange)($action['id'], 'permit', ['nonce' => str_repeat('b', 64)])->assertConflict();
    expect(DB::table('reboot_actions')->value('state'))->toBe('canceled');
});

it('makes authorization the sole winner and holds an uncertain outcome until safe expiry', function () {
    $action = ($this->queue)(($this->proof)())->assertCreated()->json('action');
    ($this->exchange)($action['id'], 'ack')->assertOk();
    $this->travel(60)->seconds();
    $permit = ($this->exchange)($action['id'], 'permit', ['nonce' => str_repeat('a', 64)])->assertOk()->json();
    $payload = json_decode(base64_decode($permit['payload']), true, flags: JSON_THROW_ON_ERROR);
    expect($payload['action_id'])->toBe($action['id'])->and($payload['boot_id'])->toBe($this->boot)->and($payload['expires_at'] - $payload['issued_at'])->toBe(10);
    $this->withHeader('Authorization', '')->postJson('/api/v1/hosts/'.$this->target->id.'/reboots/'.$action['id'].'/cancel')->assertConflict()->assertJsonPath('error', 'too_late_to_cancel');
    ($this->exchange)($action['id'], 'permit', ['nonce' => str_repeat('b', 64)])->assertOk();
    ($this->exchange)($action['id'], 'result', ['status' => 'unknown'])->assertOk();
    expect(DB::table('reboot_actions')->value('state'))->toBe('authorized');
    ($this->exchange)($action['id'], 'permit', ['nonce' => str_repeat('c', 64)])->assertConflict();
    $this->travel(6)->hours();
    $this->withHeader('Authorization', '')->getJson('/api/v1/hosts/'.$this->target->id.'/actions')->assertOk()->assertJsonPath('latest.state', 'unknown');
});

it('never extends absolute expiry for a pickup with insufficient cancel time', function () {
    $action = ($this->queue)(($this->proof)())->assertCreated()->json('action');
    $this->travel(21570)->seconds();
    ($this->exchange)($action['id'], 'ack')->assertOk();
    $this->travel(31)->seconds();
    ($this->exchange)($action['id'], 'permit', ['nonce' => str_repeat('a', 64)])->assertConflict();
    expect(DB::table('reboot_actions')->value('expires_at'))->toBe($action['expires_at']);
    $this->withHeader('Authorization', '')->getJson('/api/v1/hosts/'.$this->target->id.'/actions')->assertOk()->assertJsonPath('latest.state', 'expired');
});

it('refuses Primary, missing capability, standby mutations and altered boot', function () {
    $proof = ($this->proof)();
    $this->target->forceFill(['reboot_enabled' => false])->save();
    ($this->queue)($proof)->assertConflict();
    $this->target->forceFill(['reboot_enabled' => true, 'name' => 'Primary-Hub'])->save();
    ($this->queue)($proof)->assertConflict();
    $this->target->forceFill(['name' => 'Alpha-Hub'])->save();
    HubIdentity::find(1)->update(['role' => 'standby']);
    ($this->queue)($proof)->assertForbidden();
    $this->withToken($this->collectorToken)->postJson($this->collectorPath($this->target, 'actions/pickup'), ['boot_id' => $this->boot, 'available' => true])->assertForbidden();
    HubIdentity::find(1)->update(['role' => 'primary']);
    $action = ($this->queue)($proof)->assertCreated()->json('action');
    ($this->exchange)($action['id'], 'ack')->assertOk();
    HubIdentity::find(1)->update(['role' => 'standby']);
    $this->withHeader('Authorization', '')->postJson('/api/v1/hosts/'.$this->target->id.'/reboots/'.$action['id'].'/cancel')->assertForbidden();
    $this->travel(60)->seconds();
    ($this->exchange)($action['id'], 'permit', ['nonce' => str_repeat('a', 64)])->assertForbidden();
    HubIdentity::find(1)->update(['role' => 'primary']);
    DB::table('telemetry_contacts')->where('machine_id', $this->target->id)->update(['boot_id' => '44444444-4444-4444-8444-444444444444']);
    ($this->exchange)($action['id'], 'permit', ['nonce' => str_repeat('a', 64)])->assertConflict();
});

it('queues through the real Livewire dialog action and displays identical API state', function () {
    $page = $this->withCredentials()->withCookie(config('session.cookie'), session()->getId())->get('/hosts/'.$this->target->id)->assertOk();
    $page->assertSee('Confirm reboot');
    preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
    $snapshot = html_entity_decode($matches[1], ENT_QUOTES);
    $update = $this->withHeader('X-Livewire', 'true')->postJson(app(HandleRequests::class)->getUpdateUri(), [
        'components' => [['snapshot' => $snapshot, 'updates' => (object) [], 'calls' => [['method' => 'queueReboot', 'params' => ['correct-password', $this->betterTotp($this->enrollment['totpURI'])], 'path' => '']]]],
    ])->assertOk();
    expect(DB::table('reboot_actions')->count())->toBe(1);
    $this->getJson('/api/v1/hosts/'.$this->target->id.'/actions')->assertOk()->assertJsonPath('latest.state', 'queued');
    $this->getJson('/api/v1/fleet')->assertOk()->assertJsonPath('hosts.0.actions.latest.state', 'queued');
    $this->get('/')->assertOk()->assertSee('id="pending-host-actions"', false);
});

it('serializes concurrent queues and cancel versus authorize across real SQLite connections', function () {
    $proofA = ($this->proof)();
    $proofB = ($this->proof)();
    $database = DB::selectOne('PRAGMA database_list')->file;
    $base = [
        'environment' => dirname($database), 'database' => $database, 'private_key' => config('actions.private_key'),
        'auth_config' => config('better-auth'), 'now' => now()->toIso8601String(), 'machine' => $this->target->id,
        'session_id' => session()->getId(), 'session' => session()->all(), 'owner' => $this->owner->id,
        'boot' => $this->boot, 'action' => null,
    ];
    $race = function (array $operations) use ($base): array {
        $processes = [];
        $inputs = [];
        foreach ($operations as $operation) {
            $process = new Process([dirname(__DIR__, 4).'/bin/php', dirname(__DIR__).'/Support/reboot-race-worker.php']);
            $input = new InputStream;
            $input->write(json_encode(array_replace($base, $operation), JSON_THROW_ON_ERROR)."\n");
            $process->setInput($input);
            $process->setTimeout(60);
            $process->start();
            expect($process->waitUntil(fn ($type, $output) => str_contains($output, "ready\n")))->toBeTrue();
            $processes[] = $process;
            $inputs[] = $input;
        }
        foreach ($inputs as $index => $input) {
            $input->write("go\n");
            $input->close();
            $processes[$index]->isRunning();
        }
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue();
            $results[] = json_decode(substr($process->getOutput(), strlen("ready\n")), true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    };
    $queued = $race([['operation' => 'queue', 'proof' => $proofA], ['operation' => 'queue', 'proof' => $proofB]]);
    expect($queued[0]['id'])->toBe($queued[1]['id'])->and(DB::table('reboot_actions')->count())->toBe(1);
    $id = $queued[0]['id'];
    ($this->exchange)($id, 'ack')->assertOk();
    $this->travel(60)->seconds();
    $results = $race([['operation' => 'cancel', 'action' => $id, 'now' => now()->toIso8601String()], ['operation' => 'authorize', 'action' => $id, 'now' => now()->toIso8601String()]]);
    expect(count(array_filter($results, fn ($result) => $result['won'])))->toBe(1);
    expect(DB::table('reboot_actions')->value('state'))->toBe($results[0]['won'] ? 'canceled' : 'authorized');
});

it('reads action evidence during an independent WAL writer without taking a writer lock', function () {
    $database = DB::selectOne('PRAGMA database_list')->file;
    $writer = new PDO('sqlite:'.$database);
    $writer->exec('BEGIN IMMEDIATE');
    try {
        $started = microtime(true);
        $model = app(RebootActions::class)->host($this->target);
        expect($model['capability']['available'])->toBeTrue()->and(microtime(true) - $started)->toBeLessThan(1.0);
    } finally {
        $writer->exec('ROLLBACK');
    }
});

it('rejects another target proof and keeps unused confirmation available after refused queue', function () {
    $other = Machine::create(['name' => 'Beta-Restricted', 'data_policy' => 'health_processes']);
    $proof = $this->withCredentials()->withCookie(config('session.cookie'), session()->getId())->postJson('/owner/action-confirmation', ['target' => $other->id, 'password' => 'correct-password', 'code' => $this->betterTotp($this->enrollment['totpURI'])])->assertCreated()->json('proof');
    ($this->queue)($proof)->assertUnprocessable();
    expect(DB::table('reboot_actions')->count())->toBe(0)->and(DB::table('action_confirmation_proofs')->whereNull('consumed_at')->count())->toBe(1);
    DB::table('telemetry_contacts')->where('machine_id', $this->target->id)->delete();
    ($this->queue)(($this->proof)())->assertConflict();
    expect(DB::table('action_confirmation_proofs')->whereNull('consumed_at')->count())->toBe(2);
});

it('observes a new boot only from fresh committed contact and never claims causation', function () {
    $action = ($this->queue)(($this->proof)())->assertCreated()->json('action');
    ($this->exchange)($action['id'], 'ack')->assertOk();
    $this->travel(60)->seconds();
    ($this->exchange)($action['id'], 'permit', ['nonce' => str_repeat('a', 64)])->assertOk();
    ($this->exchange)($action['id'], 'result', ['status' => 'unknown'])->assertOk();
    $new = $this->telemetryReport(1, 'next-stream', '55555555-5555-4555-8555-555555555555');
    $this->withToken($this->collectorToken)->postJson($this->collectorPath($this->target, 'reports'), ['reports' => [$new]])->assertOk();
    expect(DB::table('reboot_actions')->value('state'))->toBe('authorized');
    $new['sequence'] = 2;
    $this->freshContact($this->target, $this->collectorToken, $new)->assertOk();
    expect(DB::table('reboot_actions')->value('state'))->toBe('new_boot_observed');
    $this->withHeader('Authorization', '')->get('/hosts/'.$this->target->id)->assertOk()->assertSee('This does not prove the queued reboot caused it.');
});
