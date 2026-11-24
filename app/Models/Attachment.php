<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Attachment extends Model
{
    protected $guarded = [];

    protected $hidden = ['storage_key', 'extracted_text'];

    protected function casts(): array
    {
        return ['truncated' => 'boolean', 'generated' => 'boolean'];
    }

    /** Uploads that were never sent with a message. */
    public function scopePending($q)
    {
        return $q->whereNull('message_id');
    }

    /**
     * Remove uploads that were never sent and have waited longer than `thryve.pending_upload_minutes` (a send that
     * failed, a page that was closed or reloaded). Nobody can see or remove these in the app, yet they count toward the
     * per-message file limit, so they must expire. Their files are deleted too. A file that was sent with a message is
     * never touched. Pass a user id to clean up only that person's uploads.
     *
     * @return int how many were removed
     */
    public static function prunePending(?int $userId = null): int
    {
        $query = static::pending()->where('created_at', '<', now()->subMinutes(max(1, (int) config('thryve.pending_upload_minutes'))));
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }
        $removed = 0;
        foreach ($query->get() as $upload) {
            Storage::disk('private')->delete($upload->storage_key);
            $upload->delete();
            $removed++;
        }

        return $removed;
    }
}
