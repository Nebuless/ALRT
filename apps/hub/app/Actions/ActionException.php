<?php

namespace App\Actions;

use RuntimeException;

class ActionException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status = 409)
    {
        parent::__construct($errorCode);
    }
}
