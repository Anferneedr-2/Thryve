<?php

namespace App\Jobs;

use App\Mail\MailConfigurator;
use App\Mail\NotificationMail;
use App\Models\NotificationDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/** Sends one notification by email. A failure here is recorded on the delivery and never touches the in-app notification. */
class SendNotificationEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $deliveryId) {}

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $delivery = NotificationDelivery::with('notification.user')->find($this->deliveryId);
        if (! $delivery || $delivery->status === 'sent') {
            return;
        }
        $notification = $delivery->notification;
        $user = $notification->user;

        if (! MailConfigurator::enabled() || ! $user->email || ! $user->is_active) {
            $delivery->update(['status' => 'skipped', 'error' => 'not_deliverable']);

            return;
        }

        $delivery->increment('attempts');
        try {
            MailConfigurator::apply();
            Mail::mailer('smtp')->to($user->email)->send(new NotificationMail($notification->title, $notification->body));
            $delivery->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
        } catch (\Throwable $e) {
            report($e);
            $delivery->update(['status' => $delivery->attempts >= $this->tries ? 'failed' : 'queued', 'error' => MailConfigurator::category($e)]);
            if ($delivery->attempts < $this->tries) {
                throw $e; // let the queue retry later
            }
        }
    }
}
