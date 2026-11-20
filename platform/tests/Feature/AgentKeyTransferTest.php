<?php

namespace Tests\Feature;

use App\Ai\AgentService;
use App\Models\Agent;
use App\Models\AuditEntry;
use App\Support\AgentKeyTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A chatbot's access key travels only inside a file that is useless without a passphrase typed by the owner. */
class AgentKeyTransferTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk-test-ACCESS-KEY-0123456789abcdef';

    private const PASS = 'correct horse battery staple';

    private string $tmp;

    private string $originalStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
        // Default file locations are under storage/app/transfer: use a temporary storage folder so a test never touches the real one.
        $this->originalStorage = $this->app->storagePath();
        $this->tmp = sys_get_temp_dir().'/thryve-keytransfer-'.bin2hex(random_bytes(5));
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

    private function agent(?string $key = self::KEY, array $o = []): Agent
    {
        return Agent::create($o + ['name' => 'General AI Assistant', 'slug' => 'general-ai-assistant', 'base_url' => 'https://hermes.example.com/api', 'profile' => 'general-ai-assistant',
            'outbound_key' => $key, 'outbound_fingerprint' => $key ? AgentService::fingerprint($key) : null]);
    }

    private function export(string $pass = self::PASS): string
    {
        $file = $this->tmp.'/app/transfer/general-ai-assistant.key.json';
        $this->artisan('thryve:export-agent-key')->expectsQuestion('Choose a passphrase', $pass)->expectsQuestion('Type the passphrase again', $pass)->assertSuccessful();

        return $file;
    }

    public function test_the_key_makes_the_trip_and_the_file_never_holds_it_in_readable_form(): void
    {
        $source = $this->agent();
        $file = $this->export();

        $raw = file_get_contents($file);
        foreach ([self::KEY, base64_encode(self::KEY), 'ACCESS-KEY', self::PASS] as $secret) {
            $this->assertStringNotContainsString($secret, $raw, 'the key file must not contain: '.$secret);
        }
        $data = json_decode($raw, true);
        $this->assertSame(AgentService::fingerprint(self::KEY), $data['fingerprint'], 'only the public fingerprint is in the clear');
        $this->assertSame('aes-256-gcm', $data['cipher']);

        // Another installation: the chatbot exists (made by the chat import) but has no key.
        $source->delete();
        $other = $this->agent(null);
        $this->artisan('thryve:import-agent-key', ['file' => $file])->expectsQuestion('Passphrase', self::PASS)
            ->expectsOutputToContain('ends in '.AgentService::fingerprint(self::KEY))->assertSuccessful();

        $other->refresh();
        $this->assertSame(self::KEY, $other->outbound_key, 'the key is stored (encrypted at rest by the model) and decrypts to the same value');
        $this->assertSame(AgentService::fingerprint(self::KEY), $other->outbound_fingerprint);
        $this->assertSame('never_tested', $other->status);
        $this->assertNull($other->pending_key);

        // The audit trail records the fingerprint, never the key.
        $entry = AuditEntry::where('action', 'agent.key_imported')->firstOrFail();
        $this->assertSame(AgentService::fingerprint(self::KEY), $entry->metadata['fingerprint']);
        $this->assertStringNotContainsString(self::KEY, json_encode($entry->toArray()));
    }

    public function test_a_wrong_passphrase_or_a_changed_file_is_refused_and_changes_nothing(): void
    {
        $this->agent();
        $file = $this->export();
        $original = file_get_contents($file);
        Agent::query()->update(['outbound_key' => null, 'outbound_fingerprint' => null]);

        $this->artisan('thryve:import-agent-key', ['file' => $file])->expectsQuestion('Passphrase', 'not the right passphrase')
            ->expectsOutputToContain('passphrase is wrong, or the file was changed')->assertFailed();
        $this->assertNull(Agent::first()->outbound_key);

        // A changed ciphertext fails too (the authentication tag no longer matches).
        $data = json_decode(file_get_contents($file), true);
        $data['data'] = base64_encode(strrev((string) base64_decode($data['data'])));
        file_put_contents($file, json_encode($data));
        $this->artisan('thryve:import-agent-key', ['file' => $file])->expectsQuestion('Passphrase', self::PASS)->expectsOutputToContain('passphrase is wrong, or the file was changed')->assertFailed();

        // So does a file whose chatbot name or fingerprint was swapped.
        $data = json_decode($original, true);
        $data['fingerprint'] = 'deadbeef';
        file_put_contents($file, json_encode($data));
        $this->artisan('thryve:import-agent-key', ['file' => $file])->expectsQuestion('Passphrase', self::PASS)->assertFailed();
        $this->assertNull(Agent::first()->outbound_key);
    }

    public function test_the_passphrase_must_be_long_enough_and_typed_the_same_twice(): void
    {
        $this->agent();
        $this->artisan('thryve:export-agent-key')->expectsQuestion('Choose a passphrase', 'short')->expectsOutputToContain('too short')->assertFailed();
        $this->artisan('thryve:export-agent-key')->expectsQuestion('Choose a passphrase', self::PASS)->expectsQuestion('Type the passphrase again', self::PASS.'x')
            ->expectsOutputToContain('not the same')->assertFailed();
        $this->assertFileDoesNotExist($this->tmp.'/app/transfer/general-ai-assistant.key.json');
    }

    public function test_an_existing_different_key_is_only_replaced_when_asked(): void
    {
        $this->agent();
        $file = $this->export();
        Agent::query()->delete();
        $mine = $this->agent('MY-OWN-DIFFERENT-KEY-9876543210');

        $this->artisan('thryve:import-agent-key', ['file' => $file])->expectsOutputToContain('already has a different key')->assertFailed();
        $this->assertSame('MY-OWN-DIFFERENT-KEY-9876543210', $mine->refresh()->outbound_key);

        $this->artisan('thryve:import-agent-key', ['file' => $file, '--replace' => true])->expectsQuestion('Passphrase', self::PASS)->assertSuccessful();
        $this->assertSame(self::KEY, $mine->refresh()->outbound_key);

        // Importing the key it already has changes nothing and says so.
        $this->artisan('thryve:import-agent-key', ['file' => $file])->expectsQuestion('Passphrase', self::PASS)->expectsOutputToContain('already has this key')->assertSuccessful();
    }

    public function test_the_chatbot_must_exist_and_have_a_key_to_export_or_to_receive_one(): void
    {
        $this->artisan('thryve:export-agent-key')->expectsOutputToContain('no chatbot with that name')->assertFailed();
        $this->agent(null);
        $this->artisan('thryve:export-agent-key')->expectsOutputToContain('has no access key here')->assertFailed();

        // A key file for a chatbot that is not here yet is refused before any passphrase is asked.
        $sealed = AgentKeyTransfer::seal('another-bot', self::KEY, self::PASS);
        $file = $this->tmp.'/app/transfer/another.key.json';
        file_put_contents($file, json_encode($sealed));
        $this->artisan('thryve:import-agent-key', ['file' => $file])->expectsOutputToContain('not here yet')->assertFailed();
        $this->artisan('thryve:import-agent-key', ['file' => $this->tmp.'/nope.json'])->expectsOutputToContain('No key file')->assertFailed();
    }

    public function test_the_default_file_lives_in_the_folder_git_ignores_and_the_chat_file_never_carries_the_key(): void
    {
        $this->agent();
        $this->export();
        $this->assertFileExists(storage_path('app/transfer/general-ai-assistant.key.json'));

        // The git ignore rule for that folder lives in storage/app/.gitignore (everything except private/ and public/).
        $rule = file_get_contents($this->originalStorage.'/app/.gitignore');
        $this->assertStringContainsString('*', $rule);
        $this->assertStringNotContainsString('transfer', $rule, 'the folder is ignored by the catch-all rule and never whitelisted');

        // The chat transfer file still holds no key (and not its fingerprint either).
        $chats = \App\Support\ChatTransfer::export('general-ai-assistant', ['nobody']);
        $json = json_encode($chats);
        $this->assertStringNotContainsString(self::KEY, $json);
        $this->assertStringNotContainsString(AgentService::fingerprint(self::KEY), $json);
    }
}
