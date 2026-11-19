<?php

namespace App\Ai;

use RuntimeException;

class TokenLimitReached extends RuntimeException
{
    public function __construct(public readonly string $scope = 'user')
    {
        parent::__construct($scope === 'system' ? 'The system token budget for today is used up.' : 'Your token allowance for today is used up. It resets at midnight (Philippine time).');
    }
}
