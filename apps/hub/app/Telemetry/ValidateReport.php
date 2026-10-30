<?php

namespace App\Telemetry;

use App\Models\Machine;
use Illuminate\Support\Facades\File;
use stdClass;

class ValidateReport
{
    private readonly SchemaMatcher $matcher;

    public function __construct()
    {
        $this->matcher = new SchemaMatcher(json_decode(File::get(base_path('../../contracts/telemetry-v1.schema.json')), true, flags: JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function validate(Machine $machine, mixed $report): array
    {
        if ($report instanceof stdClass && isset($report->protocol_version) && is_int($report->protocol_version) && $report->protocol_version !== 1) {
            throw new TelemetryException('protocol_version_unsupported');
        }
        if (! $this->matcher->matches($report) || $report->machine_id !== $machine->id) {
            throw new TelemetryException('invalid_report');
        }

        return json_decode(json_encode($report, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }
}
