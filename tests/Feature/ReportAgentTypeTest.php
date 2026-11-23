<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Role;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SimulatesDevices;
use Tests\TestCase;

/** A report agent does background analysis. It must never behave like a chatbot anywhere. */
class ReportAgentTypeTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
        $this->seed(RoleSeeder::class);
        config(['services.hermes.url' => null, 'services.hermes.key' => null]);
    }

    private function agent(string $name, string $type = 'chat', bool $default = false): Agent
    {
        return Agent::create(['name' => $name, 'slug' => str($name)->slug(), 'type' => $type, 'base_url' => 'https://'.str($name)->slug().'.example.com', 'profile' => str($name)->slug(),
            'is_default' => $default, 'outbound_key' => 'key-'.str($name)->slug().'-1234567890', 'outbound_fingerprint' => substr(md5($name), 0, 8)]);
    }

    private function signedIn(string $role): array
    {
        $u = $this->makeUser($role);
        [, $cookie] = $this->signIn($u);

        return [$u, $cookie];
    }

    public function test_agents_are_chatbots_unless_they_are_made_report_agents(): void
    {
        $this->assertSame('chat', Agent::create(['name' => 'Plain', 'slug' => 'plain', 'base_url' => 'https://p.example.com'])->fresh()->type);
    }

    public function test_a_report_agent_never_appears_in_any_ones_chat_list(): void
    {
        $this->agent('General', default: true);
        $this->agent('Report AI', 'report');

        foreach ([Role::EMPLOYEE, Role::ADMIN, Role::SUPER_ADMIN] as $role) {
            [, $cookie] = $this->signedIn($role);
            $names = array_column($this->device($cookie)->getJson('/api/chat/agents')->assertOk()->json('data'), 'name');
            $this->assertSame(['General'], $names, "{$role} must not see the report agent");
        }
    }

    public function test_a_chat_cannot_be_started_with_a_report_agent(): void
    {
        $this->agent('General', default: true);
        $report = $this->agent('Report AI', 'report');
        [, $cookie] = $this->signedIn(Role::SUPER_ADMIN);
        Http::fake();

        $this->device($cookie)->postJson('/api/chat', ['message' => 'hello', 'agent_id' => $report->id])->assertStatus(403);

        Http::assertNothingSent();
    }

    public function test_a_report_agent_cannot_be_given_to_an_employee(): void
    {
        $this->agent('General', default: true);
        $report = $this->agent('Report AI', 'report');
        [, $admin] = $this->signedIn(Role::SUPER_ADMIN);
        $employee = $this->makeUser(Role::EMPLOYEE);

        $this->device($admin)->putJson("/api/users/{$employee->id}/agents", ['agent_ids' => [$report->id]])->assertStatus(422);

        $listed = array_column($this->device($admin)->getJson("/api/users/{$employee->id}/agents")->assertOk()->json('data.agents'), 'name');
        $this->assertSame(['General'], $listed);
    }

    public function test_a_report_agent_created_first_is_not_the_default_chatbot_and_the_first_chatbot_still_is(): void
    {
        [, $cookie] = $this->signedIn(Role::SUPER_ADMIN);

        $report = $this->device($cookie)->postJson('/api/agents', ['name' => 'Report AI', 'type' => 'report', 'base_url' => 'https://r.example.com', 'profile' => 'report-ai'])->assertCreated();
        $this->assertSame('report', $report->json('data.type'));
        $this->assertFalse($report->json('data.is_default'));

        $chat = $this->device($cookie)->postJson('/api/agents', ['name' => 'General', 'base_url' => 'https://g.example.com', 'profile' => 'general'])->assertCreated();
        $this->assertSame('chat', $chat->json('data.type'));
        $this->assertTrue($chat->json('data.is_default'));
    }

    public function test_only_one_report_agent_can_be_set_up(): void
    {
        [, $cookie] = $this->signedIn(Role::SUPER_ADMIN);
        $body = ['name' => 'Report AI', 'type' => 'report', 'base_url' => 'https://r.example.com', 'profile' => 'report-ai'];

        $this->device($cookie)->postJson('/api/agents', $body)->assertCreated();
        $this->device($cookie)->postJson('/api/agents', ['name' => 'Second report'] + $body)->assertStatus(422)->assertJsonPath('error.code', 'report_agent_exists');
    }

    public function test_an_unknown_agent_type_is_rejected(): void
    {
        [, $cookie] = $this->signedIn(Role::SUPER_ADMIN);

        $this->device($cookie)->postJson('/api/agents', ['name' => 'X', 'type' => 'coach', 'base_url' => 'https://x.example.com'])->assertStatus(422);
    }

    public function test_a_report_agent_cannot_be_made_the_default_chatbot(): void
    {
        $this->agent('General', default: true);
        $report = $this->agent('Report AI', 'report');
        [, $cookie] = $this->signedIn(Role::SUPER_ADMIN);

        $this->device($cookie)->patchJson("/api/agents/{$report->id}", ['is_default' => true])->assertStatus(422);

        $this->assertFalse($report->fresh()->is_default);
        $this->assertTrue((bool) Agent::where('slug', 'general')->first()->is_default);
    }

    public function test_the_agents_screen_still_lists_the_report_agent_for_admins_with_its_type(): void
    {
        $this->agent('General', default: true);
        $this->agent('Report AI', 'report');
        [, $cookie] = $this->signedIn(Role::ADMIN);

        $rows = collect($this->device($cookie)->getJson('/api/agents')->assertOk()->json('data'))->pluck('type', 'name')->all();

        $this->assertSame(['General' => 'chat', 'Report AI' => 'report'], $rows);
    }

    public function test_removing_the_default_chatbot_is_allowed_when_only_a_report_agent_remains(): void
    {
        $general = $this->agent('General', default: true);
        $this->agent('Report AI', 'report');
        [, $cookie] = $this->signedIn(Role::SUPER_ADMIN);

        $this->device($cookie)->deleteJson("/api/agents/{$general->id}")->assertOk();
    }
}
