<?php

namespace Tests\Feature;

use App\Jobs\SendInvitationEmail;
use App\Mail\InvitationMail;
use App\Models\AuditEntry;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SimulatesDevices;
use Tests\TestCase;

/** A new account with an email address gets an invitation; whatever happens to the email, the account is kept. */
class InvitationEmailTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices;

    private const START = 'A&B<c>-start-"pw"-123';

    private User $super;

    private string $cookie;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
        $this->seed(RoleSeeder::class);
        $this->super = $this->makeUser(Role::SUPER_ADMIN);
        [, $this->cookie] = $this->signIn($this->super);
    }

    private function emailOn(string $host = 'smtp.example.com', int $port = 587): void
    {
        Settings::update(['smtp_enabled' => true, 'smtp_host' => $host, 'smtp_port' => $port, 'smtp_encryption' => 'none', 'smtp_from_address' => 'noreply@example.com', 'company_name' => 'Acme Co'], $this->super);
    }

    private function create(array $extra = [])
    {
        return $this->device($this->cookie)->postJson('/api/users', $extra + ['username' => 'new.person', 'name' => 'New Person', 'password' => self::START, 'role' => Role::EMPLOYEE]);
    }

    public function test_a_new_account_with_an_email_address_is_sent_its_username_and_starting_password(): void
    {
        Mail::fake();
        $this->emailOn();

        $r = $this->create(['email' => 'new.person@example.com'])->assertCreated()->assertJsonPath('data.invitation', 'queued');

        Mail::assertSent(InvitationMail::class, function (InvitationMail $m) {
            $m->assertSeeInText('Acme Co');
            $m->assertSeeInText('Username: new.person');
            $m->assertSeeInText('Starting password: '.self::START); // special characters arrive exactly as typed
            $m->assertSeeInText(url('/'));
            // The branded version carries the same details, escaped for HTML, and the brand blue sign-in button.
            $m->assertSeeInHtml('Acme Co');
            $m->assertSeeInHtml('new.person');
            $m->assertSeeInHtml(self::START);
            $m->assertSeeInHtml('href="'.url('/').'"', false);
            $m->assertSeeInHtml('#315eef', false);

            return $m->hasTo('new.person@example.com') && $m->hasSubject('Your Acme Co account is ready');
        });
        $this->assertTrue(User::find($r->json('data.id'))->must_change_password);
        $this->assertSame(1, AuditEntry::where('action', 'account.invitation_sent')->where('target_id', $r->json('data.id'))->count());
    }

    public function test_the_password_is_never_written_to_the_audit_log_and_the_queued_job_is_encrypted(): void
    {
        Queue::fake();
        $this->emailOn();

        $this->create(['email' => 'new.person@example.com'])->assertCreated();

        Queue::assertPushed(SendInvitationEmail::class, 1);
        $this->assertInstanceOf(ShouldBeEncrypted::class, new SendInvitationEmail(1, 1, self::START));
        $this->assertStringNotContainsString(self::START, json_encode(AuditEntry::all()->toArray()));
    }

    public function test_without_an_email_address_nothing_is_sent_and_the_admin_is_told(): void
    {
        Queue::fake();
        $this->emailOn();

        $this->create()->assertCreated()->assertJsonPath('data.invitation', 'no_email');

        Queue::assertNothingPushed();
        $this->assertSame(1, User::where('username', 'new.person')->count());
    }

    public function test_when_email_is_off_the_account_is_still_created_and_the_admin_is_told(): void
    {
        Queue::fake();

        $this->create(['email' => 'new.person@example.com'])->assertCreated()->assertJsonPath('data.invitation', 'email_off');

        Queue::assertNothingPushed();
        $this->assertSame(1, User::where('username', 'new.person')->count());
    }

    public function test_a_mail_server_failure_keeps_the_account_and_tells_the_admins_without_leaking_the_password(): void
    {
        $this->emailOn('127.0.0.1', 1); // nothing is listening here, so the connection fails for real

        $r = $this->create(['email' => 'new.person@example.com'])->assertCreated();

        $this->assertSame(1, User::where('username', 'new.person')->count());
        $this->assertSame('failed', $r->json('data.invitation'));
        $entry = AuditEntry::where('action', 'account.invitation_failed')->sole();
        $this->assertSame('connection_failed', $entry->metadata['category']);
        $note = Notification::where('type', 'account.invitation_failed')->where('user_id', $this->super->id)->sole();
        $this->assertStringContainsString('New Person', $note->body);
        $this->assertStringNotContainsString(self::START, json_encode([$entry->toArray(), $note->toArray()]));
    }

    public function test_a_password_that_is_no_longer_the_starting_password_is_never_emailed(): void
    {
        Mail::fake();
        Queue::fake();
        $this->emailOn();
        $id = $this->create(['email' => 'new.person@example.com'])->assertCreated()->json('data.id');
        $this->device($this->cookie)->postJson("/api/users/{$id}/reset-password", ['password' => 'a-different-password-9'])->assertOk();

        (new SendInvitationEmail($id, $this->super->id, self::START))->handle();

        Mail::assertNothingSent();
        $this->assertSame(1, AuditEntry::where('action', 'account.invitation_skipped')->count());
    }
}
