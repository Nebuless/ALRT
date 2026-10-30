<?php

namespace App\Telemetry;

use DateTimeImmutable;
use Illuminate\Support\Facades\File;

class ValidateDelivery
{
    private readonly SchemaMatcher $matcher;

    public function __construct()
    {
        $this->matcher = new SchemaMatcher(json_decode(File::get(base_path('../../contracts/delivery-v1.schema.json')), true, flags: JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function validate(mixed $delivery): array
    {
        if (! $this->matcher->matches($delivery)) {
            throw new TelemetryException('invalid_report');
        }
        $seen = [];
        foreach ($delivery->acknowledgements as $ack) {
            if (isset($seen[strtolower($ack->hub_id)])) {
                throw new TelemetryException('invalid_report');
            }
            $seen[strtolower($ack->hub_id)] = true;
        }
        foreach ($delivery->lost_ranges as $range) {
            if ($range->first_sequence > $range->last_sequence || new DateTimeImmutable($range->from) > new DateTimeImmutable($range->to)) {
                throw new TelemetryException('invalid_report');
            }
        }

        return json_decode(json_encode($delivery, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }
}
