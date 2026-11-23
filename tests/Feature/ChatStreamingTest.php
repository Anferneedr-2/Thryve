<?php

namespace Tests\Feature;

use App\Ai\HermesClient;
use App\Ai\StreamTransport;
use App\Models\Attachment;
use App\Models\Message;
use App\Models\Role;
use App\Models\UsageRecord;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\SimulatesDevices;
use Tests\TestCase;

class ChatStreamingTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
        $this->seed(RoleSeeder::class);
        config(['services.hermes.url' => 'http://hermes.test/p/bot', 'services.hermes.key' => 'synthetic']);
        Http::preventStrayRequests();
        $sse = $this->sse();
        $this->mock(StreamTransport::class, function ($mock) use ($sse) {
            $mock->shouldReceive('read')->andReturnUsing(function ($url, $headers, $payload, $timeout, $receive) use ($sse) {
                $this->assertTrue($payload['stream']);
                foreach (str_split($sse, 7) as $chunk) {
                    $receive($chunk);
                }

                return false;
            });
        });
    }

    public function test_followup_after_an_empty_stop_does_not_send_an_empty_assistant_turn(): void
    {
        $cookie = $this->cookieFor(Role::EMPLOYEE);
        $this->mock(HermesClient::class, function ($mock) {
            $mock->shouldReceive('stream')->once()->andReturn(['content' => '', 'input' => 10, 'output' => 0, 'estimate' => true, 'finish' => 'cancelled']);
            $mock->shouldReceive('chat')->once()->andReturnUsing(function ($context) {
                foreach ($context as $turn) {
                    $this->assertNotSame('', $turn['content']);
                }

                return ['content' => 'Next answer', 'input' => 10, 'output' => 3, 'estimate' => false, 'finish' => 'stop'];
            });
        });
        $r = $this->device($cookie)->postJson('/api/chat', ['message' => 'First question'], ['Accept' => 'text/event-stream', 'X-Chat-Stream-Id' => (string) Str::uuid()]);
        $r->streamedContent();
        $id = Message::first()->conversation_id;
        $this->device($cookie)->postJson('/api/chat', ['message' => 'Follow up', 'conversation_id' => $id])->assertOk();
    }

    private function sse(): string
    {
        return 'data: '.json_encode(['choices' => [['delta' => ['content' => 'Hello ']]]])."\n\n"
            .'data: '.json_encode(['choices' => [['delta' => ['content' => '世界'], 'finish_reason' => 'stop']]])."\n\n"
            .'data: '.json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 5]])."\n\n"
            ."data: [DONE]\n\n";
    }

    public function test_provider_stream_decodes_split_events_and_real_usage(): void
    {
        Http::fake(['*' => Http::response($this->sse(), 200, ['Content-Type' => 'text/event-stream'])]);
        $parts = [];
        $out = app(HermesClient::class)->stream([['role' => 'user', 'content' => 'Hi']], null, 'fixture-session', function ($text) use (&$parts) {
            $parts[] = $text;
        }, fn () => false);
        $this->assertSame('Hello 世界', $out['content']);
        $this->assertSame(['Hello ', '世界'], $parts);
        $this->assertSame(12, $out['input']);
        $this->assertFalse($out['estimate']);
        Http::assertNothingSent();
    }

    public function test_streaming_chat_saves_the_answer_and_one_attempt(): void
    {
        Http::fake(['*' => Http::response($this->sse(), 200, ['Content-Type' => 'text/event-stream'])]);
        $cookie = $this->cookieFor(Role::EMPLOYEE);
        $id = (string) Str::uuid();
        $response = $this->device($cookie)->postJson('/api/chat', ['message' => 'Hi'], ['Accept' => 'text/event-stream', 'X-Chat-Stream-Id' => $id]);
        $response->assertOk()->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
        $body = $response->streamedContent();
        $this->assertStringContainsString('event: delta', $body);
        $this->assertStringContainsString('event: complete', $body);
        $this->assertDatabaseHas('messages', ['role' => 'assistant', 'content' => 'Hello 世界']);
        $this->assertDatabaseHas('usage_records', ['status' => 'success', 'input_tokens' => 12, 'output_tokens' => 5]);
        $this->assertSame(1, UsageRecord::count());
        $this->assertDatabaseHas('chat_requests', ['id' => $id, 'status' => 'completed']);
    }

    public function test_stop_is_owner_scoped_and_preserves_partial_answer(): void
    {
        $cookie = $this->cookieFor(Role::EMPLOYEE);
        $id = (string) Str::uuid();
        $this->mock(HermesClient::class, function ($mock) use ($id) {
            $mock->shouldReceive('stream')->once()->andReturnUsing(function ($messages, $target, $session, $emit, $stop) use ($id) {
                $emit('Partial answer');
                DB::table('chat_requests')->where('id', $id)->update(['cancel_requested' => true]);
                $this->assertTrue($stop());

                return ['content' => 'Partial answer', 'input' => 30, 'output' => 4, 'estimate' => true, 'finish' => 'cancelled', 'model' => 'fixture'];
            });
        });
        $response = $this->device($cookie)->postJson('/api/chat', ['message' => 'Explain'], ['Accept' => 'text/event-stream', 'X-Chat-Stream-Id' => $id]);
        $response->streamedContent();
        $this->assertDatabaseHas('messages', ['role' => 'assistant', 'content' => 'Partial answer', 'completion_status' => 'cancelled']);
        $this->assertDatabaseHas('usage_records', ['status' => 'cancelled', 'is_estimate' => true]);
        $other = $this->cookieFor(Role::EMPLOYEE);
        $this->device($other)->postJson('/api/chat/streams/'.$id.'/stop')->assertNotFound();
        $this->device($cookie)->postJson('/api/chat/streams/'.$id.'/stop')->assertOk();
        $this->assertSame(1, UsageRecord::count());
    }

    public function test_generated_payload_is_never_emitted_or_saved_as_a_partial_file(): void
    {
        $cookie = $this->cookieFor(Role::EMPLOYEE);
        $text = "Here is the report.\n```thryve-file\n{\"format\":\"csv\",\"rows\":[[\"private cell\"]]}\n```";
        $this->mock(HermesClient::class, function ($mock) use ($text) {
            $mock->shouldReceive('stream')->once()->andReturnUsing(function ($m, $t, $s, $emit) use ($text) {
                foreach (str_split($text, 3) as $chunk) {
                    $emit($chunk);
                }

                return ['content' => $text, 'input' => 10, 'output' => 30, 'estimate' => true, 'finish' => 'cancelled'];
            });
        });
        $r = $this->device($cookie)->postJson('/api/chat', ['message' => 'Make a CSV'], ['Accept' => 'text/event-stream', 'X-Chat-Stream-Id' => (string) Str::uuid()]);
        $body = $r->streamedContent();
        $this->assertStringNotContainsString('private cell', $body);
        $this->assertStringNotContainsString('thryve-file', $body);
        $this->assertSame(0, Attachment::count());
        $this->assertStringNotContainsString('private cell', Message::where('role', 'assistant')->value('content'));
    }

    public function test_provider_error_frames_are_not_exposed_and_partial_text_survives(): void
    {
        $this->mock(StreamTransport::class, function ($mock) {
            $mock->shouldReceive('read')->andReturnUsing(function ($url, $headers, $payload, $timeout, $receive) {
                $receive('data: '.json_encode(['choices' => [['delta' => ['content' => 'Useful partial response']]]])."\n\n");
                $receive('data: '.json_encode(['choices' => [['delta' => ['content' => 'raw-secret-provider-detail'], 'finish_reason' => 'error']], 'hermes' => ['failed' => true]])."\n\n");

                return false;
            });
        });
        $cookie = $this->cookieFor(Role::EMPLOYEE);
        $r = $this->device($cookie)->postJson('/api/chat', ['message' => 'Explain'], ['Accept' => 'text/event-stream', 'X-Chat-Stream-Id' => (string) Str::uuid()]);
        $body = $r->streamedContent();
        $this->assertStringContainsString('event: failure', $body);
        $this->assertStringNotContainsString('raw-secret-provider-detail', $body);
        $this->assertDatabaseHas('messages', ['content' => 'Useful partial response', 'completion_status' => 'failed']);
        $this->assertDatabaseHas('usage_records', ['status' => 'failed', 'is_estimate' => true]);
    }

    public function test_provider_limit_notice_sent_as_answer_text_is_not_saved_as_an_answer(): void
    {
        $this->mock(StreamTransport::class, function ($mock) {
            $mock->shouldReceive('read')->andReturnUsing(function ($url, $headers, $payload, $timeout, $receive) {
                $notice = 'ChatGPT or Codex Subscription rate-limited every one of 3 attempts. Send /retry after that. Provider said: HTTP 429: The usage limit has been reached';
                $receive('data: '.json_encode(['choices' => [['delta' => ['content' => $notice], 'finish_reason' => 'stop']]])."\n\n");
                $receive("data: [DONE]\n\n");

                return false;
            });
        });
        $cookie = $this->cookieFor(Role::EMPLOYEE);
        $r = $this->device($cookie)->postJson('/api/chat', ['message' => 'Explain'], ['Accept' => 'text/event-stream', 'X-Chat-Stream-Id' => (string) Str::uuid()]);
        $this->assertStringContainsString('event: failure', $r->streamedContent());
        $this->assertDatabaseMissing('messages', ['role' => 'assistant']);
        $this->assertDatabaseHas('usage_records', ['status' => 'failed']);
    }

    public function test_unfinished_stream_is_not_silently_treated_as_success(): void
    {
        $this->mock(StreamTransport::class, function ($mock) {
            $mock->shouldReceive('read')->andReturnUsing(function ($url, $headers, $payload, $timeout, $receive) {
                $receive('data: '.json_encode(['choices' => [['delta' => ['content' => 'Incomplete answer']]]])."\n\n");

                return false;
            });
        });
        $cookie = $this->cookieFor(Role::EMPLOYEE);
        $r = $this->device($cookie)->postJson('/api/chat', ['message' => 'Explain'], ['Accept' => 'text/event-stream', 'X-Chat-Stream-Id' => (string) Str::uuid()]);
        $this->assertStringContainsString('event: failure', $r->streamedContent());
        $this->assertDatabaseHas('messages', ['content' => 'Incomplete answer', 'completion_status' => 'failed']);
        $this->assertSame('failed', UsageRecord::first()->status);
    }

    public function test_running_and_replayed_requests_cannot_duplicate_or_edit_messages(): void
    {
        $cookie = $this->cookieFor(Role::EMPLOYEE);
        $id = (string) Str::uuid();
        $headers = ['Accept' => 'text/event-stream', 'X-Chat-Stream-Id' => $id];
        $r = $this->device($cookie)->postJson('/api/chat', ['message' => 'Only once'], $headers)->assertOk();
        $this->device($cookie)->postJson('/api/chat', ['message' => 'Duplicate'], $headers)->assertConflict();
        $this->assertSame(1, Message::where('role', 'user')->count());
        $r->streamedContent();
        $this->device($cookie)->postJson('/api/chat', ['message' => 'Replay'], $headers)->assertConflict();
        $this->assertSame(1, UsageRecord::count());
    }

    public function test_revoked_session_stops_before_forwarding_a_prompt(): void
    {
        $cookie = $this->cookieFor(Role::EMPLOYEE);
        $r = $this->device($cookie)->postJson('/api/chat', ['message' => 'Do not forward'], ['Accept' => 'text/event-stream', 'X-Chat-Stream-Id' => (string) Str::uuid()])->assertOk();
        User::query()->update(['is_active' => false]);
        $r->streamedContent();
        $this->assertDatabaseHas('usage_records', ['status' => 'cancelled', 'input_tokens' => 0, 'output_tokens' => 0]);
        $this->assertDatabaseHas('messages', ['completion_status' => 'cancelled', 'content' => '']);
    }

    public function test_revocation_keeps_partial_server_side_without_sending_withheld_content(): void
    {
        $cookie = $this->cookieFor(Role::EMPLOYEE);
        $this->mock(HermesClient::class, function ($mock) {
            $mock->shouldReceive('stream')->andReturnUsing(function ($m, $t, $s, $emit, $stop) {
                $emit('Withheld private tail');
                User::query()->update(['is_active' => false]);
                $this->assertTrue($stop());

                return ['content' => 'Withheld private tail', 'input' => 20, 'output' => 5, 'estimate' => true, 'finish' => 'cancelled'];
            });
        });
        $r = $this->device($cookie)->postJson('/api/chat', ['message' => 'Explain'], ['Accept' => 'text/event-stream', 'X-Chat-Stream-Id' => (string) Str::uuid()]);
        $body = $r->streamedContent();
        $this->assertStringNotContainsString('Withheld private tail', $body);
        $this->assertStringNotContainsString('event: complete', $body);
        $this->assertDatabaseHas('messages', ['content' => 'Withheld private tail', 'completion_status' => 'cancelled']);
    }

    public function test_malformed_file_marker_cannot_leak_through_the_final_event(): void
    {
        $cookie = $this->cookieFor(Role::EMPLOYEE);
        $text = "A report.\n```THRYVE-FILE\n{\"rows\":[[\"private malformed cell\"]]}\n```";
        $this->mock(HermesClient::class, function ($mock) use ($text) {
            $mock->shouldReceive('stream')->andReturnUsing(function ($m, $t, $s, $emit) use ($text) {
                $emit($text);

                return ['content' => $text, 'input' => 10, 'output' => 30, 'estimate' => false, 'finish' => 'stop'];
            });
        });
        $r = $this->device($cookie)->postJson('/api/chat', ['message' => 'Make a file'], ['Accept' => 'text/event-stream', 'X-Chat-Stream-Id' => (string) Str::uuid()]);
        $body = $r->streamedContent();
        $this->assertStringNotContainsString('private malformed cell', $body);
        $this->assertStringContainsString('event: failure', $body);
        $this->assertSame(0, Message::where('role', 'assistant')->count());
    }
}
