<?php

namespace Tests\Support;

use App\Updates\UpdateSteps;

class RecordingSteps extends UpdateSteps
{
    public array $calls = [];

    public ?\Throwable $failMigrate = null;

    public ?\Closure $onMigrate = null;

    public function migrate(): void
    {
        $this->calls[] = 'migrate';
        if ($this->onMigrate) {
            ($this->onMigrate)();
        }
        if ($this->failMigrate) {
            throw $this->failMigrate;
        }
    }

    public function seed(): void
    {
        $this->calls[] = 'seed';
    }

    public function clearCaches(): void
    {
        $this->calls[] = 'caches';
    }
}

