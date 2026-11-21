<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationEmail;
use App\Mail\NotificationMail;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\Role;
use App\Models\User;
use App\Notifications\Notifier;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SimulatesDevices;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices;

    private Notifier $notifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->notifier = app(Notifier::class);
    }

    private function enableEmail(): void
    {
        $super = $this->makeUser(Role::SUPER_ADMIN, ['username' => 'cfg.owner']);
        Settings::update(['smtp_enabled' => true, 'smtp_host' => 'smtp.example.com', 'smtp_from_address' => 'noreply@example.com'], $super);
    }

    // ---- who receives what ---------------------------------------------------------------------

    public function test_subject_alerts_go_only_to_that_employee(): void
    {
        [$a, $b] = [$this->makeUser(), $this->makeUser()];
        $this->makeUser(Role::ADMIN);

        $count = $this->notifier->notify('token.threshold', 'You have used 80% of today\'s tokens', null, ['percent' => 80], subject: $a);

        $this->assertSame(1, $count);
        $this->assertSame(1, Notification::where('user_id', $a->id)->count());
        $this->assertSame(0, Notification::where('user_id', $b->id)->count());
        $this->assertSame(1, Notification::count(), 'admins do not receive an employee\'s own token alert');
    }

    public function test_provider_alerts_go_to_every_active_admin_and_super_admin_only(): void
    {
        [$admin, $super] = [$this->makeUser(Role::ADMIN), $this->makeUser(Role::SUPER_ADMIN)];
        $inactive = $this->makeUser(Role::ADMIN, ['is_active' => false]);
        $employee = $this->makeUser();

        $this->assertSame(2, $this->notifier->notify('provider.failure', 'A provider is unavailable'));

        $this->assertEqualsCanonicalizing([$admin->id, $super->id], Notification::pluck('user_id')->all());
        $this->assertSame(0, Notification::where('user_id', $employee->id)->count());
        $this->assertSame(0, Notification::where('user_id', $inactive->id)->count());
    }

    public function test_system_events_go_to_super_admins_only(): void
    {
        $this->makeUser(Role::ADMIN);
        $super = $this->makeUser(Role::SUPER_ADMIN);

        $this->assertSame(1, $this->notifier->notify('system.update', 'Update available'));
        $this->assertSame($super->id, Notification::first()->user_id);
    }

    public function test_unknown_types_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->notifier->notify('made.up', 'x');
    }

    public function test_no_notification_type_exists_for_attendance_corrections(): void
    {
        $types = array_keys(config('thryve.notification_types'));
        $this->assertEmpty(array_filter($types, fn ($t) => str_contains($t, 'attendance')));
    }

    // ---- once per window -----------------------------------------------------------------------

    public function test_the_same_alert_for_the_same_window_is_created_once_per_person(): void
    {
        $u = $this->makeUser();

        $this->assertSame(1, $this->notifier->notify('token.threshold', '80% used', dedupeKey: 'tokens:2026-11-19:80', subject: $u));
        $this->assertSame(0, $this->notifier->notify('token.threshold', '80% used', dedupeKey: 'tokens:2026-11-19:80', subject: $u));
        $this->assertSame(1, $this->notifier->notify('token.threshold', '90% used', dedupeKey: 'tokens:2026-11-19:90', subject: $u));
        $this->assertSame(1, $this->notifier->notify('token.threshold', '80% used', dedupeKey: 'tokens:2026-11-20:80', subject: $u), 'a new day is a new window');
        $this->assertSame(3, Notification::count());
    }

    public function test_the_same_key_still_reaches_each_different_recipient(): void
    {
        $this->makeUser(Role::ADMIN);
        $this->makeUser(Role::ADMIN);

        $this->assertSame(2, $this->notifier->notify('provider.threshold', 'Provider at 80%', dedupeKey: 'prov:1:80'));
        $this->assertSame(0, $this->notifier->notify('provider.threshold', 'Provider at 80%', dedupeKey: 'prov:1:80'));
    }

    public function test_notifications_without_a_key_are_never_blocked(): void
    {
        $u = $this->makeUser();
        $this->notifier->notify('token.threshold', 'one', subject: $u);
        $this->notifier->notify('token.threshold', 'two', subject: $u);

        $this->assertSame(2, Notification::where('user_id', $u->id)->count());
    }

    // ---- the bell: own notifications only ------------------------------------------------------

    public function test_a_person_lists_and_reads_only_their_own_notifications(): void
    {
        [$me, $other] = [$this->makeUser(), $this->makeUser()];
        $this->notifier->notify('token.threshold', 'Mine one', subject: $me);
        $this->notifier->notify('token.threshold', 'Mine two', subject: $me);
        $this->notifier->notify('token.threshold', 'Theirs', subject: $other);
        [, $c] = $this->signIn($me);

        $r = $this->device($c)->getJson('/api/notifications')->assertOk();
        $this->assertSame(['Mine two', 'Mine one'], array_column($r->json('data'), 'title'));
        $this->assertSame(2, $r->json('meta.unread'));
        $this->assertStringNotContainsString('Theirs', $r->getContent());

        $theirs = Notification::where('user_id', $other->id)->first();
        $this->device($c)->postJson("/api/notifications/{$theirs->id}/read")->assertStatus(404);
        $this->assertNull($theirs->fresh()->read_at, 'someone else\'s notification is untouched');
    }

    public function test_marking_read_one_and_all_updates_the_unread_count(): void
    {
        $me = $this->makeUser();
        foreach (['a', 'b', 'c'] as $t) {
            $this->notifier->notify('token.threshold', $t, subject: $me);
        }
        [, $c] = $this->signIn($me);
        $first = Notification::where('title', 'a')->first();

        $this->device($c)->postJson("/api/notifications/{$first->id}/read")->assertOk()->assertJsonPath('data.unread', 2);
        $this->assertCount(2, $this->device($c)->getJson('/api/notifications?unread=1')->json('data'));
        $this->device($c)->postJson('/api/notifications/read-all')->assertOk()->assertJsonPath('data.unread', 0);
        $this->assertCount(0, $this->device($c)->getJson('/api/notifications?unread=1')->json('data'));
        $this->assertCount(3, $this->device($c)->getJson('/api/notifications')->json('data'), 'read ones stay in the list');
    }

    public function test_read_state_persists_and_other_people_are_unaffected_by_read_all(): void
    {
        [$me, $other] = [$this->makeUser(), $this->makeUser()];
        $this->notifier->notify('token.threshold', 'x', subject: $me);
        $this->notifier->notify('token.threshold', 'y', subject: $other);
        [, $c] = $this->signIn($me);

        $this->device($c)->postJson('/api/notifications/read-all')->assertOk();

        $this->assertNotNull(Notification::where('user_id', $me->id)->first()->read_at);
        $this->assertNull(Notification::where('user_id', $other->id)->first()->read_at);
    }

    public function test_the_bell_endpoints_need_a_session(): void
    {
        $this->device(null)->getJson('/api/notifications')->assertStatus(401);
        $this->device(null)->postJson('/api/notifications/read-all')->assertStatus(401);
        $this->device(null)->postJson('/api/notifications/1/read')->assertStatus(401);
    }

    public function test_the_heartbeat_carries_the_unread_count(): void
    {
        $me = $this->makeUser();
        $this->notifier->notify('token.threshold', 'x', subject: $me);
        [, $c] = $this->signIn($me);

        $this->device($c)->postJson('/api/attendance/heartbeat')->assertOk()->assertJsonPath('data.unread_notifications', 1);
    }

    public function test_listing_is_paginated(): void
    {
        $me = $this->makeUser();
        foreach (range(1, 5) as $i) {
            $this->notifier->notify('token.threshold', "n$i", subject: $me);
        }
        [, $c] = $this->signIn($me);

        $r = $this->device($c)->getJson('/api/notifications?per_page=2')->assertOk();
        $this->assertCount(2, $r->json('data'));
        $this->assertSame(3, $r->json('meta.last_page'));
        $this->device($c)->getJson('/api/notifications?per_page=500')->assertStatus(422);
    }

    // ---- email ---------------------------------------------------------------------------------

    public function test_email_is_skipped_with_a_reason_when_smtp_is_off(): void
    {
        Queue::fake();
        $u = $this->makeUser();

        $this->notifier->notify('token.threshold', 'x', subject: $u);

        $d = NotificationDelivery::first();
        $this->assertSame('skipped', $d->status);
        $this->assertSame('email_disabled', $d->error);
        Queue::assertNothingPushed();
        $this->assertSame(1, Notification::count(), 'the in-app notification is still created');
    }

    public function test_email_is_queued_when_smtp_is_on_and_the_person_has_an_address(): void
    {
        Queue::fake();
        $this->enableEmail();
        [$withEmail, $without] = [$this->makeUser(attrs: ['email' => 'a@example.com']), $this->makeUser(attrs: ['email' => null])];

        $this->notifier->notify('token.threshold', 'x', subject: $withEmail);
        $this->notifier->notify('token.threshold', 'y', subject: $without);

        Queue::assertPushed(SendNotificationEmail::class, 1);
        $this->assertSame(['queued', 'skipped'], NotificationDelivery::orderBy('id')->pluck('status')->all());
        $this->assertSame('no_address', NotificationDelivery::where('status', 'skipped')->first()->error);
    }

    public function test_types_that_are_not_emailed_never_queue_email(): void
    {
        Queue::fake();
        $this->enableEmail();
        $this->makeUser(Role::SUPER_ADMIN, ['email' => 's@example.com']);

        $this->notifier->notify('system.health', 'Disk space low');

        $this->assertSame(0, NotificationDelivery::count());
        Queue::assertNothingPushed();
    }

    public function test_the_job_sends_the_email_and_marks_it_sent(): void
    {
        Mail::fake();
        $this->enableEmail();
        $u = $this->makeUser(attrs: ['email' => 'person@example.com']);
        $this->notifier->notify('token.threshold', 'You have used 90% of today\'s tokens', 'It resets at midnight.', subject: $u);
        $d = NotificationDelivery::first();

        (new SendNotificationEmail($d->id))->handle();

        Mail::assertSent(NotificationMail::class, fn ($m) => $m->hasTo('person@example.com') && $m->heading === 'You have used 90% of today\'s tokens');
        $this->assertSame('sent', $d->fresh()->status);
        $this->assertNotNull($d->fresh()->sent_at);
        (new SendNotificationEmail($d->id))->handle();
        Mail::assertSent(NotificationMail::class, 1); // sending twice is a no-op
    }

    public function test_email_failure_is_recorded_safely_and_never_removes_the_in_app_notification(): void
    {
        $super = $this->makeUser(Role::SUPER_ADMIN, ['username' => 'cfg.owner2']);
        // Nothing is listening on this port, so the connection fails for real.
        Settings::update(['smtp_enabled' => true, 'smtp_host' => '127.0.0.1', 'smtp_port' => 1, 'smtp_encryption' => 'none', 'smtp_from_address' => 'noreply@example.com', 'smtp_username' => 'user-x', 'smtp_password' => 'pw-should-never-leak'], $super);
        $u = $this->makeUser(attrs: ['email' => 'person@example.com']);
        Queue::fake();
        $this->notifier->notify('token.threshold', 'x', subject: $u);
        $d = NotificationDelivery::first();

        for ($i = 0; $i < 3; $i++) {
            try {
                (new SendNotificationEmail($d->id))->handle();
            } catch (\Throwable) {
            }
        }

        $d = $d->fresh();
        $this->assertSame('failed', $d->status);
        $this->assertSame(3, $d->attempts);
        $this->assertSame('connection_failed', $d->error);
        $this->assertStringNotContainsString('pw-should-never-leak', json_encode($d->toArray()));
        $this->assertSame(1, Notification::where('user_id', $u->id)->count());
        $this->assertNull(Notification::first()->read_at);
    }

    public function test_the_job_skips_people_who_became_inactive_or_lost_their_address(): void
    {
        Mail::fake();
        Queue::fake(); // the queue is synchronous in tests; hold the job back so the person changes before it runs
        $this->enableEmail();
        $u = $this->makeUser(attrs: ['email' => 'p@example.com']);
        $this->notifier->notify('token.threshold', 'x', subject: $u);
        $u->forceFill(['is_active' => false])->save();

        (new SendNotificationEmail(NotificationDelivery::first()->id))->handle();

        Mail::assertNothingSent();
        $this->assertSame('skipped', NotificationDelivery::first()->status);
    }

    public function test_attendance_corrections_send_no_notification_or_email(): void
    {
        Mail::fake();
        Queue::fake();
        $this->enableEmail();
        $emp = $this->makeUser(attrs: ['email' => 'e@example.com']);
        [, $c] = $this->signIn($this->makeUser(Role::ADMIN));

        $this->device($c)->postJson('/api/attendance/corrections', ['user_id' => $emp->id, 'work_date' => '2026-11-10', 'active_seconds' => 3600])->assertOk();

        $this->assertSame(0, Notification::count());
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }
}
