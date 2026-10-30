<?php

namespace Tests\Support;

use App\Incidents\EvaluateFleet;
use App\Models\HubIdentity;
use App\Models\Machine;
use Illuminate\Support\Facades\DB;

trait IncidentFixtures
{
    public function openSilenceIncident(): string
    {
        [$machine, $token] = $this->telemetryMachine();
        $this->incidentContact($machine, $token, 1);
        app(EvaluateFleet::class)->run();
        $this->travel(90)->seconds();
        app(EvaluateFleet::class)->run();

        return DB::table('incidents')->where('rule', 'silence')->value('id');
    }

    public function incidentHub(string $name = 'Primary-Hub', string $role = 'primary'): HubIdentity
    {
        return HubIdentity::create([
            'id' => 1, 'hub_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'name' => $name, 'role' => $role,
            'peers' => ['Beta-Restricted' => 'https://beta-restricted.example.test', 'Alpha-Hub' => 'https://alpha.example.test'],
            'telegram_bot_token' => '123456:test-only-token', 'telegram_chat_id' => 'test-chat',
        ]);
    }

    public function incidentContact(Machine $machine, string $token, int $sequence, string $signal = 'cpu', bool $high = false): void
    {
        $report = $this->telemetryReport($sequence);
        $report['machine_id'] = $machine->id;
        $report['sampled_at'] = now()->toIso8601String();
        if ($signal === 'cpu') {
            $report['metrics']['cpu_percent'] = ['status' => 'available', 'value' => $high ? 90 : 20];
        } elseif ($signal === 'memory') {
            $report['metrics']['memory']['total_bytes']['value'] = 1000;
            $report['metrics']['memory']['used_bytes']['value'] = $high ? 900 : 200;
            $report['metrics']['memory']['available_bytes']['value'] = $high ? 100 : 800;
        } else {
            $kind = substr($signal, 4);
            $report['capabilities']['psi_'.$kind] = true;
            $report['metrics']['pressure'][$kind]['some'] = ['status' => 'available', 'avg10' => $high ? 20 : 0, 'avg60' => 0, 'avg300' => 0, 'total_microseconds' => $sequence * 1000];
        }
        $this->freshContact($machine, $token, $report)->assertOk();
    }

    public function incidentPeerConfig(Machine $machine, string $token): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nebuless-peer-');
        file_put_contents($path, json_encode([
            'machine_id' => $machine->id,
            'destinations' => [
                ['id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'url' => 'https://beta-restricted.example.test', 'token' => $token],
                ['id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'url' => 'https://alpha.example.test', 'token' => $token, 'proxy_url' => 'http://127.0.0.1:18888'],
            ],
        ], JSON_THROW_ON_ERROR));
        chmod($path, 0600);
        config(['incidents.collector_config' => $path]);
        $this->beforeApplicationDestroyed(fn () => unlink($path));

        return $path;
    }

    /** @return array<string, mixed> */
    public function incidentPeerBody(string $id = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', ?int $schedulerAge = 0, ?int $workerAge = 0): array
    {
        return ['hub_id' => $id, 'observed_at' => now()->toIso8601String(), 'heartbeats' => ['scheduler' => $schedulerAge, 'urgent_worker' => $workerAge], 'delivered_incidents' => []];
    }
}
