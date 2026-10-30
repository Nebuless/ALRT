<?php

use App\Actions\RebootState;
use App\History\AcceptedIntervals;
use App\History\ReadTimeline;
use App\History\Retention;
use App\Jobs\PruneHistory;
use App\Models\HubIdentity;
use App\ReadModels\FleetSummary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\TelemetryDatabase;

uses(TelemetryDatabase::class);
beforeEach(function () {
    $this->prepareTelemetryDatabase();
    $this->travelTo(Carbon::parse('2026-10-20T00:00:00Z'));
});

it('preserves a five hour silent boot and six hour action through redaction without renewing authority', function () {
    [$machine, $token] = $this->telemetryMachine();
    HubIdentity::create(['id' => 1, 'hub_uuid' => '33333333-3333-4333-8333-333333333333', 'name' => 'Primary-Hub', 'role' => 'primary', 'peers' => []]);
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_ED25519]);
    openssl_pkey_export($key, $pem);
    config(['actions.private_key' => $pem, 'actions.key_id' => 'retention-test']);
    $machine->forceFill(['reboot_enabled' => true])->save();
    $report = $this->telemetryReport();
    $report['sampled_at'] = now()->toIso8601String();
    $this->freshContact($machine, $token, $report)->assertOk();
    $boot = $report['boot_id'];
    $this->withToken($token)->postJson($this->collectorPath($machine, 'actions/pickup'), ['boot_id' => $boot, 'available' => true])->assertOk();
    // Queue/proof consumption is covered by RebootLifecycleTest; seed only the committed queued fixture.
    $id = '66666666-6666-4666-8666-666666666666';
    DB::table('reboot_actions')->insert(['id' => $id, 'machine_id' => $machine->id, 'boot_id' => $boot,
        'state' => 'queued', 'queued_at' => now()->toIso8601String(), 'expires_at' => now()->addHours(6)->toIso8601String()]);
    $contact = (array) DB::table('telemetry_contacts')->sole();
    $capability = DB::table('reboot_capabilities')->sole();
    $action = DB::table('reboot_actions')->sole();
    $boots = DB::table('telemetry_boots')->get()->all();
    $exchange = fn ($operation, $body = []) => $this->withToken($token)->postJson($this->collectorPath($machine, 'actions/'.$id.'/'.$operation), $body + ['boot_id' => $boot]);

    $this->travel(5)->hours();
    expect(app(FleetSummary::class)->host($machine)['contact_age_seconds'])->toBe(18000);
    app(PruneHistory::class)->handle();
    DB::purge('sqlite');
    expect((array) DB::table('telemetry_contacts')->sole())->toEqual(array_replace($contact, ['payload' => json_encode(Retention::unavailable(), JSON_THROW_ON_ERROR)]))
        ->and(DB::table('telemetry_boots')->get()->all())->toEqual($boots)
        ->and(DB::table('reboot_capabilities')->sole())->toEqual($capability)
        ->and(DB::table('reboot_actions')->sole())->toEqual($action)
        ->and(DB::table('telemetry_samples')->count())->toBe(0)
        ->and(app(RebootState::class)->capability($machine)['available'])->toBeTrue();
    $host = app(FleetSummary::class)->host($machine);
    expect($host['metrics'])->toBeNull()->and($host['processes'])->toBeNull()
        ->and($host['actions']['latest']['state'])->toBe('queued')
        ->and(Carbon::parse($action->expires_at)->diffInSeconds(now(), true))->toBe(3600.0);

    $exchange('ack', ['boot_id' => '77777777-7777-4777-8777-777777777777'])->assertConflict();
    $this->withToken($token)->postJson($this->collectorPath($machine, 'actions/pickup'), ['boot_id' => $boot, 'available' => true])->assertOk()->assertJsonPath('action.id', $id);
    $exchange('ack')->assertOk();
    $picked = DB::table('reboot_actions')->sole();
    $this->travel(60)->seconds();
    $exchange('ack')->assertOk();
    expect(DB::table('reboot_actions')->value('cancel_until'))->toBe($picked->cancel_until);
    $exchange('permit', ['nonce' => str_repeat('a', 64), 'boot_id' => '77777777-7777-4777-8777-777777777777'])->assertConflict();
    $envelope = $exchange('permit', ['nonce' => str_repeat('a', 64)])->assertOk()->json();
    $permit = json_decode(base64_decode($envelope['payload']), true, flags: JSON_THROW_ON_ERROR);
    expect($permit['action_id'])->toBe($id)->and($permit['boot_id'])->toBe($boot)
        ->and($permit['action_expires_at'])->toBe(Carbon::parse($action->expires_at)->getTimestamp());
    $authorized = DB::table('reboot_actions')->value('authorized_at');
    $this->travel(1)->seconds();
    $exchange('permit', ['nonce' => str_repeat('b', 64)])->assertOk();
    expect(DB::table('reboot_actions')->value('authorized_at'))->toBe($authorized)
        ->and(DB::table('reboot_actions')->value('expires_at'))->toBe($action->expires_at);
    $exchange('result', ['status' => 'attempted'])->assertOk();
    $consumed = DB::table('reboot_actions')->sole();
    app(PruneHistory::class)->handle();
    DB::purge('sqlite');
    $exchange('permit', ['nonce' => str_repeat('c', 64)])->assertConflict();
    $exchange('ack')->assertConflict();
    expect(DB::table('reboot_actions')->sole())->toEqual($consumed)
        ->and(DB::table('reboot_actions')->count())->toBe(1)
        ->and(app(RebootState::class)->capability($machine)['available'])->toBeTrue();
    DB::table('reboot_capabilities')->where('machine_id', $machine->id)->update(['boot_id' => '77777777-7777-4777-8777-777777777777']);
    expect(app(RebootState::class)->capability($machine)['available'])->toBeFalse();
});

it('bounds payload and contiguous dedup rows across two successive four hour windows', function () {
    [$machine, $token] = $this->telemetryMachine();
    $path = $this->collectorPath($machine, 'reports');
    $expired = [];
    for ($window = 0; $window < 3; $window++) {
        $reports = [];
        for ($offset = 1; $offset <= 3; $offset++) {
            $report = $this->telemetryReport($window * 3 + $offset);
            $report['sampled_at'] = now()->toIso8601String();
            $reports[] = $report;
        }
        $this->withToken($token)->postJson($path, ['reports' => $reports])->assertOk()->assertJsonCount(3, 'accepted');
        $this->freshContact($machine, $token, $reports[2])->assertOk();
        app(PruneHistory::class)->handle();
        expect(DB::table('telemetry_samples')->orderBy('sequence')->pluck('sequence')->all())->toBe(array_column($reports, 'sequence'))
            ->and(DB::table('telemetry_accepted')->count())->toBe(3)
            ->and(DB::table('telemetry_contacts')->count())->toBe(1)
            ->and(DB::table('telemetry_streams')->count())->toBe(1)
            ->and(DB::table('telemetry_boots')->count())->toBe(1)
            ->and(DB::table('history_buckets')->count())->toBe(0)
            ->and(DB::table('history_accepted_intervals')->count())->toBe($window === 0 ? 0 : 1);
        if ($window > 0) {
            $interval = DB::table('history_accepted_intervals')->sole();
            expect($interval->first_sequence)->toBe(1)->and($interval->last_sequence)->toBe($window * 3);
        }
        $timeline = app(ReadTimeline::class)->timeline($machine, now()->subHours(4), now(), 'minute');
        $this->withToken($token)->postJson($path, ['reports' => array_merge(array_reverse($reports), $expired)])->assertOk();
        expect(app(ReadTimeline::class)->timeline($machine, now()->subHours(4), now(), 'minute'))->toEqual($timeline)
            ->and(DB::table('telemetry_samples')->count())->toBe(3);
        if ($window === 2) {
            break;
        }
        $expired = array_merge($expired, $reports);
        $this->travel(4)->hours();
        app(PruneHistory::class)->handle();
        expect(DB::table('telemetry_samples')->count())->toBe(3);
        $this->travel(1)->seconds();
        app(PruneHistory::class)->handle();
        DB::purge('sqlite');
        expect(DB::table('telemetry_samples')->count())->toBe(0)
            ->and(DB::table('telemetry_accepted')->count())->toBe(0)
            ->and(DB::table('history_accepted_intervals')->count())->toBe(1)
            ->and(json_decode(DB::table('telemetry_contacts')->value('payload'), true))->toEqual(Retention::unavailable());
        foreach ($expired as $old) {
            expect(app(AcceptedIntervals::class)->contains($machine->id, $old['stream_id'], $old['sequence']))->toBeTrue();
        }
        $this->withToken($token)->postJson($path, ['reports' => array_reverse($expired)])->assertOk()->assertJsonCount(count($expired), 'accepted');
        $revived = $expired[0];
        $revived['sampled_at'] = now()->toIso8601String();
        $this->withToken($token)->postJson($path, ['reports' => [$revived]])->assertOk();
        $this->freshContact($machine, $token, $revived)->assertConflict()->assertExactJson(['error' => 'expired_contact']);
        expect(DB::table('telemetry_samples')->count())->toBe(0)->and(DB::table('telemetry_accepted')->count())->toBe(0)
            ->and(DB::table('history_accepted_intervals')->count())->toBe(1);
    }
});
