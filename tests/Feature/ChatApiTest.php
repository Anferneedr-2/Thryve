<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Role;
use App\Models\UsageRecord;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SimulatesDevices;
use Tests\TestCase;

class ChatApiTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
        $this->seed(\Database\Seeders\RoleSeeder::class);
        config(['services.hermes.url' => 'http://hermes.test/p/bot', 'services.hermes.key' => 'k-1234567890123456']);
    }

    private function answer(string $text = 'Hello there', int $in = 100, int $out = 20): array
    {
        return ['model' => 'bot', 'choices' => [['message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out, 'total_tokens' => $in + $out]];
    }

    private function me(array $attrs = []): array
    {
        $user = $this->makeUser(Role::EMPLOYEE, $attrs);
        [, $cookie] = $this->signIn($user);

        return [$user, $cookie];
    }

    private function say(string $cookie, string $text, array $extra = [])
    {
        return $this->device($cookie)->postJson('/api/chat', ['message' => $text] + $extra);
    }

    public function test_a_message_gets_an_answer_and_is_saved_and_counted(): void
    {
        Http::fake(['*' => Http::response($this->answer('Hi! How can I help?', 300, 40))]);
        [$user, $cookie] = $this->me();

        $r = $this->say($cookie, 'Hello')->assertOk();

        $r->assertJsonPath('data.reply.content', 'Hi! How can I help?')->assertJsonPath('data.conversation.title', 'Hello');
        $id = $r->json('data.conversation.id');
        $this->device($cookie)->getJson("/api/conversations/{$id}")->assertOk()->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.0.role', 'user')->assertJsonPath('data.messages.1.role', 'assistant');
        $this->assertSame(340, (int) UsageRecord::where('user_id', $user->id)->where('purpose', 'chat')->sum(\DB::raw('input_tokens + output_tokens')));
        $this->device($cookie)->getJson('/api/usage/today')->assertJsonPath('data.used', 340);
    }

    public function test_follow_up_messages_send_the_earlier_conversation_as_context(): void
    {
        Http::fake(['*' => Http::sequence()->push($this->answer('First'))->push($this->answer('Second'))]);
        [, $cookie] = $this->me();

        $id = $this->say($cookie, 'one')->json('data.conversation.id');
        $this->say($cookie, 'two', ['conversation_id' => $id])->assertOk();

        Http::assertSent(fn ($req) => count($req['messages']) === 3
            && $req['messages'][0]['content'] === 'one' && $req['messages'][1]['content'] === 'First' && $req['messages'][2]['content'] === 'two');
    }

    public function test_conversations_are_private_to_their_owner(): void
    {
        Http::fake(['*' => Http::response($this->answer())]);
        [, $a] = $this->me();
        [, $b] = $this->me();
        $id = $this->say($a, 'my secret plan')->json('data.conversation.id');
        $msg = Message::where('conversation_id', $id)->where('role', 'assistant')->value('id');

        foreach ([['get', "/api/conversations/{$id}"], ['patch', "/api/conversations/{$id}"], ['delete', "/api/conversations/{$id}"], ['post', "/api/conversations/{$id}/regenerate"],
            ['post', "/api/conversations/{$id}/messages/{$msg}/rating"]] as [$m, $url]) {
            $this->device($b)->json($m, $url, ['title' => 'x', 'rating' => 'up'])->assertNotFound();
        }
        $this->say($b, 'hi', ['conversation_id' => $id])->assertNotFound();
        $this->device($b)->getJson('/api/conversations')->assertJsonCount(0, 'data');
        $this->device($b)->getJson('/api/conversations?q=secret')->assertJsonCount(0, 'data');
        $this->device($a)->getJson('/api/conversations?q=secret')->assertJsonCount(1, 'data');
    }

    public function test_chat_needs_a_signed_in_person(): void
    {
        $this->device(null)->postJson('/api/chat', ['message' => 'x'])->assertStatus(401);
        $this->device(null)->getJson('/api/conversations')->assertStatus(401);
    }

    public function test_empty_and_oversized_messages_are_rejected_without_cost(): void
    {
        Http::fake();
        [, $cookie] = $this->me();

        $this->say($cookie, '   ')->assertStatus(422);
        $this->say($cookie, str_repeat('a', 20001))->assertStatus(422);
        Http::assertNothingSent();
        $this->assertSame(0, Conversation::count());
    }

    public function test_sensitive_data_warns_then_sends_when_acknowledged(): void
    {
        Http::fake(['*' => Http::response($this->answer())]);
        [, $cookie] = $this->me();
        $card = 'My card is 4111 1111 1111 1111';

        $this->say($cookie, $card)->assertStatus(409)->assertJsonPath('error.code', 'sensitive_warning');
        Http::assertNothingSent();
        $this->assertSame(0, Message::count(), 'a warned message is not saved');
        $this->say($cookie, $card, ['acknowledge_sensitive' => true])->assertOk();
        Http::assertSentCount(1);
    }

    public function test_sensitive_data_is_refused_in_block_mode_even_if_acknowledged(): void
    {
        Http::fake();
        [, $cookie] = $this->me();
        Settings::update(['sensitive_data_mode' => 'block'], $this->makeUser(Role::SUPER_ADMIN));

        $this->say($cookie, 'the password is hunter2', ['acknowledge_sensitive' => true])->assertStatus(422)->assertJsonPath('error.code', 'sensitive_blocked');
        Http::assertNothingSent();
    }

    public function test_ordinary_numbers_are_not_flagged(): void
    {
        Http::fake(['*' => Http::response($this->answer())]);
        [, $cookie] = $this->me();

        $this->say($cookie, 'Invoice 2026-11-19 total 1234567890123 units, call 555-1234')->assertOk();
    }

    public function test_an_exhausted_allowance_stops_the_request_before_the_provider(): void
    {
        Http::fake(['*' => Http::response($this->answer('x', 60, 10))]);
        [, $cookie] = $this->me(['daily_token_limit' => 50]);

        $this->say($cookie, 'first')->assertOk(); // over the limit now (70 > 50), but the check is before sending
        $r = $this->say($cookie, 'second')->assertStatus(429)->assertJsonPath('error.code', 'token_limit');

        Http::assertSentCount(1);
        $this->assertArrayNotHasKey('provider', $r->json('error') ?? []);
        $this->assertSame(2, Message::where('role', 'user')->count(), 'the unsent message is kept so it can be retried');
    }

    public function test_a_failed_answer_keeps_the_message_and_retry_works(): void
    {
        Http::fake(['*' => Http::sequence()->push(['error' => 'x'], 502)->push($this->answer('Recovered'))]);
        [, $cookie] = $this->me();

        $r = $this->say($cookie, 'help me')->assertStatus(502); // a server error may have done work, so it is not retried elsewhere
        $id = $r->json('data.conversation.id') ?? $r->json('error.details.conversation.id');
        $this->assertNotNull($id);
        $this->assertSame(1, Message::where('conversation_id', $id)->count());

        $this->device($cookie)->postJson("/api/conversations/{$id}/regenerate")->assertOk()->assertJsonPath('data.reply.content', 'Recovered');
        $this->assertSame(2, Message::where('conversation_id', $id)->count());
    }

    public function test_a_timeout_says_it_may_still_finish_and_is_not_sent_twice(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 60000 milliseconds'));
        [, $cookie] = $this->me();

        $this->say($cookie, 'long task')->assertStatus(502)->assertJsonPath('error.code', 'ai_failed');
        $this->assertSame(1, UsageRecord::count());
    }

    public function test_provider_text_never_reaches_the_person(): void
    {
        Http::fake(['*' => Http::response($this->answer('x') + ['hermes' => ['failed' => true, 'error' => 'HTTP 400 secret-upstream-detail'], 'choices' => [['message' => ['content' => 'API call failed: secret-upstream-detail'], 'finish_reason' => 'error']]])]);
        [, $cookie] = $this->me();

        $r = $this->say($cookie, 'hi');

        $this->assertStringNotContainsString('secret-upstream-detail', $r->getContent());
    }

    public function test_regenerate_replaces_the_answer_and_counts_again_but_keeps_history(): void
    {
        Http::fake(['*' => Http::sequence()->push($this->answer('v1', 100, 10))->push($this->answer('v2', 100, 10))]);
        [$user, $cookie] = $this->me();
        $id = $this->say($cookie, 'write it')->json('data.conversation.id');

        $this->device($cookie)->postJson("/api/conversations/{$id}/regenerate")->assertOk()->assertJsonPath('data.reply.content', 'v2');

        $this->device($cookie)->getJson("/api/conversations/{$id}")->assertJsonCount(2, 'data.messages')->assertJsonPath('data.messages.1.content', 'v2');
        $this->assertSame(3, Message::where('conversation_id', $id)->count(), 'the old answer is hidden, not erased');
        $this->assertSame(220, (int) UsageRecord::where('user_id', $user->id)->sum(\DB::raw('input_tokens + output_tokens')));
    }

    public function test_editing_a_message_replaces_it_and_everything_after(): void
    {
        Http::fake(['*' => Http::sequence()->push($this->answer('a1'))->push($this->answer('a2'))->push($this->answer('a-edited'))]);
        [, $cookie] = $this->me();
        $id = $this->say($cookie, 'first')->json('data.conversation.id');
        $this->say($cookie, 'second', ['conversation_id' => $id]);
        $first = Message::where('conversation_id', $id)->where('role', 'user')->orderBy('id')->value('id');

        $this->device($cookie)->patchJson("/api/conversations/{$id}/messages/{$first}", ['message' => 'first, reworded'])->assertOk()->assertJsonPath('data.reply.content', 'a-edited');

        $this->device($cookie)->getJson("/api/conversations/{$id}")->assertJsonCount(2, 'data.messages')->assertJsonPath('data.messages.0.content', 'first, reworded');
        $this->assertSame(6, Message::where('conversation_id', $id)->count());
        Http::assertSent(fn ($req) => count($req['messages']) === 1 && $req['messages'][0]['content'] === 'first, reworded');
    }

    public function test_only_own_user_messages_can_be_edited_and_only_answers_rated(): void
    {
        Http::fake(['*' => Http::response($this->answer())]);
        [, $cookie] = $this->me();
        $id = $this->say($cookie, 'hi')->json('data.conversation.id');
        $user = Message::where('role', 'user')->value('id');
        $bot = Message::where('role', 'assistant')->value('id');

        $this->device($cookie)->patchJson("/api/conversations/{$id}/messages/{$bot}", ['message' => 'x'])->assertNotFound();
        $this->device($cookie)->postJson("/api/conversations/{$id}/messages/{$user}/rating", ['rating' => 'up'])->assertNotFound();
        $this->device($cookie)->postJson("/api/conversations/{$id}/messages/{$bot}/rating", ['rating' => 'up'])->assertOk()->assertJsonPath('data.rating', 'up');
        $this->device($cookie)->postJson("/api/conversations/{$id}/messages/{$bot}/rating", ['rating' => 'sideways'])->assertStatus(422);
        $this->device($cookie)->postJson("/api/conversations/{$id}/messages/{$bot}/rating", ['rating' => null])->assertOk()->assertJsonPath('data.rating', null);
    }

    public function test_rename_archive_search_and_delete(): void
    {
        Http::fake(['*' => Http::response($this->answer('The budget is fine'))]);
        [, $cookie] = $this->me();
        $id = $this->say($cookie, 'quarterly report')->json('data.conversation.id');

        $this->device($cookie)->patchJson("/api/conversations/{$id}", ['title' => 'Q4 report'])->assertOk()->assertJsonPath('data.title', 'Q4 report');
        $this->device($cookie)->getJson('/api/conversations?q=budget')->assertJsonCount(1, 'data'); // found through an answer
        $this->device($cookie)->patchJson("/api/conversations/{$id}", ['title' => ''])->assertStatus(422);

        $this->device($cookie)->patchJson("/api/conversations/{$id}", ['archived' => true])->assertOk();
        $this->device($cookie)->getJson('/api/conversations')->assertJsonCount(0, 'data');
        $this->device($cookie)->getJson('/api/conversations?archived=1')->assertJsonCount(1, 'data');

        $this->device($cookie)->deleteJson("/api/conversations/{$id}")->assertOk();
        $this->device($cookie)->getJson("/api/conversations/{$id}")->assertNotFound();
        $this->assertNotNull(Conversation::find($id)->deleted_at, 'soft-deleted, not erased');
    }

    public function test_search_treats_wildcards_literally(): void
    {
        Http::fake(['*' => Http::response($this->answer('plain'))]);
        [, $cookie] = $this->me();
        $this->say($cookie, 'alpha');

        $this->device($cookie)->getJson('/api/conversations?q=%25')->assertJsonCount(0, 'data');
    }
}
