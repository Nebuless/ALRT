<?php

namespace App\Citizens;

use App\Telemetry\SchemaMatcher;
use App\Telemetry\TelemetryException;
use Illuminate\Support\Carbon;
use stdClass;

class ValidateCitizens
{
    /** @return array<string, mixed> */
    public function exchange(stdClass $body): array
    {
        $hash = ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'];
        $uuid = ['type' => 'string', 'pattern' => '^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$'];
        $time = ['type' => 'string', 'format' => 'date-time', 'maxLength' => 40];
        $process = $this->object(['boot_id' => $uuid, 'pid' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 2147483647], 'start_ticks' => ['type' => 'integer', 'minimum' => 1]]);
        $row = $this->object([
            'id' => $hash, 'parent_id' => ['anyOf' => [$hash, ['type' => 'null']]],
            'tool' => ['enum' => ['pi', 'omo']], 'state' => ['enum' => ['working', 'waiting', 'idle', 'stopped', 'failed', 'unknown']],
            'observed_at' => $time, 'runtime_id' => $hash, 'attribution' => ['enum' => ['dedicated', 'shared', 'unavailable']],
            'process' => ['anyOf' => [$process, ['type' => 'null']]],
            'cpu_percent' => ['anyOf' => [['type' => 'number', 'minimum' => 0], ['type' => 'null']]],
            'rss_bytes' => ['anyOf' => [['type' => 'integer', 'minimum' => 0], ['type' => 'null']]], 'can_terminate' => ['type' => 'boolean'],
        ]);
        $row['required'] = array_values(array_diff($row['required'], ['parent_id']));
        $schema = $this->object([
            'nonce' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9]{64}$'], 'boot_id' => $uuid, 'bridge_id' => $uuid,
            'sequence' => ['type' => 'integer', 'minimum' => 1], 'sampled_at' => $time,
            'coverage' => ['enum' => ['complete', 'partial', 'unavailable']],
            'citizens' => ['type' => 'array', 'maxItems' => 128, 'items' => $row],
            'results' => ['type' => 'array', 'maxItems' => 64, 'items' => $this->object([
                'id' => $uuid, 'status' => ['enum' => ['terminated', 'already_exited', 'identity_changed', 'permission_denied', 'unsupported', 'expired', 'unknown']],
            ])],
        ]);
        if (! (new SchemaMatcher($schema))->matches($body)) {
            throw new TelemetryException('invalid_citizens');
        }
        $data = json_decode(json_encode($body, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $sample = Carbon::parse($data['sampled_at'])->utc();
        if ($sample->gt(now()) || $sample->lt(now()->subSeconds(10))) {
            throw new TelemetryException('stale_citizens', 409);
        }
        $data['sampled_at'] = $sample->toISOString();
        $ids = [];
        foreach ($data['citizens'] as &$citizen) {
            $observed = Carbon::parse($citizen['observed_at'])->utc();
            if (isset($ids[$citizen['id']]) || $observed->gt($sample) || ($citizen['process'] !== null && $citizen['process']['boot_id'] !== $data['boot_id'])) {
                throw new TelemetryException('invalid_citizens');
            }
            $ids[$citizen['id']] = true;
            $citizen['observed_at'] = $observed->toISOString();
            $citizen['parent_id'] ??= null;
            if ($citizen['attribution'] === 'unavailable' || $citizen['process'] === null) {
                $citizen['cpu_percent'] = $citizen['rss_bytes'] = null;
                $citizen['can_terminate'] = false;
            }
            if ($citizen['attribution'] !== 'dedicated') {
                $citizen['can_terminate'] = false;
            }
        }
        unset($citizen);
        if (count(array_unique(array_column($data['results'], 'id'))) !== count($data['results'])) {
            throw new TelemetryException('invalid_citizens');
        }

        return $data;
    }

    /** @return array{revision: string, confirmation: true} */
    public function termination(stdClass $body): array
    {
        if (! (new SchemaMatcher($this->object([
            'revision' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'], 'confirmation' => ['const' => true],
        ])))->matches($body)) {
            throw new TelemetryException('invalid_citizens');
        }

        return ['revision' => $body->revision, 'confirmation' => true];
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function object(array $properties): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }
}
