<?php

namespace App\Install;

use RuntimeException;

/** A failure whose message is safe to show to the person running the installer. */
class InstallException extends RuntimeException
{
    public function __construct(string $message, public readonly string $step = '')
    {
        parent::__construct($message);
    }
}
