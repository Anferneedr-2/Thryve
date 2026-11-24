<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use Illuminate\Console\Command;

class PruneUploads extends Command
{
    protected $signature = 'thryve:prune-uploads';

    protected $description = 'Remove uploads that were never sent with a message and have waited too long, together with their files';

    public function handle(): int
    {
        $removed = Attachment::prunePending();
        $this->info("Removed {$removed} expired unsent upload(s).");

        return self::SUCCESS;
    }
}
