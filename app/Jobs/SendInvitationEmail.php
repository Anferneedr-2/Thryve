<?php

namespace App\Jobs;

use App\Mail\InvitationMail;
use App\Mail\MailConfigurator;
use App\Models\User;
use App\Notifications\Notifier;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails a new person their username and starting password. The password travels in this job, so the payload is
 * encrypted in the queue and it is never written to the audit log. A failure never undoes the account: the admins are
 * told in the app and give the person the password themselves.
 */
class SendInvitationEmail implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $userId, public ?int $createdById, public string $startingPassword) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $user = User::find($this->userId);
        $creator = $this->createdById ? User::find($this->createdById) : null;

        // Never email a password that is no longer the one the person has.
        if (! $user || ! $user->email || ! $user->is_active || ! $user->must_change_password || ! MailConfigurator::enabled() || ! Hash::check($this->startingPassword, $user->password)) {
            if ($user) {
                Audit::log('account.invitation_skipped', $creator, $user, ['reason' => 'not_deliverable']);
            }

            return;
        }

        MailConfigurator::apply();
        Mail::mailer('smtp')->to($user->email)->send(new InvitationMail($user->name, $user->username, $this->startingPassword, (string) Settings::get('company_name'), url('/')));
        Audit::log('account.invitation_sent', $creator, $user);
    }

    public function failed(Throwable $e): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }
        $category = MailConfigurator::category($e);
        Audit::log('account.invitation_failed', $this->createdById ? User::find($this->createdById) : null, $user, ['category' => $category]);
        app(Notifier::class)->notify(
            'account.invitation_failed',
            'Invitation email not sent',
            "The invitation for {$user->name} could not be sent. ".MailConfigurator::describe($category, Settings::get('smtp_host')).' Give them their starting password yourself.',
            ['user_id' => $user->id],
            "invitation-failed:{$user->id}",
        );
    }
}
