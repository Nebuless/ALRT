<?php

use App\Models\Machine;
use Illuminate\Support\Facades\DB;
use Tests\Support\TelemetryDatabase;

uses(TelemetryDatabase::class);
beforeEach(fn () => $this->prepareTelemetryDatabase());

it('accepts canonical content once across reordered replay and contact and remembers pruned identities', function () {
    [$machine, $token] = $this->telemetryMachine();
    $report = $this->telemetryReport();
    $path = $this->collectorPath($machine, 'reports');
    $this->withToken($token)->postJson($path, ['reports' => [$report]])->assertOk()->assertJsonPath('accepted.0.sequence', 1);
    $reordered = array_reverse($report, true);
    $reordered['metrics'] = array_reverse($reordered['metrics'], true);
    $this->withToken($token)->postJson($path, ['reports' => [$reordered]])->assertOk();
    $this->freshContact($machine, $token, $report)->assertOk();
    expect(DB::table('telemetry_accepted')->count())->toBe(1)->and(DB::table('telemetry_samples')->count())->toBe(1);
    DB::table('telemetry_samples')->delete();
    $this->withToken($token)->postJson($path, ['reports' => [$report]])->assertOk();
    expect(DB::table('telemetry_samples')->count())->toBe(0);
    $report['metrics']['cpu_percent'] = ['value' => 42, 'status' => 'available'];
    $this->withToken($token)->postJson($path, ['reports' => [$this->telemetryReport(2), $report]])->assertConflict()->assertExactJson(['error' => 'identity_conflict']);
    expect(DB::table('telemetry_accepted')->count())->toBe(1)->and(DB::table('telemetry_samples')->count())->toBe(0);
});

it('rolls back a batch with no ACK on persistence failure', function () {
    [$machine, $token] = $this->telemetryMachine();
    DB::unprepared("CREATE TRIGGER fail_second_sample BEFORE INSERT ON telemetry_samples WHEN NEW.sequence = 2 BEGIN SELECT RAISE(ABORT, 'private database diagnostic'); END");
    $this->withToken($token)->postJson($this->collectorPath($machine, 'reports'), ['reports' => [$this->telemetryReport(1), $this->telemetryReport(2)]])
        ->assertStatus(503)->assertExactJson(['error' => 'ingest_unavailable']);
    expect(DB::table('telemetry_accepted')->count())->toBe(0)->and(DB::table('telemetry_samples')->count())->toBe(0);
});

it('bounds a blocked current writer without consuming its challenge or changing the connection timeout', function () {
    [$machine, $token] = $this->telemetryMachine();
    $nonce = $this->withToken($token)->postJson($this->collectorPath($machine, 'challenge'))->assertCreated()->json('nonce');
    $timeout = DB::selectOne('PRAGMA busy_timeout')->timeout;
    $writer = new PDO('sqlite:'.DB::connection()->getDatabaseName());
    $writer->exec('BEGIN IMMEDIATE');
    $started = hrtime(true);
    try {
        $this->withToken($token)->postJson($this->collectorPath($machine, 'contact'), ['nonce' => $nonce, 'report' => $this->telemetryReport()])
            ->assertStatus(503)->assertExactJson(['error' => 'ingest_unavailable']);
    } finally {
        $writer->rollBack();
    }
    expect((hrtime(true) - $started) / 1e9)->toBeLessThan(6.5)
        ->and(DB::selectOne('PRAGMA busy_timeout')->timeout)->toBe($timeout)
        ->and(DB::connection()->transactionLevel())->toBe(0)
        ->and(DB::table('telemetry_accepted')->count())->toBe(0)
        ->and(DB::table('telemetry_challenges')->value('consumed_at'))->toBeNull();
    $this->withToken($token)->postJson($this->collectorPath($machine, 'contact'), ['nonce' => $nonce, 'report' => $this->telemetryReport()])->assertOk();
    expect(DB::table('telemetry_accepted')->count())->toBe(1);
});

it('rejects private extras and invalid schema relationships before persistence', function (string $case) {
    [$machine, $token] = $this->telemetryMachine('Beta-Restricted');
    $report = $this->telemetryReport();
    match ($case) {
        'title' => $report['title'] = 'private project',
        'nested_extra' => $report['metrics']['memory']['environment'] = 'private secret',
        'type' => $report['sequence'] = '1',
        'range' => $report['capabilities']['clock_ticks_per_second'] = 1000001,
        'required' => $report['metrics'] = [],
        'enum' => $report['scope'] = 'private scope',
        'conditional' => $report['metrics']['cpu_percent'] = ['status' => 'unsupported', 'value' => 10],
        'date' => $report['sampled_at'] = 'tomorrow',
        'identity' => $report['machine_id'] = 'other-machine',
        'major' => $report['protocol_version'] = 2,
    };
    $expected = $case === 'major' ? 'protocol_version_unsupported' : 'invalid_report';
    $this->withToken($token)->postJson($this->collectorPath($machine, 'reports'), ['reports' => [$report]])
        ->assertUnprocessable()->assertExactJson(['error' => $expected]);
    expect(DB::table('telemetry_accepted')->count())->toBe(0);
})->with(['title', 'nested_extra', 'type', 'range', 'required', 'enum', 'conditional', 'date', 'identity', 'major']);

it('bounds replay by record count and uncompressed bytes', function () {
    [$machine, $token] = $this->telemetryMachine();
    $path = $this->collectorPath($machine, 'reports');
    $this->withToken($token)->postJson($path, ['reports' => array_fill(0, 129, (object) [])])->assertStatus(413)->assertExactJson(['error' => 'batch_too_large']);
    $this->withToken($token)->postJson($path, ['reports' => [$this->telemetryReport()], 'padding' => str_repeat('x', 262145)])->assertStatus(413);
    expect(DB::table('telemetry_accepted')->count())->toBe(0);
});

it('keeps replay historical and refuses retired streams and boots from becoming current again', function () {
    [$machine, $token] = $this->telemetryMachine();
    $this->freshContact($machine, $token, $this->telemetryReport())->assertOk();
    $this->travel(5)->seconds();
    $this->freshContact($machine, $token, $this->telemetryReport(1, 'collector-two'))->assertOk();
    $this->freshContact($machine, $token, $this->telemetryReport(1, 'collector-three', '33333333-3333-4333-8333-333333333333'))->assertOk();
    $before = DB::table('telemetry_contacts')->first();
    $this->travel(60)->seconds();
    $this->withToken($token)->postJson($this->collectorPath($machine, 'reports'), ['reports' => [$this->telemetryReport(50)]])->assertOk();
    $this->freshContact($machine, $token, $this->telemetryReport(51))->assertConflict()->assertExactJson(['error' => 'retired_stream']);
    $this->freshContact($machine, $token, $this->telemetryReport(1, 'collector-four'))->assertConflict();
    $after = DB::table('telemetry_contacts')->first();
    expect($after->last_contact_at)->toBe($before->last_contact_at)->and($after->payload)->toBe($before->payload);
});

it('consumes challenges once and rejects expired and delayed generations', function () {
    [$machine, $token] = $this->telemetryMachine();
    $challenge = $this->collectorPath($machine, 'challenge');
    $contact = $this->collectorPath($machine, 'contact');
    $nonce = $this->withToken($token)->postJson($challenge)->assertCreated()->json('nonce');
    $body = ['nonce' => $nonce, 'report' => $this->telemetryReport()];
    $this->withToken($token)->postJson($contact, $body)->assertOk();
    $this->withToken($token)->postJson($contact, $body)->assertConflict()->assertExactJson(['error' => 'stale_challenge']);
    $nonce = $this->withToken($token)->postJson($challenge)->json('nonce');
    $this->travel(31)->seconds();
    $this->withToken($token)->postJson($contact, ['nonce' => $nonce, 'report' => $this->telemetryReport(2)])->assertConflict();
    $delayed = $this->withToken($token)->postJson($challenge)->json('nonce');
    $this->freshContact($machine, $token, $this->telemetryReport(3))->assertOk();
    $this->withToken($token)->postJson($contact, ['nonce' => $delayed, 'report' => $this->telemetryReport(4)])->assertConflict();
    $this->freshContact($machine, $token, $this->telemetryReport(2))->assertConflict()->assertExactJson(['error' => 'stale_contact']);
    expect(DB::table('telemetry_contacts')->value('sequence'))->toBe(3);
});

it('accepts the unchanged canonical schema fixture used by the Go decoder', function () {
    [$machine, $token] = $this->telemetryMachine();
    $fixture = json_decode(file_get_contents(dirname(__DIR__, 4).'/contracts/fixtures/telemetry/valid-sample.json'), true, flags: JSON_THROW_ON_ERROR);
    $this->withToken($token)->postJson($this->collectorPath($machine, 'reports'), ['reports' => [$fixture]])->assertOk();
});

it('checks nested process and pressure constraints from the source schema', function (string $case) {
    [$machine, $token] = $this->telemetryMachine();
    $report = $this->telemetryReport();
    match ($case) {
        'parent' => $report['processes']['top_cpu']['items'][0]['parent']['identity'] = null,
        'orphan' => $report['processes']['orphans']['items'][0]['former_parent'] = null,
        'pressure' => $report['metrics']['pressure']['memory']['some']['avg10'] = 101,
        'label' => $report['metrics']['disks'][0]['label'] = '/private/path',
        'chunk' => $report['processes']['top_cpu']['chunk_count'] = 2,
        'top_limit' => $report['processes']['top_cpu']['items'] = array_fill(0, 11, $report['processes']['top_cpu']['items'][0]),
        'name' => $report['processes']['top_cpu']['items'][0]['name'] = str_repeat('x', 65),
        'array' => $report['metrics']['disks'] = (object) [],
        'boolean' => $report['capabilities']['cpu'] = 1,
    };
    $this->withToken($token)->postJson($this->collectorPath($machine, 'reports'), ['reports' => [$report]])->assertUnprocessable()->assertExactJson(['error' => 'invalid_report']);
    expect(DB::table('telemetry_accepted')->count())->toBe(0);
})->with(['parent', 'orphan', 'pressure', 'label', 'chunk', 'top_limit', 'name', 'array', 'boolean']);

it('binds a challenge to its authenticated machine and orders live samples by retained stream sequence', function () {
    [$machine, $token] = $this->telemetryMachine();
    $nonce = $this->withToken($token)->postJson($this->collectorPath($machine, 'challenge'))->assertCreated()->json('nonce');
    $other = Machine::create(['name' => 'Primary-Hub', 'data_policy' => 'health_processes']);
    $otherToken = $other->rotateToken();
    $report = $this->telemetryReport();
    $report['machine_id'] = $other->id;
    $this->withToken($otherToken)->postJson($this->collectorPath($other, 'contact'), ['nonce' => $nonce, 'report' => $report])->assertConflict()->assertExactJson(['error' => 'stale_challenge']);
    $this->withToken($token)->postJson($this->collectorPath($machine, 'reports'), ['reports' => [$this->telemetryReport(5)]])->assertOk();
    $this->withToken($token)->postJson($this->collectorPath($machine, 'contact'), ['nonce' => $nonce, 'report' => $this->telemetryReport(4)])->assertConflict()->assertExactJson(['error' => 'stale_contact']);
    expect(DB::table('telemetry_contacts')->count())->toBe(0);
});
