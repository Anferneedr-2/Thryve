<?php

namespace App\Updates;

use RuntimeException;

/** An update problem whose message is safe to show to the Super Admin. */
class UpdateException extends RuntimeException
{
    public function __construct(public readonly string $codeName, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
