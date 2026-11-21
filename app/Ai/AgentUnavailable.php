<?php

namespace App\Ai;

use RuntimeException;

/** The chosen agent cannot take requests at all (it does not exist, is disabled, or has no key). */
class AgentUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This chatbot is not available right now.');
    }
}
