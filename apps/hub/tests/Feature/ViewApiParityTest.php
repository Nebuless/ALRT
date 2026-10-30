<?php

use App\Incidents\EvaluateFleet;
use App\Livewire\HostDetail;
use App\Models\HubIdentity;
use App\Models\Machine;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\TelemetryDatabase;

uses(TelemetryDatabase::class);
beforeEach(fn () => $this->prepareTelemetryDatabase());

it('protects every live screen and renders a truthful empty fleet with private responses', function () {
    foreach (['/', '/reporting'] as $path) {
        $this->get($path)->assertRedirect('/login');
    }
    $this->telemetryOwner();
    $empty = $this->get('/')->assertOk()->assertSee('id="sky"', false)->assertDontSee('class="lens', false)->assertHeader('Cache-Control');
    expect($empty->headers->get('Cache-Control'))->toContain('no-store', 'private');
    $this->get('/reporting')->assertOk()->assertSee('Reporting paths')->assertHeader('Cache-Control');
    $this->get('/satellites')->assertRedirect('/reporting');
});

it('keeps twenty minute old metrics and processes visibly aged and shares API evidence', function () {
    [$machine, $token] = $this->telemetryMachine();
    $report = $this->telemetryReport();
    $report['sampled_at'] = now()->toIso8601String();
    $this->freshContact($machine, $token, $report)->assertOk();
    $this->travel(20)->minutes();
    app(EvaluateFleet::class)->run();
    $this->telemetryOwner();
    $api = $this->getJson('/api/v1/hosts/'.$machine->id)->assertOk()->json('data');
    expect($api['sample_age_seconds'])->toBe(1200);
    $this->getJson('/api/v1/fleet')->assertOk()->assertJsonPath('verdict', 'Alpha-Hub is silent.');
    $page = $this->get('/hosts/'.$machine->id)->assertOk()->assertSee('Alpha-Hub is silent.')->assertSee('worker')->assertSee('150%')->assertSee('512 MiB')->assertSee('5 GiB')->assertSee('1 KiB/s')->assertSee('unsupported')->assertSee('20 min')->assertSee('Records still on Alpha-Hub appear after replay.')->assertSee('Top by memory')->assertSee('Parent unavailable')->assertSee('Reboot unavailable');
    expect($page->headers->get('Cache-Control'))->toContain('no-store', 'private');
    $timeline = $this->getJson('/api/v1/hosts/'.$machine->id.'/history?from='.urlencode(now()->subHours(4)->toIso8601String()).'&to='.urlencode(now()->toIso8601String()))->json();
    Livewire::test(HostDetail::class, ['machine' => $machine])->assertViewHas('host', $api)->assertViewHas('timeline', $timeline);
});

it('selects historical processes by the exact retained sample id and refuses another hosts snapshot', function () {
    [$machine, $token] = $this->telemetryMachine();
    $first = $this->telemetryReport(1);
    $this->freshContact($machine, $token, $first)->assertOk();
    $sample = DB::table('telemetry_samples')->value('id');
    $this->travel(10)->seconds();
    $second = $this->telemetryReport(2);
    $second['sampled_at'] = now()->toIso8601String();
    $second['processes']['top_cpu']['items'][0]['name'] = 'new-worker';
    $this->freshContact($machine, $token, $second)->assertOk();
    $this->telemetryOwner();
    $snapshot = $this->getJson('/api/v1/hosts/'.$machine->id.'/history/snapshots/'.$sample)->assertOk()->json('data');
    $this->get('/hosts/'.$machine->id.'?sample='.$sample)->assertOk()->assertSee('Historical snapshot')->assertDontSee('new-worker');
    Livewire::test(HostDetail::class, ['machine' => $machine])->set('sample', $sample)->assertViewHas('snapshot', $snapshot)->assertSee('Historical snapshot')->assertDontSee('new-worker');
    Livewire::test(HostDetail::class, ['machine' => $machine])->set('sample', $sample)->set('sample', '')->assertSet('sample', null)->assertSee('new-worker');
    $this->get('/hosts/'.$machine->id.'?sample=999999')->assertOk()->assertSee('Selected snapshot unavailable')->assertDontSee('new-worker');
    $other = Machine::create(['id' => '44444444-4444-4444-8444-444444444444', 'name' => 'Beta-Restricted', 'data_policy' => 'health_processes']);
    $foreign = $this->telemetryReport();
    $foreign['machine_id'] = $other->id;
    $foreign['processes']['top_cpu']['items'][0]['name'] = 'foreign-worker';
    $this->freshContact($other, $other->rotateToken(), $foreign)->assertOk();
    $foreignId = DB::table('telemetry_samples')->where('machine_id', $other->id)->value('id');
    $this->get('/hosts/'.$machine->id.'?sample='.$foreignId)->assertOk()->assertSee('Selected snapshot unavailable')->assertDontSee('foreign-worker');
    $this->getJson('/api/v1/hosts/'.$machine->id.'/history/snapshots/'.$foreignId)->assertNotFound();
});

it('exposes todays opened and resolved incidents in the same model as the live timeline', function () {
    [$machine, $token] = $this->telemetryMachine();
    $this->freshContact($machine, $token, $this->telemetryReport())->assertOk();
    $this->travel(100)->seconds();
    app(EvaluateFleet::class)->run();
    DB::table('incidents')->update(['resolved_at' => now()->toIso8601String()]);
    $this->telemetryOwner();
    $incidents = $this->getJson('/api/v1/hosts/'.$machine->id.'/incidents')->assertOk()->json('data');
    expect($incidents)->toHaveCount(1)->and($incidents[0]['resolved_at'])->not->toBeNull();
    $this->get('/hosts/'.$machine->id)->assertOk()->assertSee('Resolved');
    Livewire::test(HostDetail::class, ['machine' => $machine])->assertViewHas('incidents', $incidents);
});

it('lists unknown destinations and keeps stale collector claims distinct from direct receipts', function () {
    [$machine, $token] = $this->telemetryMachine();
    HubIdentity::create(['id' => 1, 'hub_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'name' => 'Primary-Hub', 'role' => 'primary', 'peers' => ['Alpha-Hub' => 'https://standby.invalid', 'Beta-Restricted' => 'https://work.invalid']]);
    $this->freshContact($machine, $token, $this->telemetryReport())->assertOk();
    DB::table('telemetry_delivery_acks')->insert(['machine_id' => $machine->id, 'hub_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'stream_id' => 'collector-one', 'sequence' => 1, 'acknowledged_at' => now()->subMinutes(10)->toIso8601String(), 'observed_at' => now()->toIso8601String()]);
    $this->telemetryOwner();
    $paths = $this->getJson('/api/v1/reporting-paths')->assertOk()->json('data');
    expect(collect($paths)->pluck('state')->all())->toContain('unknown', 'stale', 'observed');
    $this->get('/reporting')->assertOk()->assertSee('Collector claim')->assertSee('Direct receipt')->assertSee('unknown')->assertSee('600');
});

it('serves only a neutral public offline shell and a network only worker for private requests', function () {
    $this->get('/offline')->assertOk()->assertSee('You are offline.')->assertDontSee('Alpha-Hub')->assertDontSee('wire:snapshot');
    $worker = $this->get('/service-worker.js')->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=utf-8')->getContent();
    expect($worker)->toContain("request.method !== 'GET'", "url.pathname === '/offline'", "url.pathname.startsWith('/build/assets/')")->not->toContain('sync', 'indexedDB');
});

it('keeps a collector path stale when the claim observation is old despite its recent ACK timestamp', function () {
    [$machine] = $this->telemetryMachine();
    DB::table('telemetry_delivery_acks')->insert(['machine_id' => $machine->id, 'hub_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'stream_id' => 'collector-one', 'sequence' => 1,
        'acknowledged_at' => now()->subSeconds(5)->toIso8601String(), 'observed_at' => now()->subMinutes(10)->toIso8601String()]);
    $this->telemetryOwner();
    $paths = $this->getJson('/api/v1/reporting-paths')->assertOk()->json('data');
    $claim = collect($paths)->firstWhere('provenance', 'collector_claim');
    expect($claim['state'])->toBe('stale')->and($claim['observation_age_seconds'])->toBe(600);
});

it('never calls a live contact with old or unsupported measurements healthy', function () {
    [$machine, $token] = $this->telemetryMachine();
    $this->freshContact($machine, $token, $this->telemetryReport())->assertOk();
    app(EvaluateFleet::class)->run();
    $this->telemetryOwner();
    $this->getJson('/api/v1/fleet')->assertOk()->assertSee('Alpha-Hub has old measurements.')->assertDontSee('Nothing’s wrong.');
    $this->getJson('/api/v1/hosts/'.$machine->id)->assertOk()->assertJsonPath('data.observation.state', 'stale_metrics');
});

it('keeps host history at exactly four hours on an advancing clock and renders raw snapshot choices', function () {
    [$machine, $token] = $this->telemetryMachine();
    $this->freshContact($machine, $token, $this->telemetryReport())->assertOk();
    $this->telemetryOwner();
    $this->travelBack();
    $this->get('/hosts/'.$machine->id)->assertOk()->assertSee('Inspect received sample')->assertSee('Last 4 hours')->assertDontSee('Last 6 hours');
    Livewire::test(HostDetail::class, ['machine' => $machine])->assertViewHas('timeline', fn ($timeline) => $timeline['resolution'] === 'raw' && strtotime($timeline['to']) - strtotime($timeline['from']) === 14400);
});

it('labels missing metric and process coverage as partial without a calm verdict or measured zero', function () {
    [$machine, $token] = $this->telemetryMachine();
    $report = $this->telemetryReport();
    $report['sampled_at'] = now()->toIso8601String();
    $report['metrics']['memory']['used_bytes'] = ['value' => null, 'status' => 'permission_denied'];
    $report['processes']['orphans']['completeness'] = 'partial';
    $report['processes']['orphans']['reason'] = 'process_scan_incomplete';
    $this->freshContact($machine, $token, $report)->assertOk();
    app(EvaluateFleet::class)->run();
    $this->telemetryOwner();
    $this->getJson('/api/v1/hosts/'.$machine->id)->assertOk()->assertJsonPath('data.observation.state', 'partial_data');
    $this->getJson('/api/v1/fleet')->assertOk()->assertSee('Alpha-Hub has incomplete measurements.')->assertDontSee('Nothing’s wrong.');
    $this->get('/hosts/'.$machine->id)->assertOk()->assertSee('permission denied')->assertSee('Unavailable')->assertSee('process_scan_incomplete');
});

it('keeps a fully observed calm host calm when ranked process selections deliberately stop at ten', function () {
    [$machine, $token] = $this->telemetryMachine();
    $report = $this->telemetryReport();
    $report['sampled_at'] = now()->toIso8601String();
    $report['coverage']['processes']['discovered'] = 11;
    $report['coverage']['processes']['sampled'] = 11;
    $items = [];
    for ($index = 0; $index < 10; $index++) {
        $item = $report['processes']['longest_running']['items'][0];
        $item['identity']['pid'] = 100 + $index;
        $item['identity']['start_ticks'] = 1000 + $index * 100;
        $item['age_seconds'] = 990 - $index;
        $item['name'] = 'worker-'.$index;
        $items[] = $item;
    }
    foreach (['top_cpu', 'top_rss', 'longest_running'] as $category) {
        $report['processes'][$category]['items'] = $items;
        $report['processes'][$category]['completeness'] = 'partial';
        $report['processes'][$category]['reason'] = 'category_limit';
        $report['processes'][$category]['total_observed'] = 11;
    }
    $this->freshContact($machine, $token, $report)->assertOk();
    app(EvaluateFleet::class)->run();
    $this->telemetryOwner();
    $this->getJson('/api/v1/hosts/'.$machine->id)->assertOk()->assertJsonPath('data.observation.state', 'healthy');
    $this->getJson('/api/v1/fleet')->assertOk()->assertJsonPath('verdict', 'Nothing’s wrong.');
    $this->get('/hosts/'.$machine->id)->assertOk()->assertSee('10 received of 11 observed')->assertSee('category_limit')->assertSee('partial');
    $this->travel(10)->seconds();
    $report['sequence'] = 2;
    $report['sampled_at'] = now()->toIso8601String();
    $report['coverage']['processes']['status'] = 'partial';
    $report['coverage']['processes']['reason'] = 'process_scan_incomplete';
    $report['coverage']['processes']['sampled'] = 10;
    $report['coverage']['processes']['permission_denied'] = 1;
    $this->freshContact($machine, $token, $report)->assertOk();
    app(EvaluateFleet::class)->run();
    $this->getJson('/api/v1/hosts/'.$machine->id)->assertOk()->assertJsonPath('data.observation.state', 'partial_data');
    $this->getJson('/api/v1/fleet')->assertOk()->assertSee('Alpha-Hub has incomplete measurements.')->assertDontSee('Nothing’s wrong.');
});

it('keeps an expired selected id unavailable instead of substituting a retained process sample', function () {
    [$machine, $token] = $this->telemetryMachine();
    $report = $this->telemetryReport();
    $report['sampled_at'] = now()->toIso8601String();
    $this->freshContact($machine, $token, $report)->assertOk();
    $sample = DB::table('telemetry_samples')->value('id');
    $this->travel(14400)->seconds();
    $this->telemetryOwner();
    Livewire::test(HostDetail::class, ['machine' => $machine])->set('sample', $sample)
        ->assertSee('Historical snapshot #'.$sample)->assertSee('data-evidence-lifetime="0"', false);
    $this->travel(1)->seconds();
    $report['sequence'] = 2;
    $report['sampled_at'] = now()->toIso8601String();
    $report['processes']['top_cpu']['items'][0]['name'] = 'replacement-worker';
    $this->freshContact($machine, $token, $report)->assertOk();
    Livewire::test(HostDetail::class, ['machine' => $machine])->set('sample', $sample)
        ->assertSet('sample', $sample)->assertSee('Selected snapshot unavailable')->assertDontSee('replacement-worker');
    $this->get('/hosts/'.$machine->id.'?sample='.$sample)->assertOk()->assertSee('Selected snapshot unavailable')->assertDontSee('Historical snapshot #');
    $this->get('/hosts/'.$machine->id)->assertOk()->assertSee('Last 4 hours')->assertDontSee('Last 2 hours');
});
