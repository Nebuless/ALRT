<?php

namespace App\Telemetry;

use RuntimeException;

class TelemetryException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status = 422)
    {
        parent::__construct($errorCode);
    }
}
