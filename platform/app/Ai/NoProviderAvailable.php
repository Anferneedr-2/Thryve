<?php

namespace App\Ai;

use RuntimeException;

class NoProviderAvailable extends RuntimeException
{
    public function __construct(public readonly ?string $lastCategory = null)
    {
        parent::__construct('No AI provider is available right now. Please try again shortly.');
    }
}
