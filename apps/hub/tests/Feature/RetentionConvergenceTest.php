<?php

use App\History\AcceptedIntervals;
use App\History\Retention;
use App\Jobs\PruneHistory;
use App\ReadModels\FleetSummary;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\TelemetryDatabase;

uses(TelemetryDatabase::class);
beforeEach(fn () => $this->prepareTelemetryDatabase());

it('drains every payload stage including future legacy evidence and performs no writes when empty', function () {
    [$machine, $token] = $this->telemetryMachine();
    $this->travelTo(Carbon::parse('2026-10-16T12:00:00Z'));
    $report = $this->telemetryReport();
    $this->freshContact($machine, $token, $report)->assertOk();
    $contact = DB::table('telemetry_contacts')->first();
    $sample = (array) DB::table('telemetry_samples')->first();
    $accepted = (array) DB::table('telemetry_accepted')->first();
    DB::transaction(function () use ($contact, $sample, $accepted): void {
        for ($i = 1; $i <= 260; $i++) {
            $id = sprintf('55555555-5555-4555-8555-%012d', $i);
            DB::table('machines')->insert(['id' => $id, 'name' => 'synthetic-'.$i, 'data_policy' => 'health_processes']);
            $row = (array) $contact;
            $row['machine_id'] = $id;
            DB::table('telemetry_contacts')->insert($row);
        }
        for ($i = 2; $i <= 260; $i++) {
            $identity = $accepted;
            unset($identity['id']);
            $identity['sequence'] = $i;
            $identity['sampled_at'] = '2026-10-17T12:00:00+00:00';
            $acceptedId = DB::table('telemetry_accepted')->insertGetId($identity);
            $raw = $sample;
            unset($raw['id']);
            $raw['accepted_id'] = $acceptedId;
            $raw['sequence'] = $i;
            $raw['sampled_at'] = $identity['sampled_at'];
            DB::table('telemetry_samples')->insert($raw);
            DB::table('history_buckets')->insert(['machine_id' => $contact->machine_id, 'resolution' => $i % 2 ? 60 : 3600,
                'bucket_at' => Carbon::parse('2026-10-17T12:00:00Z')->addHours($i)->toIso8601String(), 'aggregate' => '{"legacy":"process-secret"}']);
        }
    });
    $this->travel(5)->hours();
    $before = DB::table('telemetry_contacts')->orderBy('machine_id')->get()->map(fn ($row) => (array) $row)->all();
    app(PruneHistory::class)->handle();
    expect(DB::table('telemetry_samples')->count())->toBe(0)->and(DB::table('telemetry_accepted')->count())->toBe(0)
        ->and(DB::table('history_buckets')->count())->toBe(0)->and(DB::table('telemetry_contacts')->count())->toBe(261);
    foreach ($before as $row) {
        expect((array) DB::table('telemetry_contacts')->where('machine_id', $row['machine_id'])->first())
            ->toEqual(array_replace($row, ['payload' => json_encode(Retention::unavailable(), JSON_THROW_ON_ERROR)]));
    }
    expect(app(AcceptedIntervals::class)->contains($machine->id, $report['stream_id'], 260))->toBeTrue();
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });
    app(PruneHistory::class)->handle();
    expect($writes)->toBe([]);
    $this->travelTo(Carbon::parse('2026-10-16T12:00:00Z'));
    expect(app(FleetSummary::class)->host($machine)['metrics'])->toBeNull();
});

it('rolls back compaction and raw deletion together and leaves pending work restartable', function () {
    [$machine, $token] = $this->telemetryMachine();
    $this->withToken($token)->postJson($this->collectorPath($machine, 'reports'), ['reports' => [$this->telemetryReport()]])->assertOk();
    $this->travel(5)->hours();
    DB::unprepared("CREATE TRIGGER fail_prune BEFORE DELETE ON telemetry_accepted BEGIN SELECT RAISE(ABORT, 'injected prune failure'); END");
    expect(fn () => app(PruneHistory::class)->handle())->toThrow(QueryException::class);
    expect(DB::table('history_accepted_intervals')->count())->toBe(0)->and(DB::table('telemetry_samples')->count())->toBe(1);
    DB::unprepared('DROP TRIGGER fail_prune');
    DB::purge('sqlite');
    app(PruneHistory::class)->handle();
    expect(DB::table('history_accepted_intervals')->count())->toBe(1)->and(DB::table('telemetry_samples')->count())->toBe(0);
});

it('does not redact a newer eligible generation after inspecting expired evidence', function () {
    [$machine, $token] = $this->telemetryMachine();
    $this->freshContact($machine, $token, $this->telemetryReport())->assertOk();
    $this->travel(5)->hours();
    $old = DB::table('telemetry_contacts')->first();
    $interleaved = false;
    DB::listen(function (QueryExecuted $query) use (&$interleaved, $machine): void {
        if (! $interleaved && str_starts_with($query->sql, 'select') && str_contains($query->sql, '"telemetry_contacts"') && str_contains($query->sql, 'limit')) {
            $interleaved = true;
            DB::table('telemetry_contacts')->where('machine_id', $machine->id)->update(['generation' => DB::raw('generation + 1'), 'sampled_at' => now()->toIso8601String()]);
        }
    });
    app(PruneHistory::class)->handle();
    expect($interleaved)->toBeTrue()->and(DB::table('telemetry_contacts')->value('generation'))->toBe($old->generation + 1);
    expect(DB::table('telemetry_contacts')->value('payload'))->toBe($old->payload);
    expect(app(FleetSummary::class)->host($machine)['metrics'])->not->toBeNull();
});

it('services every bounded stage when the first stage exhausts the cooperative budget', function () {
    [$machine, $token] = $this->telemetryMachine();
    $this->freshContact($machine, $token, $this->telemetryReport())->assertOk();
    DB::table('history_buckets')->insert(['machine_id' => $machine->id, 'resolution' => 60,
        'bucket_at' => now()->toIso8601String(), 'aggregate' => '{}']);
    $this->travel(5)->hours();
    $job = new class extends PruneHistory
    {
        protected function monotonicTime(): int
        {
            return DB::table('telemetry_samples')->exists() ? 0 : 3_000_000_000;
        }
    };
    $job->handle();
    expect(DB::table('telemetry_samples')->count())->toBe(0)
        ->and(DB::table('history_buckets')->count())->toBe(0)
        ->and(json_decode(DB::table('telemetry_contacts')->value('payload'), true)['unavailable_reason'])->toBe('observation_unavailable');
});
