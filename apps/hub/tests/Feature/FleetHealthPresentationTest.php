<?php

use App\Incidents\EvaluateFleet;
use App\Incidents\ObservePeerHub;
use App\Models\Machine;
use App\ReadModels\FleetSummary;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\IncidentFixtures;
use Tests\Support\TelemetryDatabase;

uses(TelemetryDatabase::class, IncidentFixtures::class);

beforeEach(function () {
    $this->prepareTelemetryDatabase();
    Queue::fake();
    Http::preventStrayRequests();
});

it('does not infer hub authority from an enrolled machine display name', function () {
    $this->telemetryMachine('Primary-Hub');
    Machine::create(['name' => 'Collector only', 'data_policy' => 'titles']);
    $this->telemetryOwner();
    $this->get('/reporting')->assertOk()
        ->assertDontSee('primary hub</span>', false)->assertDontSee('standby hub</span>', false);
});

it('names failed peer services on Home while collector contacts stay healthy', function (string $service, string $label) {
    $hub = $this->incidentHub('Alpha-Hub', 'standby');
    $hub->update(['peers' => ['Primary-Hub' => 'https://beta-restricted.example.test']]);
    [$machine, $token] = $this->telemetryMachine('Primary-Hub');
    $this->incidentPeerConfig($machine, $token);
    $failed = true;
    Http::fake(['https://beta-restricted.example.test/*' => function () use ($service, &$failed) {
        if (! $failed) {
            return Http::response($this->incidentPeerBody());
        }
        if ($service === 'http') {
            throw new ConnectionException('synthetic peer failure');
        }

        return Http::response($this->incidentPeerBody(
            schedulerAge: $service === 'scheduler' ? 100 : 0,
            workerAge: $service === 'urgent_worker' ? 100 : 0,
        ));
    }]);
    for ($sequence = 1; $sequence <= 11; $sequence++) {
        $this->incidentContact($machine, $token, $sequence);
        app(ObservePeerHub::class)->run();
        app(EvaluateFleet::class)->run();
        if ($sequence < 11) {
            $this->travel(10)->seconds();
        }
    }
    expect(DB::table('incidents')->where('rule', 'hub_'.$service)->whereNull('resolved_at')->exists())->toBeTrue()
        ->and(DB::table('incidents')->where('kind', 'host')->exists())->toBeFalse();
    $this->telemetryOwner();
    $this->withHeader('Authorization', '');
    $sentence = 'Primary-Hub’s '.$label.' is unavailable.';
    $this->getJson('/api/v1/fleet')->assertOk()
        ->assertJsonPath('hosts.0.observation.state', 'healthy')
        ->assertJsonPath('attention_count', 1)
        ->assertJsonPath('verdict', $sentence);
    expect(app(FleetSummary::class)->fleet()['peer_hubs'][0]['observation_age_seconds'])->toBe(0);
    $this->getJson('/api/v1/fleet')->assertOk()->assertJsonPath('verdict', $sentence);

    // A fresh collector contact cannot refresh an old peer service observation.
    $this->travel(75)->seconds();
    $this->incidentContact($machine, $token, 12);
    app(EvaluateFleet::class)->run();
    $this->getJson('/api/v1/fleet')->assertOk()->assertJsonPath('verdict', $sentence);
    expect(app(FleetSummary::class)->fleet()['peer_hubs'][0]['observation_age_seconds'])->toBe(75);

    $failed = false;
    app(ObservePeerHub::class)->run();
    $this->getJson('/api/v1/fleet')->assertOk()->assertJsonPath('attention_count', 0)->assertJsonPath('verdict', 'Nothing’s wrong.');
    $this->getJson('/api/v1/fleet')->assertOk()->assertJsonPath('incidents', []);
})->with([
    ['http', 'HTTP service'],
    ['scheduler', 'scheduler'],
    ['urgent_worker', 'urgent worker'],
]);

it('keeps challenged future CPU measurements unknown independently of live contact', function () {
    [$machine, $token] = $this->telemetryMachine();
    $report = $this->telemetryReport();
    $report['sampled_at'] = now()->addMinutes(5)->toIso8601String();
    $report['metrics']['cpu_percent']['value'] = 99;
    $this->freshContact($machine, $token, $report)->assertOk();
    app(EvaluateFleet::class)->run();
    expect(DB::table('incident_watches')->where('rule', 'cpu')->value('state'))->toBe('unknown');
    $this->telemetryOwner();
    $this->withHeader('Authorization', '');
    $this->getJson('/api/v1/hosts/'.$machine->id)->assertOk()
        ->assertJsonPath('data.contact_age_seconds', 0)
        ->assertJsonPath('data.health.state', 'healthy')
        ->assertJsonPath('data.sample_age_seconds', null)
        ->assertJsonPath('data.observation.state', 'unknown');
    $this->getJson('/api/v1/fleet')->assertOk()->assertJsonPath('attention_count', 1);
    $this->getJson('/api/v1/fleet')->assertOk()->assertJsonPath('verdict', 'Alpha-Hub has unknown measurements.');
    $this->get('/hosts/'.$machine->id)->assertOk()->assertSee('Last received vitals')
        ->assertSee('ahead of this hub’s clock')->assertDontSee('Live vitals');

    $this->travel(10)->seconds();
    $report['sequence'] = 2;
    $report['sampled_at'] = now()->toIso8601String();
    $report['metrics']['cpu_percent']['value'] = 20;
    $this->freshContact($machine, $token, $report)->assertOk();
    app(EvaluateFleet::class)->run();
    $this->getJson('/api/v1/fleet')->assertOk()->assertJsonPath('verdict', 'Nothing’s wrong.');
});
