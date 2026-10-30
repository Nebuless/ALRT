<?php

use App\Incidents\IncidentReadModel;
use Illuminate\Support\Facades\DB;
use Tests\Support\TelemetryDatabase;

uses(TelemetryDatabase::class);

it('reads active incident alerts in one query while preserving ordering filtering and freshness', function () {
    $this->prepareTelemetryDatabase();
    $opened = now()->subMinute()->toIso8601String();
    $due = now()->addMinute()->toIso8601String();
    foreach (['cpu', 'psi_memory', 'hub_http', 'silence', 'resolved'] as $rule) {
        DB::table('incidents')->insert([
            'id' => $rule, 'target' => $rule === 'hub_http' ? 'peer' : 'machine', 'target_name' => 'Synthetic',
            'kind' => $rule === 'hub_http' ? 'hub' : 'host', 'rule' => $rule, 'anchor' => 'synthetic',
            'opened_at' => $opened, 'resolved_at' => $rule === 'resolved' ? $opened : null,
        ]);
    }
    DB::table('incident_outbox')->insert([
        'incident_id' => 'cpu', 'due_at' => $due, 'attempts' => 2, 'last_error' => 'transport_failed',
    ]);
    $read = app(IncidentReadModel::class);
    DB::enableQueryLog();
    try {
        $fleet = $read->fleet();
        expect(DB::getQueryLog())->toHaveCount(1);
    } finally {
        DB::disableQueryLog();
    }
    expect(array_column($fleet, 'id'))->toBe(['silence', 'hub_http', 'psi_memory', 'cpu'])
        ->and($fleet[0])->toBe([
            'id' => 'silence', 'target' => 'machine', 'target_name' => 'Synthetic', 'kind' => 'host', 'rule' => 'silence',
            'opened_at' => $opened, 'age_seconds' => 60, 'provenance' => 'local_incident_evaluation',
            'alert' => ['delivered_at' => null, 'suppressed_at' => null, 'next_attempt_at' => null, 'attempts' => 0, 'last_error' => null],
        ])
        ->and($fleet[3]['alert'])->toBe([
            'delivered_at' => null, 'suppressed_at' => null, 'next_attempt_at' => $due, 'attempts' => 2, 'last_error' => 'transport_failed',
        ])
        ->and(array_column($read->host('machine')['incidents'], 'id'))->toBe(['silence', 'psi_memory', 'cpu'])
        ->and($read->host('absent')['incidents'])->toBe([]);
    $this->travel(10)->seconds();
    $updated = now()->toIso8601String();
    DB::table('incident_outbox')->where('incident_id', 'cpu')->update([
        'delivered_at' => $updated, 'suppressed_at' => $updated, 'due_at' => $updated, 'attempts' => 3, 'last_error' => null,
    ]);
    $fresh = $read->fleet();
    expect($fresh[3]['age_seconds'])->toBe(70)->and($fresh[3]['alert'])->toBe([
        'delivered_at' => $updated, 'suppressed_at' => $updated, 'next_attempt_at' => $updated, 'attempts' => 3, 'last_error' => null,
    ]);
    DB::table('incidents')->update(['resolved_at' => $updated]);
    expect($read->fleet())->toBe([]);
});
