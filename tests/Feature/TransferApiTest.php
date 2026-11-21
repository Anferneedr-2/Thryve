<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AuditEntry;
use App\Models\Role;
use App\Support\AgentKeyTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SimulatesDevices;
use Tests\TestCase;

/** Super Admin brings a chatbot's chats and key across from the web page, for hosts with no terminal. */
class TransferApiTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices;

    private const KEY = 'sk-test-ACCESS-KEY-0123456789abcdef';

    private const PASS = 'correct horse battery staple';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
        $this->seed(\Database\Seeders\RoleSeeder::class);
        Storage::fake(config('thryve.private_disk'));
    }

    private function bundle(string $user = 'robi'): array
    {
        return ['format' => 'thryve-chat-transfer', 'version' => 1, 'agent' => ['slug' => 'general-ai-assistant', 'name' => 'General AI Assistant', 'description' => 'Help', 'base_url' => 'https://h.example.com/api', 'profile' => 'general-ai-assistant'],
            'conversations' => [['user' => $user, 'title' => 'Hello', 'archived_at' => null, 'last_message_at' => '2026-11-01T10:00:00+00:00', 'created_at' => '2026-11-01T09:00:00+00:00', 'updated_at' => '2026-11-01T10:00:00+00:00',
                'messages' => [['role' => 'user', 'content' => 'Hi', 'status' => 'complete', 'rating' => null, 'superseded_at' => null, 'created_at' => '2026-11-01T09:00:00+00:00', 'updated_at' => '2026-11-01T09:00:00+00:00', 'attachments' => []]]]]];
    }

    private function jsonFile(array $data, string $name = 'x.json'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, json_encode($data));
    }

    private function send(string $cookie, string $path, array $data)
    {
        return $this->device($cookie)->post('/api/settings/transfer/'.$path, $data, ['Accept' => 'application/json']);
    }

    public function test_only_a_super_admin_can_use_it(): void
    {
        foreach ([Role::EMPLOYEE, Role::ADMIN] as $role) {
            $c = $this->cookieFor($role);
            $this->send($c, 'chats', ['file' => $this->jsonFile($this->bundle())])->assertStatus(403);
            $this->send($c, 'key', ['file' => $this->jsonFile([]), 'passphrase' => 'x'])->assertStatus(403);
            $this->device($c)->getJson('/api/settings/transfer')->assertStatus(403);
        }
        $this->device(null)->postJson('/api/settings/transfer/chats')->assertStatus(401);
    }

    public function test_a_preview_changes_nothing_then_the_import_brings_the_chats_and_repeating_is_safe(): void
    {
        $this->makeUser(Role::EMPLOYEE, ['username' => 'robi']);
        $c = $this->cookieFor(Role::SUPER_ADMIN);
        $this->device($c)->getJson('/api/settings/transfer')->assertOk()->assertJsonStructure(['data' => ['max_upload_bytes']]);

        $this->send($c, 'chats', ['file' => $this->jsonFile($this->bundle()), 'dry_run' => '1'])->assertOk()->assertJsonPath('data.imported', 1)->assertJsonPath('data.dry_run', true);
        $this->assertSame(0, Agent::where('slug', 'general-ai-assistant')->count(), 'a preview creates nothing');

        $this->send($c, 'chats', ['file' => $this->jsonFile($this->bundle()), 'dry_run' => '0'])->assertOk()->assertJsonPath('data.imported', 1)->assertJsonPath('data.messages', 1);
        $this->assertSame(1, \DB::table('conversations')->count());
        $this->assertNull(Agent::where('slug', 'general-ai-assistant')->first()->outbound_key, 'no key travels with the chats');

        $this->send($c, 'chats', ['file' => $this->jsonFile($this->bundle())])->assertOk()->assertJsonPath('data.imported', 0)->assertJsonPath('data.duplicates', 1);
        $this->assertSame(1, \DB::table('conversations')->count());
        $this->assertSame(1, AuditEntry::where('action', 'chats.imported')->count(), 'only the real import is recorded');
    }

    public function test_people_who_do_not_exist_are_reported_not_created_and_bad_files_are_refused(): void
    {
        $c = $this->cookieFor(Role::SUPER_ADMIN);
        $this->send($c, 'chats', ['file' => $this->jsonFile($this->bundle('ghost'))])->assertOk()->assertJsonPath('data.imported', 0)->assertJsonPath('data.missing_users.ghost', 1);
        $this->assertSame(0, \App\Models\User::where('username', 'ghost')->count());

        $this->send($c, 'chats', ['file' => UploadedFile::fake()->createWithContent('x.json', 'not json')])->assertStatus(422)->assertJsonPath('error.code', 'invalid_file');
        $this->send($c, 'chats', ['file' => $this->jsonFile(['format' => 'other'])])->assertStatus(422)->assertJsonPath('error.code', 'invalid_file');
        $this->send($c, 'chats', [])->assertStatus(422);
    }

    public function test_the_key_is_saved_with_the_right_passphrase_and_never_returned(): void
    {
        Agent::create(['name' => 'General AI Assistant', 'slug' => 'general-ai-assistant', 'base_url' => 'https://h.example.com/api', 'profile' => 'general-ai-assistant']);
        $c = $this->cookieFor(Role::SUPER_ADMIN);
        $file = fn () => $this->jsonFile(AgentKeyTransfer::seal('general-ai-assistant', self::KEY, self::PASS), 'k.json');

        $bad = $this->send($c, 'key', ['file' => $file(), 'passphrase' => 'not the passphrase']);
        $bad->assertStatus(422)->assertJsonPath('error.message', 'The passphrase is wrong, or the file was changed.');
        $this->assertNull(Agent::first()->outbound_key);

        $ok = $this->send($c, 'key', ['file' => $file(), 'passphrase' => self::PASS])->assertOk()->assertJsonPath('data.result', 'set');
        $this->assertStringNotContainsString(self::KEY, $ok->getContent());
        $this->assertSame(self::KEY, Agent::first()->outbound_key);
        $this->assertSame('never_tested', Agent::first()->status);
        $entry = AuditEntry::where('action', 'agent.key_imported')->firstOrFail();
        $this->assertStringNotContainsString(self::KEY, json_encode($entry->toArray()));
        $this->assertStringNotContainsString(self::PASS, json_encode($entry->toArray()));

        $this->send($c, 'key', ['file' => $file(), 'passphrase' => self::PASS])->assertOk()->assertJsonPath('data.result', 'same');
    }

    public function test_passphrase_guessing_is_limited_and_other_throttled_calls_do_not_use_up_the_allowance(): void
    {
        Agent::create(['name' => 'General AI Assistant', 'slug' => 'general-ai-assistant', 'base_url' => 'https://h.example.com/api', 'profile' => 'general-ai-assistant']);
        $c = $this->cookieFor(Role::SUPER_ADMIN);
        $file = fn () => $this->jsonFile(AgentKeyTransfer::seal('general-ai-assistant', self::KEY, self::PASS), 'k.json');

        for ($i = 0; $i < 3; $i++) {
            $this->device($c)->postJson('/api/attendance/heartbeat', [])->status(); // shares the old per-user counter; must not matter now
        }
        for ($i = 0; $i < 5; $i++) {
            $this->send($c, 'key', ['file' => $file(), 'passphrase' => 'guess '.$i])->assertStatus(422);
        }
        $this->send($c, 'key', ['file' => $file(), 'passphrase' => self::PASS])->assertStatus(429);
        $this->assertNull(Agent::first()->outbound_key, 'a blocked attempt changes nothing');
    }

    public function test_a_different_key_is_only_replaced_when_asked_and_the_chatbot_must_exist(): void
    {
        $c = $this->cookieFor(Role::SUPER_ADMIN);
        $file = fn () => $this->jsonFile(AgentKeyTransfer::seal('general-ai-assistant', self::KEY, self::PASS), 'k.json');
        $this->send($c, 'key', ['file' => $file(), 'passphrase' => self::PASS])->assertStatus(422)->assertJsonPath('error.code', 'invalid_file');

        $a = Agent::create(['name' => 'General AI Assistant', 'slug' => 'general-ai-assistant', 'base_url' => 'https://h.example.com/api', 'profile' => 'general-ai-assistant', 'outbound_key' => 'MY-OWN-KEY-9876543210', 'outbound_fingerprint' => '43210xyz']);
        $this->send($c, 'key', ['file' => $file(), 'passphrase' => self::PASS])->assertStatus(422);
        $this->assertSame('MY-OWN-KEY-9876543210', $a->refresh()->outbound_key);

        $this->send($c, 'key', ['file' => $file(), 'passphrase' => self::PASS, 'replace' => '1'])->assertOk();
        $this->assertSame(self::KEY, $a->refresh()->outbound_key);
    }
}
