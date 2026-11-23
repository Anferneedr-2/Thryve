<?php

namespace App\Analysis;

/** The agent's reply broke the contract. The reason is a short code, never the reply text itself. */
final class AnalysisInvalid extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
