<?php

namespace App\Ai;

final class FileGenerationFailed extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The file could not be prepared. Try again or ask for a shorter document.');
    }
}
