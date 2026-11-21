<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Role;
use App\Models\User;
use App\Support\ChatTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Concerns\SimulatesDevices;
use Tests\TestCase;

/** Moving one chatbot's conversations to another installation through a transfer file. */
class ChatTransferTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices;

    private string $tmp;

    private string $originalStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
        Storage::fake(config('thryve.private_disk'));
        // The migration looks in storage/app/transfer: point it at a temporary folder so a test never touches the real one.
        $this->originalStorage = $this->app->storagePath();
        $this->tmp = sys_get_temp_dir().'/thryve-transfer-'.bin2hex(random_bytes(5));
        mkdir($this->tmp.'/app/transfer', 0775, true);
        $this->app->useStoragePath($this->tmp);
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStorage);
        foreach (glob($this->tmp.'/app/transfer/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp.'/app/transfer');
        @rmdir($this->tmp.'/app');
        @rmdir($this->tmp);
        parent::tearDown();
    }

    private function agent(array $o = []): Agent
    {
        return Agent::create($o + ['name' => 'General AI Assistant', 'slug' => 'general-ai-assistant', 'base_url' => 'https://hermes.example.com/api', 'profile' => 'general-ai-assistant',
            'description' => 'Everyday help', 'is_default' => true, 'outbound_key' => 'SECRET-ACCESS-KEY-1234567890', 'outbound_fingerprint' => 'abcd1234', 'inbound_key_hash' => 'SECRET-HASH-XYZ']);
    }

    private function user(string $username, string $role = Role::EMPLOYEE): User
    {
        return $this->makeUser($role, ['username' => $username, 'name' => ucfirst($username)]);
    }

    private function conversation(User $u, Agent $a, string $title, array $messages, array $o = []): int
    {
        $id = DB::table('conversations')->insertGetId($o + ['user_id' => $u->id, 'agent_id' => $a->id, 'title' => $title, 'last_message_at' => '2026-11-19 10:05:00', 'created_at' => '2026-11-19 10:00:00', 'updated_at' => '2026-11-19 10:05:00']);
        foreach ($messages as $i => $m) {
            DB::table('messages')->insert($m + ['conversation_id' => $id, 'status' => 'complete', 'rating' => null, 'superseded_at' => null,
                'created_at' => '2026-11-19 10:0'.$i.':00', 'updated_at' => '2026-11-19 10:0'.$i.':30']);
        }

        return $id;
    }

    /** Builds the "old device": two people with chats, one more person who is not exported, a deleted chat. */
    private function oldDevice(): array
    {
        $agent = $this->agent();
        $sa = $this->user('superadmin', Role::SUPER_ADMIN);
        $robi = $this->user('Robi');
        $other = $this->user('someone.else');
        $this->conversation($sa, $agent, 'Plan my week', [
            ['role' => 'user', 'content' => "Help me plan next week, ñ 日本語 \"quotes\" and a\nnew line"],
            ['role' => 'assistant', 'content' => 'Here is a plan...', 'rating' => 'up'],
            ['role' => 'assistant', 'content' => 'An older answer that was regenerated', 'superseded_at' => '2026-11-19 10:03:00'],
        ]);
        $withFile = $this->conversation($sa, $agent, 'Explain this file', [['role' => 'user', 'content' => 'What does this say?'], ['role' => 'assistant', 'content' => 'It says hello.']]);
        $firstMessage = DB::table('messages')->where('conversation_id', $withFile)->orderBy('id')->value('id');
        $bytes = "hello from the old device\n\x00\x01binary-ish";
        $key = 'attachments/'.bin2hex(random_bytes(8)).'.bin';
        Storage::disk('private')->put($key, $bytes);
        DB::table('attachments')->insert(['user_id' => $sa->id, 'conversation_id' => $withFile, 'message_id' => $firstMessage, 'original_name' => 'notes.txt', 'kind' => 'text', 'size' => strlen($bytes),
            'storage_key' => $key, 'sha256' => hash('sha256', $bytes), 'extracted_text' => 'hello from the old device', 'truncated' => 0, 'generated' => 0, 'created_at' => '2026-11-19 10:00:30', 'updated_at' => '2026-11-19 10:00:30']);
        $this->conversation($robi, $agent, 'Archived chat', [['role' => 'user', 'content' => 'Old question'], ['role' => 'assistant', 'content' => 'Old answer']], ['archived_at' => '2026-11-19 12:00:00']);
        $this->conversation($other, $agent, 'Not exported', [['role' => 'user', 'content' => 'Private to someone else']]);
        $this->conversation($sa, $agent, 'Deleted chat', [['role' => 'user', 'content' => 'I deleted this']], ['deleted_at' => '2026-11-19 11:00:00']);

        return [$agent, $bytes];
    }

    /** Makes the database look like a different installation: nothing in it, people created in another order. */
    private function newDevice(array $usernames = ['Robi', 'superadmin']): void
    {
        DB::table('attachments')->delete();
        DB::table('messages')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_user')->delete();
        DB::table('agents')->delete();
        DB::table('policy_acknowledgments')->delete();
        DB::table('users')->delete();
        foreach (Storage::disk('private')->allFiles('attachments') as $f) {
            Storage::disk('private')->delete($f);
        }
        foreach ($usernames as $u) {
            $this->user($u, $u === 'superadmin' ? Role::SUPER_ADMIN : Role::EMPLOYEE);
        }
    }

    private function exportToFile(array $users = ['superadmin', 'Robi']): string
    {
        $file = $this->tmp.'/app/transfer/general-chats.json';
        $this->artisan('thryve:export-chats', ['--users' => implode(',', $users), '--out' => $file])->assertSuccessful();

        return $file;
    }

    public function test_the_conversations_make_the_whole_trip_to_another_installation(): void
    {
        [, $bytes] = $this->oldDevice();
        $file = $this->exportToFile();

        // Only the chosen people's live conversations are in the file, and nothing secret is.
        $bundle = json_decode(file_get_contents($file), true);
        $this->assertSame(['conversations' => 3, 'messages' => 7, 'files' => 1], $bundle['counts']);
        $this->assertEqualsCanonicalizing(['Plan my week', 'Explain this file', 'Archived chat'], array_column($bundle['conversations'], 'title'));
        $raw = file_get_contents($file);
        foreach (['SECRET-ACCESS-KEY', 'SECRET-HASH', 'outbound_key', 'inbound_key', 'password', 'someone.else', 'Private to someone else', 'I deleted this'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw, "the file must not contain: {$secret}");
        }

        $this->newDevice();
        $this->artisan('thryve:import-chats', ['file' => $file])->assertSuccessful();

        // The chatbot was made without any keys, so it cannot answer until keys are created on this side.
        $agent = Agent::where('slug', 'general-ai-assistant')->firstOrFail();
        $this->assertNull($agent->outbound_key);
        $this->assertFalse((bool) $agent->is_default);
        $this->assertSame('Everyday help', $agent->description);

        // People were matched by username, whatever their ids are here.
        $sa = User::where('username', 'superadmin')->first();
        $plan = DB::table('conversations')->where('title', 'Plan my week')->first();
        $this->assertSame($sa->id, $plan->user_id);
        $this->assertSame($agent->id, $plan->agent_id);
        $this->assertSame('2026-11-19 10:00:00', $plan->created_at);
        $this->assertSame('2026-11-19 10:05:00', $plan->last_message_at);
        $this->assertNull(DB::table('conversations')->where('title', 'Archived chat')->value('deleted_at'));
        $this->assertNotNull(DB::table('conversations')->where('title', 'Archived chat')->value('archived_at'));
        $this->assertSame(User::where('username', 'Robi')->value('id'), DB::table('conversations')->where('title', 'Archived chat')->value('user_id'));

        // Messages come across in order with their text, ratings and replaced-answer history intact.
        $m = DB::table('messages')->where('conversation_id', $plan->id)->orderBy('id')->get();
        $this->assertSame(["Help me plan next week, ñ 日本語 \"quotes\" and a\nnew line", 'Here is a plan...', 'An older answer that was regenerated'], $m->pluck('content')->all());
        $this->assertSame(['user', 'assistant', 'assistant'], $m->pluck('role')->all());
        $this->assertSame('up', $m[1]->rating);
        $this->assertNull($m[0]->superseded_at);
        $this->assertSame('2026-11-19 10:03:00', $m[2]->superseded_at);
        $this->assertSame('2026-11-19 10:01:00', $m[1]->created_at);

        // The attached file arrives byte for byte, under a new private name, owned by the right person.
        $a = DB::table('attachments')->first();
        $this->assertSame('notes.txt', $a->original_name);
        $this->assertSame($sa->id, $a->user_id);
        $this->assertSame($bytes, Storage::disk('private')->get($a->storage_key));
        $this->assertSame(hash('sha256', $bytes), $a->sha256);
        $this->assertSame('hello from the old device', $a->extracted_text);
        $this->assertSame(DB::table('conversations')->where('title', 'Explain this file')->value('id'), $a->conversation_id);
        $this->assertNotNull($a->message_id);

        $this->assertSame(3, DB::table('conversations')->count());
        $this->assertSame(7, DB::table('messages')->count());
    }

    public function test_importing_twice_never_duplicates_anything(): void
    {
        $this->oldDevice();
        $file = $this->exportToFile();
        $this->newDevice();

        $this->artisan('thryve:import-chats', ['file' => $file])->assertSuccessful();
        $counts = [DB::table('conversations')->count(), DB::table('messages')->count(), DB::table('attachments')->count(), count(Storage::disk('private')->allFiles('attachments'))];

        $report = ChatTransfer::import(json_decode(file_get_contents($file), true));
        $this->assertSame(0, $report['imported']);
        $this->assertSame(3, $report['duplicates']);
        $this->assertSame($counts, [DB::table('conversations')->count(), DB::table('messages')->count(), DB::table('attachments')->count(), count(Storage::disk('private')->allFiles('attachments'))]);
    }

    public function test_people_who_do_not_exist_yet_are_skipped_and_picked_up_later_without_creating_anyone(): void
    {
        $this->oldDevice();
        $file = $this->exportToFile();
        $this->newDevice(['superadmin']); // Robi has not been created here yet

        $report = ChatTransfer::import(json_decode(file_get_contents($file), true));
        $this->assertSame(2, $report['imported']);
        $this->assertSame(['Robi' => 1], $report['missing_users']);
        $this->assertNull(User::where('username', 'Robi')->first(), 'nobody is created by an import');
        $this->artisan('thryve:import-chats', ['file' => $file])->expectsOutputToContain('Robi (1)')->assertSuccessful();

        $this->user('Robi');
        $later = ChatTransfer::import(json_decode(file_get_contents($file), true));
        $this->assertSame(1, $later['imported']);
        $this->assertSame(2, $later['duplicates']);
        $this->assertSame([], $later['missing_users']);
        $this->assertSame(3, DB::table('conversations')->count());
    }

    public function test_a_fresh_install_where_nobody_exists_yet_is_left_exactly_as_it_was(): void
    {
        $this->oldDevice();
        $file = $this->exportToFile();
        $this->newDevice([]); // no people at all, as when the migrations run during a new install

        $report = ChatTransfer::import(json_decode(file_get_contents($file), true));

        $this->assertSame(0, $report['imported']);
        $this->assertSame(['superadmin' => 2, 'Robi' => 1], $report['missing_users']);
        $this->assertSame(0, Agent::count(), 'no chatbot is made when there is nothing to put in it');
        $this->assertSame(0, DB::table('conversations')->count());
        $this->assertStringContainsString('nothing to import yet', $report['agent']);

        // And a dry run that would import something says the chatbot would be made.
        $this->user('superadmin', Role::SUPER_ADMIN);
        $dry = ChatTransfer::import(json_decode(file_get_contents($file), true), true);
        $this->assertSame(2, $dry['imported']);
        $this->assertStringContainsString('would be created', $dry['agent']);
        $this->assertSame(0, Agent::count());
    }

    public function test_a_chatbot_that_was_removed_here_is_brought_back_instead_of_made_twice(): void
    {
        $this->oldDevice();
        $file = $this->exportToFile();
        $this->newDevice();
        // The other device once had this chatbot and removed it: the row stays, hidden, and still owns the short name.
        $old = $this->agent(['name' => 'My old local name', 'base_url' => 'http://127.0.0.1:8642', 'outbound_key' => null, 'outbound_fingerprint' => null,
            'is_enabled' => false, 'is_default' => false, 'removed_at' => now()->subDay()]);
        $bundle = json_decode(file_get_contents($file), true);

        // A dry run says what would happen and changes nothing.
        $dry = ChatTransfer::import($bundle, true);
        $this->assertStringContainsString('would be brought back', $dry['agent']);
        $this->assertNotNull($old->refresh()->removed_at);

        $report = ChatTransfer::import($bundle);

        $this->assertSame(3, $report['imported']);
        $this->assertStringContainsString('brought back', $report['agent']);
        $this->assertSame(1, Agent::where('slug', 'general-ai-assistant')->count(), 'no second chatbot, no database error');
        $old->refresh();
        $this->assertNull($old->removed_at);
        $this->assertTrue((bool) $old->is_enabled);
        $this->assertSame('My old local name', $old->name, 'its own settings are kept');
        $this->assertSame('http://127.0.0.1:8642', $old->base_url);
        $this->assertNull($old->outbound_key, 'still no key until one is created or imported');
        $this->assertSame(3, DB::table('conversations')->where('agent_id', $old->id)->count());

        // And running it again adds nothing.
        $this->assertSame(0, ChatTransfer::import($bundle)['imported']);
    }

    public function test_an_existing_chatbot_with_the_same_name_is_reused_and_left_untouched(): void
    {
        $this->oldDevice();
        $file = $this->exportToFile();
        $this->newDevice();
        $mine = $this->agent(['name' => 'Renamed here', 'description' => 'Local description', 'outbound_key' => 'MY-OWN-KEY-9876543210', 'is_default' => false]);

        $report = ChatTransfer::import(json_decode(file_get_contents($file), true));

        $this->assertSame('found', $report['agent']);
        $this->assertSame(1, Agent::where('slug', 'general-ai-assistant')->count());
        $mine->refresh();
        $this->assertSame('Renamed here', $mine->name);
        $this->assertSame('MY-OWN-KEY-9876543210', $mine->outbound_key);
        $this->assertSame(3, DB::table('conversations')->where('agent_id', $mine->id)->count());
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->oldDevice();
        $file = $this->exportToFile();
        $this->newDevice();

        $this->artisan('thryve:import-chats', ['file' => $file, '--dry-run' => true])->expectsOutputToContain('Would import 3 conversations')->assertSuccessful();

        $this->assertSame(0, DB::table('conversations')->count());
        $this->assertSame(0, DB::table('messages')->count());
        $this->assertSame(0, Agent::count());
        $this->assertSame([], Storage::disk('private')->allFiles('attachments'));
    }

    public function test_a_damaged_or_foreign_file_is_refused_and_a_damaged_attachment_imports_nothing_of_its_chat(): void
    {
        $this->oldDevice();
        $file = $this->exportToFile();
        $this->newDevice();

        $this->expectException(InvalidArgumentException::class);
        try {
            ChatTransfer::import(['format' => 'something-else', 'version' => 1, 'conversations' => [], 'agent' => ['slug' => 'x']]);
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('not a chat transfer file', $e->getMessage());
            // an attachment whose bytes do not match its checksum: its whole conversation is rolled back
            $bundle = json_decode(file_get_contents($file), true);
            foreach ($bundle['conversations'] as &$c) {
                foreach ($c['messages'] as &$m) {
                    foreach ($m['attachments'] as &$a) {
                        $a['file_base64'] = base64_encode('tampered');
                    }
                }
            }
            try {
                ChatTransfer::import($bundle);
                $this->fail('A damaged attachment must stop the import.');
            } catch (InvalidArgumentException $e2) {
                $this->assertStringContainsString('damaged', $e2->getMessage());
                $this->assertSame(0, DB::table('conversations')->where('title', 'Explain this file')->count(), 'the damaged chat was rolled back');
                throw $e2;
            }
        }
    }

    public function test_the_export_command_needs_people_and_a_real_chatbot(): void
    {
        $this->oldDevice();
        $this->artisan('thryve:export-chats')->expectsOutputToContain('Say whose chats to export')->assertFailed();
        $this->artisan('thryve:export-chats', ['--users' => 'superadmin', '--agent' => 'no-such-bot'])->expectsOutputToContain('no chatbot')->assertFailed();
        $this->artisan('thryve:import-chats', ['file' => $this->tmp.'/missing.json'])->expectsOutputToContain('No transfer file')->assertFailed();
    }

    public function test_the_migration_does_nothing_without_a_file_and_imports_when_one_is_there(): void
    {
        $migration = require base_path('database/migrations/2026_11_20_120000_import_transferred_general_chats.php');

        $this->oldDevice();
        $migration->up(); // no file in the (temporary) transfer folder: nothing happens
        $this->assertSame(5, DB::table('conversations')->count(), 'the old device is untouched');

        $file = $this->exportToFile();
        $this->newDevice();
        $this->assertFileExists($file);
        $migration->up();
        $this->assertSame(3, DB::table('conversations')->count());

        $migration->up(); // running it again (another migrate on a copy of the data) adds nothing
        $this->assertSame(3, DB::table('conversations')->count());
        $this->assertSame(7, DB::table('messages')->count());
    }
}
