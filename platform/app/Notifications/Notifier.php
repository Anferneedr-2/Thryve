<?php

namespace App\Notifications;

use App\Jobs\SendNotificationEmail;
use App\Mail\MailConfigurator;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates in-app notifications for the right people, once per dedupe key, and queues email when enabled.
 * Recipients are always real accounts: an employee can only ever receive their own.
 */
class Notifier
{
    /**
     * @param  User|null  $subject  the person an event is about (used by the 'subject' audience)
     * @return int number of new notifications created
     */
    public function notify(string $type, string $title, ?string $body = null, array $data = [], ?string $dedupeKey = null, ?User $subject = null): int
    {
        $def = (config("thryve.notification_types")[$type] ?? null) ?? throw new InvalidArgumentException("Unknown notification type [{$type}].");
        $created = 0;

        foreach ($this->recipients($def['audience'], $subject) as $user) {
            $notification = $this->createOnce($user, $type, $title, $body, $data, $dedupeKey);
            if (! $notification) {
                continue; // already sent for this window
            }
            $created++;
            if ($def['email']) {
                $this->queueEmail($notification, $user);
            }
        }

        return $created;
    }

    /** @return Collection<int, User> */
    private function recipients(string $audience, ?User $subject): Collection
    {
        return match ($audience) {
            'subject' => $subject && $subject->is_active ? collect([$subject]) : collect(),
            'admins' => $this->withRoles([Role::ADMIN, Role::SUPER_ADMIN]),
            'super_admins' => $this->withRoles([Role::SUPER_ADMIN]),
            default => throw new InvalidArgumentException("Unknown audience [{$audience}]."),
        };
    }

    private function withRoles(array $slugs): Collection
    {
        return User::where('is_active', true)->whereHas('role', fn ($r) => $r->whereIn('slug', $slugs))->get();
    }

    private function createOnce(User $user, string $type, string $title, ?string $body, array $data, ?string $dedupeKey): ?Notification
    {
        return DB::transaction(function () use ($user, $type, $title, $body, $data, $dedupeKey) {
            if ($dedupeKey !== null && Notification::where('user_id', $user->id)->where('dedupe_key', $dedupeKey)->exists()) {
                return null;
            }
            try {
                return Notification::create(['user_id' => $user->id, 'type' => $type, 'title' => $title, 'body' => $body, 'data' => $data ?: null, 'dedupe_key' => $dedupeKey]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                return null; // a concurrent request created it first
            }
        });
    }

    private function queueEmail(Notification $notification, User $user): void
    {
        $skip = match (true) {
            ! MailConfigurator::enabled() => 'email_disabled',
            ! $user->email => 'no_address',
            default => null,
        };
        $delivery = NotificationDelivery::create(['notification_id' => $notification->id, 'channel' => 'email', 'status' => $skip ? 'skipped' : 'queued', 'error' => $skip]);
        if (! $skip) {
            SendNotificationEmail::dispatch($delivery->id)->afterCommit();
        }
    }
}
