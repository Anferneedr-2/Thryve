<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Brings a chatbot's conversations over from another installation, if a transfer file has been put in
 * storage/app/transfer/general-chats.json (made there with `php artisan thryve:export-chats`).
 *
 * The conversations are NOT in this repository: that folder is ignored by git, because they are private. Where there is no
 * file this does nothing, so it is harmless on every other machine. Where the people in the file do not exist yet, they are
 * listed in the log and skipped; run `php artisan thryve:import-chats` once they do (it is safe to repeat).
 */
return new class extends Migration
{
    public function up(): void
    {
        $file = storage_path('app/transfer/general-chats.json');
        if (! is_file($file)) {
            return;
        }
        $code = Artisan::call('thryve:import-chats', ['file' => $file]);
        $output = trim(Artisan::output());
        Log::info("Chat transfer import:\n{$output}");
        if ($code !== 0) {
            throw new RuntimeException("The chat transfer file could not be imported: {$output}");
        }
    }

    public function down(): void
    {
        // Imported conversations are real data and are never removed automatically.
    }
};
