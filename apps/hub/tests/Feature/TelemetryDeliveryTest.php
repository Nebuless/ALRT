<?php

use App\ReadModels\FleetSummary;
use Illuminate\Support\Facades\DB;
use Tests\Support\TelemetryDatabase;

uses(TelemetryDatabase::class);
beforeEach(fn () => $this->prepareTelemetryDatabase());
function deliveryMetadata(): array
{
    return ['version' => 1, 'recording' => 'recording', 'possible_loss_seconds' => 30, 'unknown_coverage' => false, 'lost_ranges' => [['hub_id' => '33333333-3333-4333-8333-333333333333', 'stream_id' => 'collector-one', 'first_sequence' => 1, 'last_sequence' => 3, 'from' => '2026-10-15T01:00:00Z', 'to' => '2026-10-15T01:00:30Z', 'reason' => 'eviction']], 'acknowledgements' => [['hub_id' => '33333333-3333-4333-8333-333333333333', 'stream_id' => 'collector-one', 'sequence' => 4, 'acknowledged_at' => '2026-10-15T01:01:00Z']]];
}
it('stores challenged delivery atomically and distinguishes indirect ACK age from observation age', function () {
    [$m,$token] = $this->telemetryMachine();
    $nonce = $this->withToken($token)->postJson($this->collectorPath($m, 'challenge'))->json('nonce');
    $this->withToken($token)->postJson($this->collectorPath($m, 'contact'), ['nonce' => $nonce, 'report' => $this->telemetryReport(), 'delivery' => deliveryMetadata()])->assertOk()->assertJsonCount(1, 'accepted');
    $paths = app(FleetSummary::class)->paths($m);
    expect($paths['indirect_paths'][0]['provenance'])->toBe('collector_claim')->and($paths['indirect_paths'][0]['acknowledged_at'])->toBe('2026-10-15T01:01:00Z')->and(app(FleetSummary::class)->host($m)['delivery']['lost_ranges'][0]['last_sequence'])->toBe(3);
    $this->travel(60)->seconds();
    $this->freshContact($m, $token, $this->telemetryReport(2))->assertOk();
    $later = app(FleetSummary::class)->paths($m);
    expect($later['indirect_paths'][0]['acknowledged_at'])->toBe($paths['indirect_paths'][0]['acknowledged_at'])->and($later['indirect_paths'][0]['observed_at'])->toBe($paths['indirect_paths'][0]['observed_at']);
});
it('rejects sensitive extras and inverted ranges before all mutations', function () {
    [$m,$token] = $this->telemetryMachine();
    foreach (['extra', 'range'] as $case) {
        $data = deliveryMetadata();
        if ($case === 'extra') {
            $data['acknowledgements'][0]['url'] = 'https://secret.invalid';
        } else {
            $data['lost_ranges'][0]['first_sequence'] = 9;
        }$nonce = $this->withToken($token)->postJson($this->collectorPath($m, 'challenge'))->json('nonce');
        $this->withToken($token)->postJson($this->collectorPath($m, 'contact'), ['nonce' => $nonce, 'report' => $this->telemetryReport(), 'delivery' => $data])->assertUnprocessable();
        expect(DB::table('telemetry_contacts')->count())->toBe(0)->and(DB::table('telemetry_accepted')->count())->toBe(0);
    }
});
it('refreshes direct duplicate receipt without changing contact or contribution', function () {
    [$m,$token] = $this->telemetryMachine();
    $report = $this->telemetryReport();
    $this->freshContact($m, $token, $report)->assertOk();
    $before = app(FleetSummary::class)->host($m);
    $this->travel(60)->seconds();
    $this->withToken($token)->postJson($this->collectorPath($m, 'reports'), ['reports' => [$report]])->assertOk();
    $paths = app(FleetSummary::class)->paths($m);
    expect($paths['paths'][0]['age_seconds'])->toBe(0)->and(app(FleetSummary::class)->host($m)['contact_at'])->toBe($before['contact_at'])->and(DB::table('telemetry_accepted')->count())->toBe(1);
});

it('rolls back delivery and report when the delivery write fails', function () {
    [$machine, $token] = $this->telemetryMachine();
    DB::unprepared("CREATE TRIGGER fail_delivery BEFORE INSERT ON telemetry_delivery BEGIN SELECT RAISE(ABORT, 'private storage failure'); END");
    $nonce = $this->withToken($token)->postJson($this->collectorPath($machine, 'challenge'))->json('nonce');
    $this->withToken($token)->postJson($this->collectorPath($machine, 'contact'), ['nonce' => $nonce, 'report' => $this->telemetryReport(), 'delivery' => deliveryMetadata()])
        ->assertStatus(503)->assertExactJson(['error' => 'ingest_unavailable']);
    expect(DB::table('telemetry_accepted')->count())->toBe(0)
        ->and(DB::table('telemetry_contacts')->count())->toBe(0)
        ->and(DB::table('telemetry_delivery')->count())->toBe(0)
        ->and(DB::table('telemetry_challenges')->value('consumed_at'))->toBeNull();
});

it('bounds and validates all optional metadata before consuming a challenge', function (string $case) {
    [$machine, $token] = $this->telemetryMachine();
    $data = deliveryMetadata();
    match ($case) {
        'ranges' => $data['lost_ranges'] = array_fill(0, 33, $data['lost_ranges'][0]),
        'acks' => $data['acknowledgements'] = array_fill(0, 9, $data['acknowledgements'][0]),
        'duplicate_hub' => $data['acknowledgements'][] = $data['acknowledgements'][0],
        'url' => $data['acknowledgements'][0]['hub_id'] = 'https://private.invalid',
        'time' => $data['lost_ranges'][0]['to'] = '2026-10-15T00:00:00Z',
        'date' => $data['acknowledgements'][0]['acknowledged_at'] = '2026-02-31T01:01:00Z',
        'state' => $data['recording'] = '/private/path',
        'null' => $data = null,
    };
    $nonce = $this->withToken($token)->postJson($this->collectorPath($machine, 'challenge'))->json('nonce');
    $this->withToken($token)->postJson($this->collectorPath($machine, 'contact'), ['nonce' => $nonce, 'report' => $this->telemetryReport(), 'delivery' => $data])
        ->assertUnprocessable()->assertExactJson(['error' => 'invalid_report']);
    expect(DB::table('telemetry_accepted')->count())->toBe(0)->and(DB::table('telemetry_challenges')->value('consumed_at'))->toBeNull();
})->with(['ranges', 'acks', 'duplicate_hub', 'url', 'time', 'date', 'state', 'null']);

it('keeps repeated stale ACK claims stale and treats absent claims as unknown', function () {
    [$machine, $token] = $this->telemetryMachine();
    $data = deliveryMetadata();
    $data['acknowledgements'][0]['acknowledged_at'] = now()->subMinutes(10)->toIso8601String();
    $post = function (array $data, int $sequence) use ($machine, $token) {
        $nonce = $this->withToken($token)->postJson($this->collectorPath($machine, 'challenge'))->json('nonce');

        return $this->withToken($token)->postJson($this->collectorPath($machine, 'contact'), ['nonce' => $nonce, 'report' => $this->telemetryReport($sequence), 'delivery' => $data]);
    };
    $post($data, 1)->assertOk();
    $first = app(FleetSummary::class)->paths($machine)['indirect_paths'][0];
    $this->travel(60)->seconds();
    $post($data, 2)->assertOk();
    $path = app(FleetSummary::class)->paths($machine)['indirect_paths'][0];
    expect($path['observed_at'])->toBe($first['observed_at'])->and($path['state'])->toBe('stale')
        ->and($path['claim_age_seconds'])->toBe(660)->and($path['observation_age_seconds'])->toBe(60);
    $data['acknowledgements'] = [];
    $post($data, 3)->assertOk();
    expect(app(FleetSummary::class)->paths($machine)['indirect_paths'])->toBe([]);
});

it('accepts the same lowercase date-time syntax in reports and delivery metadata', function () {
    [$machine, $token] = $this->telemetryMachine();
    $report = $this->telemetryReport();
    $report['sampled_at'] = strtolower($report['sampled_at']);
    $data = deliveryMetadata();
    $data['lost_ranges'][0]['from'] = strtolower($data['lost_ranges'][0]['from']);
    $data['lost_ranges'][0]['to'] = strtolower($data['lost_ranges'][0]['to']);
    $data['acknowledgements'][0]['acknowledged_at'] = strtolower($data['acknowledgements'][0]['acknowledged_at']);
    $nonce = $this->withToken($token)->postJson($this->collectorPath($machine, 'challenge'))->json('nonce');
    $this->withToken($token)->postJson($this->collectorPath($machine, 'contact'), ['nonce' => $nonce, 'report' => $report, 'delivery' => $data])
        ->assertOk()->assertJsonCount(1, 'accepted');
    expect(app(FleetSummary::class)->paths($machine)['indirect_paths'][0]['acknowledged_at'])
        ->toBe($data['acknowledgements'][0]['acknowledged_at']);
});
