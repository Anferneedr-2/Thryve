<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\AuditEntry;
use App\Models\Role;
use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SimulatesDevices;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
        $this->seed(\Database\Seeders\RoleSeeder::class);
        Storage::fake(config('thryve.private_disk'));
    }

    private function superCookie(array $attrs = []): string
    {
        return $this->cookieFor(Role::SUPER_ADMIN, $attrs);
    }

    // ---- SMTP settings and secrets -------------------------------------------------------------

    public function test_the_smtp_password_is_encrypted_at_rest_and_never_returned(): void
    {
        $c = $this->superCookie();

        $r = $this->device($c)->patchJson('/api/settings', ['smtp_host' => 'smtp.example.com', 'smtp_password' => 'p4ss-never-leak'])->assertOk();

        $stored = Setting::where('key', 'smtp_password')->value('value');
        $this->assertNotNull($stored);
        $this->assertStringNotContainsString('p4ss-never-leak', $stored, 'stored encrypted');
        $this->assertSame('p4ss-never-leak', Settings::get('smtp_password'), 'but usable by the server');
        $this->assertStringNotContainsString('p4ss-never-leak', $r->getContent());
        $this->assertNull($r->json('data.settings.smtp_password.value'));
        $this->assertTrue($r->json('data.settings.smtp_password.is_set'));
        $this->assertStringNotContainsString('p4ss-never-leak', $this->device($c)->getJson('/api/settings')->getContent());
        $this->assertStringNotContainsString('p4ss-never-leak', json_encode(AuditEntry::all()->toArray()));
    }

    public function test_the_smtp_password_can_be_cleared_and_changing_it_is_audited_without_the_value(): void
    {
        $c = $this->superCookie();
        $this->device($c)->patchJson('/api/settings', ['smtp_password' => 'first-secret-1'])->assertOk();
        $this->device($c)->patchJson('/api/settings', ['smtp_password' => 'second-secret-2'])->assertOk();
        $this->assertSame('second-secret-2', Settings::get('smtp_password'));

        $this->device($c)->patchJson('/api/settings', ['smtp_password' => null])->assertOk();

        $this->assertNull(Settings::get('smtp_password'));
        $this->assertSame(0, Setting::where('key', 'smtp_password')->count());
        $audits = AuditEntry::where('action', 'settings.updated')->get();
        $this->assertCount(3, $audits);
        $this->assertSame(['smtp_password'], $audits[0]->metadata['protected_changed']);
        $this->assertStringNotContainsString('secret', json_encode($audits->pluck('metadata')));
    }

    public function test_email_settings_are_validated_and_typed(): void
    {
        $c = $this->superCookie();

        $this->device($c)->patchJson('/api/settings', ['smtp_port' => 99999])->assertStatus(422);
        $this->device($c)->patchJson('/api/settings', ['smtp_encryption' => 'starttls'])->assertStatus(422);
        $this->device($c)->patchJson('/api/settings', ['smtp_host' => 'bad host;rm'])->assertStatus(422);
        $this->device($c)->patchJson('/api/settings', ['smtp_from_address' => 'not-an-email'])->assertStatus(422);
        $this->device($c)->patchJson('/api/settings', ['smtp_enabled' => 'maybe'])->assertStatus(422);

        $this->device($c)->patchJson('/api/settings', ['smtp_enabled' => true, 'smtp_port' => 465, 'smtp_encryption' => 'ssl', 'smtp_host' => 'mail.example.com', 'smtp_from_address' => 'noreply@example.com', 'smtp_username' => null])->assertOk();
        $this->assertTrue(Settings::get('smtp_enabled'));
        $this->assertSame(465, Settings::get('smtp_port'));
        $this->assertNull(Settings::get('smtp_username'));
    }

    public function test_nullable_settings_can_be_cleared_but_required_ones_cannot(): void
    {
        $c = $this->superCookie();
        $this->device($c)->patchJson('/api/settings', ['smtp_host' => 'mail.example.com'])->assertOk();
        $this->device($c)->patchJson('/api/settings', ['smtp_host' => null])->assertOk();
        $this->assertNull(Settings::get('smtp_host'));

        $this->device($c)->patchJson('/api/settings', ['company_name' => null])->assertStatus(422);
    }

    // ---- SMTP test -----------------------------------------------------------------------------

    public function test_smtp_test_sends_a_real_message_through_the_configured_server(): void
    {
        Mail::fake();
        $c = $this->superCookie(['email' => 'owner@example.com']);
        $this->device($c)->patchJson('/api/settings', ['smtp_host' => 'smtp.example.com', 'smtp_from_address' => 'noreply@example.com'])->assertOk();

        $r = $this->device($c)->postJson('/api/settings/smtp-test', ['to' => 'inbox@example.com'])->assertOk();

        $this->assertTrue($r->json('data.ok'));
        Mail::assertSent(NotificationMail::class, fn ($m) => $m->hasTo('inbox@example.com') && $m->heading === 'Test email');
        $this->assertTrue(Cache::get('smtp:last_test')['ok']);
        $this->assertSame(1, AuditEntry::where('action', 'settings.smtp_tested')->count());
    }

    public function test_smtp_test_defaults_to_the_signed_in_persons_address(): void
    {
        Mail::fake();
        $c = $this->superCookie(['email' => 'owner@example.com']);
        $this->device($c)->patchJson('/api/settings', ['smtp_host' => 'smtp.example.com', 'smtp_from_address' => 'noreply@example.com'])->assertOk();

        $this->device($c)->postJson('/api/settings/smtp-test')->assertOk();

        Mail::assertSent(NotificationMail::class, fn ($m) => $m->hasTo('owner@example.com'));
    }

    public function test_a_failing_smtp_server_fails_safely_without_leaking_anything(): void
    {
        $c = $this->superCookie(['email' => 'owner@example.com']);
        $this->device($c)->patchJson('/api/settings', [
            'smtp_host' => '127.0.0.2', 'smtp_port' => 1, 'smtp_encryption' => 'none', 'smtp_from_address' => 'noreply@example.com',
            'smtp_username' => 'mail-user-xyz', 'smtp_password' => 'mail-pass-xyz',
        ])->assertOk();

        $r = $this->device($c)->postJson('/api/settings/smtp-test')->assertOk();

        $this->assertFalse($r->json('data.ok'));
        $this->assertSame('connection_failed', $r->json('data.category'));
        $this->assertStringContainsString('Could not connect', $r->json('data.message'));
        foreach (['mail-pass-xyz', 'mail-user-xyz', '127.0.0.2'] as $secret) {
            $this->assertStringNotContainsString($secret, $r->getContent());
            $this->assertStringNotContainsString($secret, json_encode(AuditEntry::where('action', 'settings.smtp_tested')->get()->toArray()));
        }
        $this->assertFalse(Cache::get('smtp:last_test')['ok']);
    }

    public function test_smtp_test_needs_a_server_a_from_address_and_a_recipient(): void
    {
        $c = $this->superCookie(); // this user has an email by factory default, so clear it
        DB::table('users')->update(['email' => null]);

        $this->device($c)->postJson('/api/settings/smtp-test', ['to' => 'x@example.com'])->assertStatus(422);
        $this->device($c)->patchJson('/api/settings', ['smtp_host' => 'smtp.example.com', 'smtp_from_address' => 'noreply@example.com'])->assertOk();
        $this->device($c)->postJson('/api/settings/smtp-test')->assertStatus(422)->assertJsonPath('error.details.to.0', 'Enter an address to send the test to.');
        $this->device($c)->postJson('/api/settings/smtp-test', ['to' => 'not-an-email'])->assertStatus(422);
    }

    public function test_only_a_super_admin_can_use_the_smtp_test_branding_and_health_endpoints(): void
    {
        foreach ([Role::EMPLOYEE, Role::ADMIN] as $role) {
            $c = $this->cookieFor($role);
            $this->device($c)->postJson('/api/settings/smtp-test', ['to' => 'x@example.com'])->assertStatus(403);
            $this->device($c)->postJson('/api/settings/branding', ['logo' => UploadedFile::fake()->image('l.png', 50, 50)])->assertStatus(403);
            $this->device($c)->deleteJson('/api/settings/branding/logo')->assertStatus(403);
            $this->device($c)->getJson('/api/system/health')->assertStatus(403);
        }
        $this->device(null)->getJson('/api/system/health')->assertStatus(401);
    }

    public function test_the_mailer_picks_up_the_saved_settings(): void
    {
        $c = $this->superCookie();
        $this->device($c)->patchJson('/api/settings', ['smtp_host' => 'smtp.example.com', 'smtp_port' => 465, 'smtp_encryption' => 'ssl', 'smtp_username' => 'u', 'smtp_password' => 'pw', 'smtp_from_address' => 'noreply@example.com', 'smtp_from_name' => 'Acme AI'])->assertOk();

        \App\Mail\MailConfigurator::apply();

        $this->assertSame('smtp.example.com', config('mail.mailers.smtp.host'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('pw', config('mail.mailers.smtp.password'));
        $this->assertSame('Acme AI', config('mail.from.name'));
        $this->assertTrue(\App\Mail\MailConfigurator::enabled() === false, 'saving details does not switch email on by itself');
    }

    public function test_a_gmail_login_failure_explains_the_app_password_without_showing_the_host(): void
    {
        $gmail = \App\Mail\MailConfigurator::describe('auth_failed', 'smtp.gmail.com');
        $this->assertStringContainsString('App Password', $gmail);
        $this->assertStringContainsString('did not accept the username or password', $gmail);
        $this->assertStringNotContainsString('smtp.gmail.com', $gmail);

        $other = \App\Mail\MailConfigurator::describe('auth_failed', 'smtp.example.com');
        $this->assertStringNotContainsString('App Password', $other);
        $this->assertStringNotContainsString('App Password', \App\Mail\MailConfigurator::describe('auth_failed'));
        $this->assertStringNotContainsString('App Password', \App\Mail\MailConfigurator::describe('connection_failed', 'smtp.gmail.com'));
    }

    public function test_a_certificate_failure_is_reported_as_a_secure_connection_problem_not_a_connection_problem(): void
    {
        $cert = new \RuntimeException('Connection could not be established with host "smtp.example.com:587": stream_socket_client(): SSL operation failed with code 1. OpenSSL Error messages: certificate verify failed');
        $refused = new \RuntimeException('Connection could not be established with host "ssl://smtp.example.com:465": Unable to connect (connection refused)');
        $login = new \RuntimeException('Failed to authenticate on SMTP server. Expected response code "235" but got code "535"');

        $this->assertSame('tls_failed', \App\Mail\MailConfigurator::category($cert));
        $this->assertSame('connection_failed', \App\Mail\MailConfigurator::category($refused));
        $this->assertSame('auth_failed', \App\Mail\MailConfigurator::category($login));
    }

    public function test_port_465_always_uses_a_secure_connection_from_the_start(): void
    {
        $c = $this->superCookie();
        $this->device($c)->patchJson('/api/settings', ['smtp_host' => 'smtp.example.com', 'smtp_port' => 465, 'smtp_encryption' => 'tls'])->assertOk();
        \App\Mail\MailConfigurator::apply();
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));

        $this->device($c)->patchJson('/api/settings', ['smtp_port' => 587])->assertOk();
        \App\Mail\MailConfigurator::apply();
        $this->assertSame('smtp', config('mail.mailers.smtp.scheme'));
    }

    // ---- branding ------------------------------------------------------------------------------

    public function test_branding_is_public_and_reflects_settings(): void
    {
        $c = $this->superCookie();
        $this->device(null)->getJson('/api/branding')->assertOk()->assertJsonPath('data.company_name', 'Thryve')->assertJsonPath('data.has_logo', false);

        $this->device($c)->patchJson('/api/settings', ['company_name' => 'Acme Corp', 'default_theme' => 'dark'])->assertOk();

        $this->device(null)->getJson('/api/branding')->assertJsonPath('data.company_name', 'Acme Corp')->assertJsonPath('data.default_theme', 'dark');
    }

    public function test_logo_and_favicon_upload_serve_replace_and_remove(): void
    {
        $c = $this->superCookie();
        $this->get('/branding/logo')->assertStatus(404);

        $this->device($c)->post('/api/settings/branding', ['logo' => UploadedFile::fake()->image('anything.png', 120, 60), 'favicon' => UploadedFile::fake()->image('fav.png', 32, 32)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.has_logo', true)->assertJsonPath('data.has_favicon', true);

        $img = $this->get('/branding/logo')->assertOk();
        $img->assertHeader('Content-Type', 'image/png');
        $img->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString("default-src 'none'", $img->headers->get('Content-Security-Policy'));
        $this->get('/branding/favicon')->assertOk();

        // Replacing leaves exactly one file per kind.
        $this->device($c)->post('/api/settings/branding', ['logo' => UploadedFile::fake()->image('new.jpg', 100, 100)], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(['branding/logo.jpg'], array_values(array_filter(Storage::disk('private')->allFiles('branding'), fn ($f) => str_contains($f, 'logo'))));
        $this->get('/branding/logo')->assertHeader('Content-Type', 'image/jpeg');

        $this->device($c)->deleteJson('/api/settings/branding/logo')->assertOk()->assertJsonPath('data.has_logo', false);
        $this->get('/branding/logo')->assertStatus(404);
        $this->assertGreaterThanOrEqual(2, AuditEntry::where('action', 'settings.branding_updated')->count());
    }

    public function test_a_separate_logo_for_the_dark_theme_can_be_uploaded_served_and_removed(): void
    {
        $c = $this->superCookie();
        $this->device(null)->getJson('/api/branding')->assertJsonPath('data.has_logo_dark', false);
        $this->get('/branding/logo_dark')->assertStatus(404);

        // The two logos are independent: uploading one never replaces the other.
        $up = fn (array $f) => $this->device($c)->post('/api/settings/branding', $f, ['Accept' => 'application/json']);
        $up(['logo' => UploadedFile::fake()->image('light.png', 120, 60)])->assertOk()->assertJsonPath('data.has_logo', true)->assertJsonPath('data.has_logo_dark', false);
        $up(['logo_dark' => UploadedFile::fake()->image('dark.png', 120, 60)])->assertOk()->assertJsonPath('data.has_logo', true)->assertJsonPath('data.has_logo_dark', true);

        $img = $this->get('/branding/logo_dark')->assertOk();
        $img->assertHeader('Content-Type', 'image/png');
        $img->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertEqualsCanonicalizing(['branding/logo.png', 'branding/logo_dark.png'], Storage::disk('private')->allFiles('branding'));
        $this->device(null)->getJson('/api/branding')->assertJsonPath('data.has_logo_dark', true);

        // Same safety rules as the normal logo.
        $up(['logo_dark' => UploadedFile::fake()->createWithContent('dark.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')])->assertStatus(422);
        $up(['logo_dark' => UploadedFile::fake()->image('big.png', 3000, 3000)])->assertStatus(422);

        $this->device($c)->deleteJson('/api/settings/branding/logo_dark')->assertOk()->assertJsonPath('data.has_logo_dark', false)->assertJsonPath('data.has_logo', true);
        $this->get('/branding/logo_dark')->assertStatus(404);
        $this->get('/branding/logo')->assertOk();
    }

    public function test_a_logo_for_the_login_page_is_independent_of_the_other_logos(): void
    {
        $c = $this->superCookie();
        $up = fn (array $f) => $this->device($c)->post('/api/settings/branding', $f, ['Accept' => 'application/json']);
        $up(['logo' => UploadedFile::fake()->image('light.png', 120, 60), 'logo_dark' => UploadedFile::fake()->image('dark.png', 120, 60)])->assertOk();
        $this->device(null)->getJson('/api/branding')->assertJsonPath('data.has_logo_login', false);
        $this->get('/branding/logo_login')->assertStatus(404);

        $up(['logo_login' => UploadedFile::fake()->image('login.png', 300, 90)])->assertOk()
            ->assertJsonPath('data.has_logo', true)->assertJsonPath('data.has_logo_dark', true)->assertJsonPath('data.has_logo_login', true);
        $this->get('/branding/logo_login')->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertEqualsCanonicalizing(['branding/logo.png', 'branding/logo_dark.png', 'branding/logo_login.png'], Storage::disk('private')->allFiles('branding'));

        $up(['logo_login' => UploadedFile::fake()->createWithContent('login.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')])->assertStatus(422);

        $this->device($c)->deleteJson('/api/settings/branding/logo_login')->assertOk()
            ->assertJsonPath('data.has_logo_login', false)->assertJsonPath('data.has_logo', true)->assertJsonPath('data.has_logo_dark', true);
        $this->get('/branding/logo_login')->assertStatus(404);
    }

    public function test_app_icons_and_the_manifest_are_made_from_the_favicon(): void
    {
        $c = $this->superCookie();

        // With no favicon: no icons, and the manifest still names the app without them.
        $this->get('/branding/icon/192')->assertStatus(404);
        $empty = $this->get('/manifest.webmanifest')->assertOk();
        $empty->assertHeader('Content-Type', 'application/manifest+json');
        $this->assertSame('Thryve', $empty->json('name'));
        $this->assertSame([], $empty->json('icons'));
        $this->assertStringNotContainsString('apple-touch-icon', $this->get('/')->getContent());
        $this->assertStringContainsString('<link rel="manifest" href="/manifest.webmanifest">', $this->get('/')->getContent());

        // A non-square favicon becomes square icons of every size, centred on a transparent square.
        $this->device($c)->post('/api/settings/branding', ['favicon' => UploadedFile::fake()->image('fav.png', 300, 100)], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.favicon_png', true);
        foreach ([32, 180, 192, 512] as $size) {
            $r = $this->get("/branding/icon/{$size}")->assertOk();
            $r->assertHeader('Content-Type', 'image/png');
            $r->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertSame([$size, $size], array_slice(getimagesizefromstring($r->getContent()), 0, 2), "icon {$size}");
        }
        // The iPhone icon sits on white; the others keep their transparent padding (GD alpha 127 = fully transparent).
        $alphaAtCorner = fn (int $size) => (imagecolorat($im = imagecreatefromstring($this->get("/branding/icon/{$size}")->getContent()), 0, 0) >> 24) & 127;
        $this->assertSame(0, $alphaAtCorner(180));
        $this->assertSame(127, $alphaAtCorner(192));
        $this->get('/branding/icon/100')->assertStatus(404); // only the listed sizes
        $this->get('/branding/icon/abc')->assertStatus(404);

        $m = $this->get('/manifest.webmanifest')->assertOk();
        $this->assertSame(['192x192', '512x512'], array_column($m->json('icons'), 'sizes'));
        $this->assertMatchesRegularExpression('#^/branding/icon/192\?v=\d+$#', $m->json('icons.0.src'));
        $this->assertSame('standalone', $m->json('display'));
        $this->device($c)->patchJson('/api/settings', ['company_name' => 'Acme Corp'])->assertOk();
        $this->assertSame('Acme Corp', $this->get('/manifest.webmanifest')->json('name'));

        // The page head points at all of it, with a tab colour for each theme.
        $page = $this->get('/')->getContent();
        $this->assertMatchesRegularExpression('#<link rel="apple-touch-icon" href="/branding/icon/180\?v=\d+">#', $page);
        $this->assertMatchesRegularExpression('#<link rel="icon" type="image/png" sizes="32x32" href="/branding/icon/32\?v=\d+">#', $page);
        $this->assertStringContainsString('<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">', $page);
        $this->assertStringContainsString('<meta name="theme-color" content="#111a2e" media="(prefers-color-scheme: dark)">', $page);

        // Removing the favicon removes the icons again.
        $this->device($c)->deleteJson('/api/settings/branding/favicon')->assertOk();
        $this->get('/branding/icon/192')->assertStatus(404);
        $this->assertSame([], $this->get('/manifest.webmanifest')->json('icons'));
    }

    public function test_an_ico_favicon_is_used_for_the_tab_but_no_app_icons_are_made_from_it(): void
    {
        $c = $this->superCookie();
        // An ICO file (the test tools label files by name, so the type is stated): the tab can use it, but it cannot be resized into app icons.
        $this->device($c)->post('/api/settings/branding', ['favicon' => UploadedFile::fake()->create('favicon.ico', 1, 'image/x-icon')], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.has_favicon', true)->assertJsonPath('data.favicon_png', false);

        $this->get('/branding/favicon')->assertOk();
        $this->get('/branding/icon/192')->assertStatus(404);
        $this->assertSame([], $this->get('/manifest.webmanifest')->json('icons'));
        $page = $this->get('/')->getContent();
        $this->assertStringContainsString('<link rel="icon" href="/branding/favicon?v=', $page);
        $this->assertStringNotContainsString('apple-touch-icon', $page);
    }

    public function test_unsafe_or_oversized_images_are_rejected(): void
    {
        $c = $this->superCookie();
        $post = fn (array $files) => $this->device($c)->post('/api/settings/branding', $files, ['Accept' => 'application/json']);

        $post(['logo' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')])->assertStatus(422);
        $post(['logo' => UploadedFile::fake()->createWithContent('logo.png', '<?php echo "pwned";')])->assertStatus(422);
        $post(['logo' => UploadedFile::fake()->createWithContent('logo.png', '<html><script>alert(1)</script></html>')])->assertStatus(422);
        $post(['logo' => UploadedFile::fake()->image('big.png', 3000, 3000)])->assertStatus(422);
        $post(['logo' => UploadedFile::fake()->image('heavy.png', 50, 50)->size(2048)])->assertStatus(422);
        $post([])->assertStatus(422);

        $this->assertSame([], Storage::disk('private')->allFiles('branding'));
        $this->assertStringNotContainsString('image/', (string) $this->get('/branding/other')->headers->get('Content-Type'));
    }

    public function test_the_page_carries_the_branding_and_default_theme(): void
    {
        $c = $this->superCookie();
        $this->device($c)->patchJson('/api/settings', ['company_name' => 'Acme "Best" Corp', 'default_theme' => 'dark'])->assertOk();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<title>Acme &quot;Best&quot; Corp</title>', $html);
        preg_match("/JSON\.parse\('(.*?)'\)/", $html, $m);
        $app = json_decode(str_replace('\u0022', '"', $m[1]), true);
        $this->assertSame('Acme "Best" Corp', $app['branding']['company_name'], 'the name survives intact inside the page data');
        $this->assertSame('dark', $app['branding']['default_theme']);
        $this->assertStringContainsString("t='dark'", $html, 'the head script applies the default theme before first paint');
    }

    // ---- health --------------------------------------------------------------------------------

    public function test_health_reports_each_dependency_with_a_safe_status(): void
    {
        config(['services.hermes.url' => null]); // the developer's own .env may point at a real Hermes
        $c = $this->superCookie();

        $r = $this->device($c)->getJson('/api/system/health')->assertOk();

        $byKey = collect($r->json('data'))->keyBy('key');
        $this->assertEqualsCanonicalizing(['database', 'hermes', 'agents', 'email', 'scheduler', 'queue', 'storage', 'backups'], $byKey->keys()->all());
        $this->assertSame('ok', $byKey['database']['status']);
        $this->assertSame('unknown', $byKey['hermes']['status'], 'Hermes is not configured in tests');
        $this->assertSame('unknown', $byKey['email']['status']);
        $this->assertSame('fail', $byKey['scheduler']['status'], 'the scheduler has never run');
        $this->assertContains($byKey['storage']['status'], ['ok', 'warn'], 'warn only when the host disk is nearly full');
        foreach ($byKey as $check) {
            $this->assertContains($check['status'], ['ok', 'warn', 'fail', 'unknown']);
            $this->assertNotEmpty($check['checked_at']);
        }
    }

    public function test_health_follows_the_scheduler_heartbeat_and_email_test_result(): void
    {
        $c = $this->superCookie();
        $this->artisan('thryve:heartbeat')->assertSuccessful();
        $this->device($c)->patchJson('/api/settings', ['smtp_enabled' => true, 'smtp_host' => 'smtp.example.com', 'smtp_from_address' => 'noreply@example.com'])->assertOk();

        $get = fn () => collect($this->device($c)->getJson('/api/system/health')->json('data'))->keyBy('key');
        $this->assertSame('ok', $get()['scheduler']['status']);
        $this->assertSame('warn', $get()['email']['status'], 'on but never tested');

        Cache::forever('smtp:last_test', ['ok' => false, 'category' => 'auth_failed']);
        $this->assertSame('fail', $get()['email']['status']);
        $this->assertStringContainsString('username or password', $get()['email']['detail']);

        Cache::forever('smtp:last_test', ['ok' => true]);
        $this->assertSame('ok', $get()['email']['status']);

        Cache::forever('scheduler:last_run', now()->subMinutes(30)->toIso8601String());
        $this->assertSame('fail', $get()['scheduler']['status']);
    }

    public function test_health_never_exposes_credentials_or_raw_errors(): void
    {
        $c = $this->superCookie();
        $this->device($c)->patchJson('/api/settings', ['smtp_enabled' => true, 'smtp_host' => 'smtp.example.com', 'smtp_from_address' => 'noreply@example.com', 'smtp_password' => 'health-secret-pw'])->assertOk();
        config(['services.hermes.url' => 'http://127.0.0.1:1/secret-path', 'services.hermes.key' => 'hermes-secret-key']);

        $body = $this->device($c)->getJson('/api/system/health')->getContent();

        foreach (['health-secret-pw', 'hermes-secret-key', 'secret-path', 'SQLSTATE', 'Stack trace'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
        $this->assertSame('fail', collect(json_decode($body, true)['data'])->firstWhere('key', 'hermes')['status'], 'an unreachable Hermes is reported as failing');
    }
}
