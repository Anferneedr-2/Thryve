<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AuditEntry;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SimulatesDevices;
use Tests\TestCase;

/** A chatbot can have a picture. It is optional: without one the app keeps showing the letter on a coloured circle. */
class AgentAvatarTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
        $this->seed(\Database\Seeders\RoleSeeder::class);
        Storage::fake(config('thryve.private_disk')); // never write pictures into the real storage folder
        config(['services.hermes.url' => null, 'services.hermes.key' => null]);
        $this->agent = Agent::create(['name' => 'General', 'slug' => 'general', 'base_url' => 'https://general.example.com', 'profile' => 'general', 'is_default' => true,
            'outbound_key' => 'key-for-general-1234567890', 'outbound_fingerprint' => substr(md5('general'), 0, 8)]);
    }

    private function upload(string $cookie, array $files, ?int $id = null)
    {
        return $this->device($cookie)->post('/api/agents/'.($id ?? $this->agent->id).'/avatar', $files, ['Accept' => 'application/json']);
    }

    public function test_by_default_no_agent_has_a_picture(): void
    {
        $employee = $this->cookieFor(Role::EMPLOYEE);

        $agents = $this->device($employee)->getJson('/api/chat/agents')->assertOk();
        $this->assertNull($agents->json('data.0.avatar'));
        $this->device($employee)->get('/api/agents/'.$this->agent->id.'/avatar')->assertStatus(404);
    }

    public function test_a_super_admin_adds_a_picture_and_everyone_signed_in_can_see_it(): void
    {
        $super = $this->cookieFor(Role::SUPER_ADMIN);
        $employee = $this->cookieFor(Role::EMPLOYEE);

        $r = $this->upload($super, ['avatar' => UploadedFile::fake()->image('bot.png', 200, 200)])->assertOk();
        $this->assertStringStartsWith('/api/agents/'.$this->agent->id.'/avatar?v=', $r->json('data.avatar'));

        // It shows in the chat's list of chatbots, the admin list and the conversation summaries.
        $this->assertSame($r->json('data.avatar'), $this->device($employee)->getJson('/api/chat/agents')->json('data.0.avatar'));
        $this->assertSame($r->json('data.avatar'), $this->device($super)->getJson('/api/agents')->json('data.0.avatar'));

        $img = $this->device($employee)->get('/api/agents/'.$this->agent->id.'/avatar')->assertOk();
        $img->assertHeader('Content-Type', 'image/png');
        $img->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString("default-src 'none'", $img->headers->get('Content-Security-Policy'));
        $this->assertSame(1, AuditEntry::where('action', 'agent.avatar_updated')->count());

        // Replacing keeps exactly one file, and the address changes only with the picture.
        $this->upload($super, ['avatar' => UploadedFile::fake()->image('new.jpg', 100, 100)])->assertOk();
        $this->assertSame(['agents/'.$this->agent->id.'.jpg'], Storage::disk('private')->allFiles('agents'));
        $this->device($employee)->get('/api/agents/'.$this->agent->id.'/avatar')->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_the_picture_can_be_removed_and_the_default_look_returns(): void
    {
        $super = $this->cookieFor(Role::SUPER_ADMIN);
        $this->upload($super, ['avatar' => UploadedFile::fake()->image('bot.png', 120, 120)])->assertOk();

        $this->device($super)->deleteJson('/api/agents/'.$this->agent->id.'/avatar')->assertOk()->assertJsonPath('data.avatar', null);

        $this->assertSame([], Storage::disk('private')->allFiles('agents'));
        $this->assertNull($this->device($super)->getJson('/api/chat/agents')->json('data.0.avatar'));
        $this->device($super)->get('/api/agents/'.$this->agent->id.'/avatar')->assertStatus(404);
    }

    public function test_only_a_super_admin_can_change_a_picture(): void
    {
        $file = fn () => ['avatar' => UploadedFile::fake()->image('bot.png', 100, 100)];
        $this->upload($this->cookieFor(Role::ADMIN), $file())->assertStatus(403);
        $this->upload($this->cookieFor(Role::EMPLOYEE), $file())->assertStatus(403);
        $this->device($this->cookieFor(Role::ADMIN))->deleteJson('/api/agents/'.$this->agent->id.'/avatar')->assertStatus(403);
        $this->assertSame([], Storage::disk('private')->allFiles('agents'));
        $this->device(null)->get('/api/agents/'.$this->agent->id.'/avatar')->assertStatus(401);
    }

    public function test_unsafe_or_oversized_pictures_are_rejected(): void
    {
        $super = $this->cookieFor(Role::SUPER_ADMIN);

        $this->upload($super, ['avatar' => UploadedFile::fake()->createWithContent('bot.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')])->assertStatus(422);
        $this->upload($super, ['avatar' => UploadedFile::fake()->createWithContent('bot.png', '<?php echo "pwned";')])->assertStatus(422);
        $this->upload($super, ['avatar' => UploadedFile::fake()->image('big.png', 3000, 3000)])->assertStatus(422);
        $this->upload($super, ['avatar' => UploadedFile::fake()->image('heavy.png', 50, 50)->size(2048)])->assertStatus(422);
        $this->upload($super, [])->assertStatus(422);

        $this->assertSame([], Storage::disk('private')->allFiles('agents'));
    }

    public function test_a_removed_or_unknown_agent_has_no_picture_route(): void
    {
        $super = $this->cookieFor(Role::SUPER_ADMIN);
        $this->upload($super, ['avatar' => UploadedFile::fake()->image('bot.png', 100, 100)])->assertOk();
        $this->upload($super, ['avatar' => UploadedFile::fake()->image('bot.png', 100, 100)], 9999)->assertStatus(404);

        $this->agent->update(['removed_at' => now()]);
        $this->device($super)->get('/api/agents/'.$this->agent->id.'/avatar')->assertStatus(404);
    }
}
