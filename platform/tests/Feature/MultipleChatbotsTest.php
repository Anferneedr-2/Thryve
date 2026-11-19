<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AuditEntry;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Role;
use App\Models\UsageRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SimulatesDevices;
use Tests\TestCase;

/** Each agent is a chatbot; the default one is open to everyone, the others are given to employees one by one. */
class MultipleChatbotsTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices;

    private Agent $general;

    private Agent $coach;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
        $this->seed(\Database\Seeders\RoleSeeder::class);
        config(['services.hermes.url' => null, 'services.hermes.key' => null]);
        $this->general = $this->agent('General', 'general', 'https://general.example.com', true);
        $this->coach = $this->agent('Coach', 'coach', 'https://coach.example.com', false, 'Helps you improve how you work');
    }

    private function agent(string $name, string $profile, string $url, bool $default, ?string $description = null): Agent
    {
        return Agent::create(['name' => $name, 'slug' => $profile, 'base_url' => $url, 'profile' => $profile, 'is_default' => $default, 'description' => $description,
            'outbound_key' => "key-for-{$profile}-1234567890", 'outbound_fingerprint' => substr(md5($profile), 0, 8)]);
    }

    private function answer(string $text = 'Hello'): array
    {
        return ['model' => 'x', 'choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 5]];
    }

    private function employee(): array
    {
        $u = $this->makeUser(Role::EMPLOYEE);
        [, $cookie] = $this->signIn($u);

        return [$u, $cookie];
    }

    private function give(User $u, Agent $a): void
    {
        DB::table('agent_user')->insert(['agent_id' => $a->id, 'user_id' => $u->id, 'created_at' => now()]);
    }

    private function names($cookie): array
    {
        return array_column($this->device($cookie)->getJson('/api/chat/agents')->assertOk()->json('data'), 'name');
    }

    // ---- who sees what ---------------------------------------------------------------------------

    public function test_everyone_gets_the_default_chatbot_and_only_assigned_people_get_the_others(): void
    {
        [$u, $cookie] = $this->employee();
        $this->assertSame(['General'], $this->names($cookie), 'a new employee starts with the default only');

        $this->give($u, $this->coach);
        $this->assertSame(['General', 'Coach'], $this->names($cookie));
    }

    public function test_admins_and_super_admins_get_every_enabled_chatbot(): void
    {
        foreach ([Role::ADMIN, Role::SUPER_ADMIN] as $role) {
            $this->assertEqualsCanonicalizing(['General', 'Coach'], $this->names($this->cookieFor($role)));
        }
    }

    public function test_disabled_revoked_and_removed_agents_are_not_offered_to_anyone(): void
    {
        [$u, $cookie] = $this->employee();
        $this->give($u, $this->coach);
        $admin = $this->cookieFor(Role::ADMIN);

        $this->coach->update(['is_enabled' => false]);
        $this->assertSame(['General'], $this->names($cookie));
        $this->assertSame(['General'], $this->names($admin));
        $this->coach->update(['is_enabled' => true, 'outbound_key' => null]);
        $this->assertSame(['General'], $this->names($cookie));
        $this->coach->update(['outbound_key' => 'k-1234567890123456', 'removed_at' => now()]);
        $this->assertSame(['General'], $this->names($admin));
    }

    public function test_the_list_shows_names_and_descriptions_but_never_addresses_keys_or_profiles(): void
    {
        [$u, $cookie] = $this->employee();
        $this->give($u, $this->coach);

        $r = $this->device($cookie)->getJson('/api/chat/agents')->assertOk();

        $this->assertSame(['id', 'name', 'description', 'is_default'], array_keys($r->json('data.1')));
        $this->assertSame('Helps you improve how you work', $r->json('data.1.description'));
        foreach (['example.com', 'key-for', 'coach', 'general'] as $leak) {
            $this->assertStringNotContainsString($leak, strtolower(json_encode(array_map(fn ($a) => [$a['id'], $a['description'], $a['is_default']], $r->json('data')))));
        }
        $this->device(null)->getJson('/api/chat/agents')->assertStatus(401);
    }

    // ---- starting and continuing -----------------------------------------------------------------

    public function test_an_assigned_employee_can_chat_with_the_coach_and_it_uses_the_coachs_own_server_and_key(): void
    {
        Http::fake(['*' => Http::response($this->answer('Try this'))]);
        [$u, $cookie] = $this->employee();
        $this->give($u, $this->coach);

        $r = $this->device($cookie)->postJson('/api/chat', ['message' => 'Help me plan my week', 'agent_id' => $this->coach->id])->assertOk();

        $r->assertJsonPath('data.conversation.agent.name', 'Coach')->assertJsonPath('data.reply.content', 'Try this');
        Http::assertSent(fn ($req) => $req->url() === 'https://coach.example.com/p/coach/v1/chat/completions'
            && $req->hasHeader('Authorization', 'Bearer key-for-coach-1234567890') && $req['model'] === 'coach');
        $this->assertSame($this->coach->id, UsageRecord::sole()->agent_id);
        $this->assertSame($this->coach->id, Conversation::sole()->agent_id);
    }

    public function test_the_default_chatbot_is_used_when_none_is_named(): void
    {
        Http::fake(['*' => Http::response($this->answer())]);
        [, $cookie] = $this->employee();

        $this->device($cookie)->postJson('/api/chat', ['message' => 'hi'])->assertOk()->assertJsonPath('data.conversation.agent.name', 'General');

        Http::assertSent(fn ($req) => str_starts_with($req->url(), 'https://general.example.com/p/general/'));
    }

    public function test_an_employee_cannot_start_a_chat_with_a_chatbot_they_were_not_given(): void
    {
        Http::fake();
        [, $cookie] = $this->employee();

        $this->device($cookie)->postJson('/api/chat', ['message' => 'hi', 'agent_id' => $this->coach->id])->assertStatus(403)->assertJsonPath('error.code', 'agent_not_available');
        $this->device($cookie)->postJson('/api/chat', ['message' => 'hi', 'agent_id' => 99999])->assertStatus(403);

        Http::assertNothingSent();
        $this->assertSame(0, Conversation::count());
        $this->assertSame(0, Message::count());
        $this->assertSame(0, UsageRecord::count());
    }

    public function test_every_way_of_continuing_a_conversation_checks_access_again(): void
    {
        Http::fake(['*' => Http::response($this->answer())]);
        [$u, $cookie] = $this->employee();
        $this->give($u, $this->coach);
        $id = $this->device($cookie)->postJson('/api/chat', ['message' => 'plan', 'agent_id' => $this->coach->id])->json('data.conversation.id');
        $first = Message::where('conversation_id', $id)->where('role', 'user')->value('id');

        DB::table('agent_user')->where('user_id', $u->id)->delete(); // an admin takes the Coach away
        Http::fake();

        $this->device($cookie)->postJson('/api/chat', ['message' => 'more', 'conversation_id' => $id])->assertStatus(403)->assertJsonPath('error.code', 'agent_not_available');
        $this->device($cookie)->postJson("/api/conversations/{$id}/regenerate")->assertStatus(403);
        $this->device($cookie)->patchJson("/api/conversations/{$id}/messages/{$first}", ['message' => 'edited'])->assertStatus(403);
        Http::assertNothingSent();
        $this->assertSame(2, Message::where('conversation_id', $id)->count(), 'nothing was added or hidden');

        // The history stays readable, and the person can still tidy their own list.
        $this->device($cookie)->getJson("/api/conversations/{$id}")->assertOk()->assertJsonCount(2, 'data.messages')->assertJsonPath('data.agent.name', 'Coach');
        $this->device($cookie)->patchJson("/api/conversations/{$id}", ['title' => 'Old coaching'])->assertOk();
    }

    public function test_a_conversation_keeps_its_own_chatbot_whatever_the_request_says(): void
    {
        Http::fake(['*' => Http::response($this->answer())]);
        [$u, $cookie] = $this->employee();
        $this->give($u, $this->coach);
        $id = $this->device($cookie)->postJson('/api/chat', ['message' => 'one', 'agent_id' => $this->coach->id])->json('data.conversation.id');

        $this->device($cookie)->postJson('/api/chat', ['message' => 'two', 'conversation_id' => $id, 'agent_id' => $this->general->id])->assertOk();

        Http::assertSentCount(2);
        Http::assertSent(fn ($req) => str_starts_with($req->url(), 'https://coach.example.com/'));
        Http::assertNotSent(fn ($req) => str_starts_with($req->url(), 'https://general.example.com/'));
        $this->assertSame($this->coach->id, Conversation::find($id)->agent_id);
    }

    public function test_a_chatbot_that_is_switched_off_stops_its_conversations_for_everyone(): void
    {
        Http::fake(['*' => Http::response($this->answer())]);
        $admin = $this->cookieFor(Role::ADMIN);
        $id = $this->device($admin)->postJson('/api/chat', ['message' => 'hi', 'agent_id' => $this->coach->id])->json('data.conversation.id');
        $this->coach->update(['is_enabled' => false]);
        Http::fake();

        $this->device($admin)->postJson('/api/chat', ['message' => 'again', 'conversation_id' => $id])->assertStatus(403)->assertJsonPath('error.code', 'agent_not_available');
        Http::assertNothingSent();
    }

    public function test_with_several_chatbots_and_no_default_available_one_must_be_chosen(): void
    {
        [$u, $cookie] = $this->employee();
        $this->give($u, $this->coach);
        $this->general->update(['is_enabled' => false]); // the default is off: only the Coach is left, so it is used
        Http::fake(['*' => Http::response($this->answer())]);
        $this->device($cookie)->postJson('/api/chat', ['message' => 'hi'])->assertOk()->assertJsonPath('data.conversation.agent.name', 'Coach');

        $third = $this->agent('Writer', 'writer', 'https://writer.example.com', false);
        $this->give($u, $third);
        $this->device($cookie)->postJson('/api/chat', ['message' => 'hi'])->assertStatus(422)->assertJsonPath('error.code', 'agent_required');
    }

    public function test_an_employee_with_no_chatbot_at_all_is_told_so(): void
    {
        [, $cookie] = $this->employee();
        $this->general->update(['is_enabled' => false]);
        Http::fake();

        $this->device($cookie)->postJson('/api/chat', ['message' => 'hi'])->assertStatus(403)->assertJsonPath('error.code', 'no_chatbot');
        $this->assertSame([], $this->names($cookie));
    }

    public function test_conversations_from_before_agents_adopt_the_default_chatbot(): void
    {
        Http::fake(['*' => Http::response($this->answer('ok'))]);
        [$u, $cookie] = $this->employee();
        $c = Conversation::create(['user_id' => $u->id, 'title' => 'Old chat', 'agent_id' => null]);
        Message::create(['conversation_id' => $c->id, 'role' => 'user', 'content' => 'an old question']);

        $this->device($cookie)->postJson("/api/conversations/{$c->id}/regenerate")->assertOk();

        $this->assertSame($this->general->id, $c->fresh()->agent_id);
    }

    public function test_the_conversation_list_names_each_chatbot(): void
    {
        Http::fake(['*' => Http::response($this->answer())]);
        [$u, $cookie] = $this->employee();
        $this->give($u, $this->coach);
        $this->device($cookie)->postJson('/api/chat', ['message' => 'with general']);
        $this->device($cookie)->postJson('/api/chat', ['message' => 'with coach', 'agent_id' => $this->coach->id]);

        $list = $this->device($cookie)->getJson('/api/conversations')->assertOk()->json('data');

        $this->assertEqualsCanonicalizing(['General', 'Coach'], array_column(array_column($list, 'agent'), 'name'));
    }

    public function test_one_allowance_covers_both_chatbots(): void
    {
        Http::fake(['*' => Http::response($this->answer('x'))]);
        $u = $this->makeUser(Role::EMPLOYEE, ['daily_token_limit' => 60]);
        [, $cookie] = $this->signIn($u);
        $this->give($u, $this->coach);

        $this->device($cookie)->postJson('/api/chat', ['message' => 'a'])->assertOk(); // 35 tokens
        $this->device($cookie)->postJson('/api/chat', ['message' => 'b', 'agent_id' => $this->coach->id])->assertOk(); // 70 in total
        $this->device($cookie)->postJson('/api/chat', ['message' => 'c', 'agent_id' => $this->coach->id])->assertStatus(429)->assertJsonPath('error.code', 'token_limit');
        $this->device($cookie)->postJson('/api/chat', ['message' => 'd'])->assertStatus(429);
    }

    // ---- who can change access ---------------------------------------------------------------------

    public function test_an_admin_or_super_admin_sets_which_chatbots_an_employee_can_use_and_it_applies_at_once(): void
    {
        [$u, $cookie] = $this->employee();
        $admin = $this->cookieFor(Role::ADMIN);

        $r = $this->device($admin)->putJson("/api/users/{$u->id}/agents", ['agent_ids' => [$this->coach->id]])->assertOk();

        $general = collect($r->json('data.agents'))->firstWhere('name', 'General');
        $coach = collect($r->json('data.agents'))->firstWhere('name', 'Coach');
        $this->assertSame([true, true, true], [$general['assigned'], $general['is_default'], $coach['assigned']]);
        $this->assertSame(['General', 'Coach'], $this->names($cookie));

        $this->device($this->cookieFor(Role::SUPER_ADMIN))->putJson("/api/users/{$u->id}/agents", ['agent_ids' => []])->assertOk();
        $this->assertSame(['General'], $this->names($cookie));
        $this->assertSame(2, AuditEntry::where('action', 'agent.access_changed')->where('target_id', $u->id)->count());
        $entry = AuditEntry::where('action', 'agent.access_changed')->orderBy('id')->first();
        $this->assertSame([$this->coach->id], $entry->metadata['added']);
    }

    public function test_the_access_screen_shows_the_current_state(): void
    {
        $u = $this->makeUser(Role::EMPLOYEE);
        $this->give($u, $this->coach);
        $admin = $this->cookieFor(Role::ADMIN);

        $r = $this->device($admin)->getJson("/api/users/{$u->id}/agents")->assertOk();

        $this->assertFalse($r->json('data.all_access'));
        $this->assertSame([true, true], array_column($r->json('data.agents'), 'assigned'));
        $other = $this->makeUser(Role::ADMIN);
        $this->assertTrue($this->device($this->cookieFor(Role::SUPER_ADMIN))->getJson("/api/users/{$other->id}/agents")->json('data.all_access'));
    }

    public function test_the_account_boundary_applies_to_chatbot_access_too(): void
    {
        $emp = $this->makeUser(Role::EMPLOYEE);
        $otherAdmin = $this->makeUser(Role::ADMIN);
        $body = ['agent_ids' => [$this->coach->id]];

        $this->device(null)->putJson("/api/users/{$emp->id}/agents", $body)->assertStatus(401);
        $this->device($this->cookieFor(Role::EMPLOYEE))->putJson("/api/users/{$emp->id}/agents", $body)->assertStatus(403);
        $this->device($this->cookieFor(Role::EMPLOYEE))->getJson("/api/users/{$emp->id}/agents")->assertStatus(403);
        $this->device($this->cookieFor(Role::ADMIN))->putJson("/api/users/{$otherAdmin->id}/agents", $body)->assertStatus(403);
        $this->device($this->cookieFor(Role::SUPER_ADMIN))->putJson("/api/users/{$otherAdmin->id}/agents", $body)->assertStatus(422)->assertJsonPath('error.code', 'not_applicable');
        $this->assertSame(0, DB::table('agent_user')->count());
    }

    public function test_only_real_extra_chatbots_can_be_assigned(): void
    {
        $emp = $this->makeUser(Role::EMPLOYEE);
        $admin = $this->cookieFor(Role::ADMIN);
        $gone = $this->agent('Gone', 'gone', 'https://gone.example.com', false);
        $gone->update(['removed_at' => now()]);

        foreach ([[$this->general->id], [99999], [$gone->id], ['x'], [$this->coach->id, $this->coach->id]] as $ids) {
            $this->device($admin)->putJson("/api/users/{$emp->id}/agents", ['agent_ids' => $ids])->assertStatus(422);
        }
        $this->device($admin)->putJson("/api/users/{$emp->id}/agents", [])->assertStatus(422);
        $this->assertSame(0, DB::table('agent_user')->count());
    }

    public function test_setting_the_same_access_twice_changes_nothing(): void
    {
        $emp = $this->makeUser(Role::EMPLOYEE);
        $admin = $this->cookieFor(Role::ADMIN);

        $this->device($admin)->putJson("/api/users/{$emp->id}/agents", ['agent_ids' => [$this->coach->id]])->assertOk();
        $this->device($admin)->putJson("/api/users/{$emp->id}/agents", ['agent_ids' => [$this->coach->id]])->assertOk();

        $this->assertSame(1, DB::table('agent_user')->where('user_id', $emp->id)->count());
    }
}
