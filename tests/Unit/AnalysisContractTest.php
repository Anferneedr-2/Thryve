<?php

namespace Tests\Unit;

use App\Analysis\AnalysisContract;
use App\Analysis\AnalysisInvalid;
use PHPUnit\Framework\TestCase;

/** The request we build and the reply we accept. Anything the agent says that breaks the contract is rejected. */
class AnalysisContractTest extends TestCase
{
    private const REF = 'an_TEST0001';

    private array $topics = [
        ['id' => 4, 'name' => 'Business writing', 'description' => 'Emails'],
        ['id' => 9, 'name' => 'Other work question', 'description' => 'Fallback'],
    ];

    private function reply(array $override = []): string
    {
        return json_encode(array_replace([
            'request_ref' => self::REF, 'rubric_version' => 'v1', 'task_label' => 'Draft client follow-up email', 'topic_ids' => [4],
            'scores' => ['clarity' => 4, 'context' => 3, 'specifics' => 2, 'refinement' => 4, 'verification' => 1], 'tip' => 'State the tone you want.',
        ], $override));
    }

    private function rejected(string $reply, string $reason): void
    {
        try {
            AnalysisContract::parse($reply, self::REF, [4, 9]);
            $this->fail("Expected the reply to be rejected as {$reason}");
        } catch (AnalysisInvalid $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    // ---- the reply -------------------------------------------------------------------------------

    public function test_a_correct_reply_is_accepted(): void
    {
        $r = AnalysisContract::parse($this->reply(), self::REF, [4, 9]);

        $this->assertSame('valid', $r['status']);
        $this->assertSame('Draft client follow-up email', $r['task_label']);
        $this->assertSame([4], $r['topic_ids']);
        $this->assertSame('State the tone you want.', $r['tip']);
        $this->assertSame(['clarity' => 4, 'context' => 3, 'specifics' => 2, 'refinement' => 4, 'verification' => 1], $r['scores']);
    }

    public function test_one_code_fence_around_the_json_is_tolerated_but_other_text_is_not(): void
    {
        $this->assertSame('valid', AnalysisContract::parse("```json\n".$this->reply()."\n```", self::REF, [4, 9])['status']);
        $this->rejected('Here is the result: '.$this->reply(), 'not_json');
        $this->rejected($this->reply().' Hope this helps!', 'not_json');
    }

    public function test_things_that_are_not_a_json_object_are_rejected(): void
    {
        foreach (['', 'pong', '[]', '[1,2]', '"text"', '42', 'null', '{broken'] as $bad) {
            $this->rejected($bad, 'not_json');
        }
    }

    public function test_a_reply_for_another_request_or_rubric_is_rejected(): void
    {
        $this->rejected($this->reply(['request_ref' => 'an_OTHER']), 'ref_mismatch');
        $this->rejected(json_encode(array_diff_key(json_decode($this->reply(), true), ['request_ref' => 1])), 'ref_mismatch');
        $this->rejected($this->reply(['rubric_version' => 'v2']), 'rubric_mismatch');
    }

    public function test_scores_must_be_whole_numbers_from_one_to_five_for_all_five_dimensions(): void
    {
        $good = ['clarity' => 4, 'context' => 3, 'specifics' => 2, 'refinement' => 4, 'verification' => 1];
        $this->rejected($this->reply(['scores' => array_diff_key($good, ['verification' => 1])]), 'bad_scores');
        foreach ([0, 6, -1, 3.5, '3', true, null, [3]] as $bad) {
            $this->rejected($this->reply(['scores' => ['clarity' => $bad] + $good]), 'bad_scores');
        }
        // PHP writes 3.0 as 3, so use raw JSON to send a score written with a decimal point.
        $this->rejected(str_replace('"clarity":4', '"clarity":4.0', $this->reply()), 'bad_scores');
        $this->rejected($this->reply(['scores' => [4, 3, 2, 4, 1]]), 'bad_scores');
        $this->rejected($this->reply(['scores' => 'high']), 'bad_scores');
        foreach ([1, 5] as $edge) {
            $this->assertSame('valid', AnalysisContract::parse($this->reply(['scores' => array_fill_keys(AnalysisContract::DIMENSIONS, $edge)]), self::REF, [4, 9])['status']);
        }
    }

    public function test_topics_must_come_from_the_list_that_was_sent(): void
    {
        $this->rejected($this->reply(['topic_ids' => []]), 'bad_topics');
        $this->rejected($this->reply(['topic_ids' => [4, 9, 4]]), 'bad_topics');
        $this->rejected($this->reply(['topic_ids' => [4, 9, 1, 2]]), 'bad_topics');
        $this->rejected($this->reply(['topic_ids' => [777]]), 'bad_topics');
        $this->rejected($this->reply(['topic_ids' => ['4']]), 'bad_topics');
        $this->rejected($this->reply(['topic_ids' => 4]), 'bad_topics');
        $this->rejected($this->reply(['topic_ids' => ['a' => 4]]), 'bad_topics');
        $this->assertSame([9, 4], AnalysisContract::parse($this->reply(['topic_ids' => [9, 4]]), self::REF, [4, 9])['topic_ids']);
    }

    public function test_the_label_and_tip_must_be_short_non_empty_text(): void
    {
        $this->rejected($this->reply(['task_label' => '']), 'bad_label');
        $this->rejected($this->reply(['task_label' => '   ']), 'bad_label');
        $this->rejected($this->reply(['task_label' => str_repeat('a', 81)]), 'bad_label');
        $this->rejected($this->reply(['task_label' => 12]), 'bad_label');
        $this->rejected($this->reply(['tip' => '']), 'bad_tip');
        $this->rejected($this->reply(['tip' => str_repeat('b', 201)]), 'bad_tip');
        $this->rejected($this->reply(['tip' => ['one', 'two']]), 'bad_tip');

        $r = AnalysisContract::parse($this->reply(['task_label' => "  Draft   email\n for client ", 'tip' => str_repeat('b', 200)]), self::REF, [4, 9]);
        $this->assertSame('valid', $r['status']);
        $this->assertSame('Draft email for client', $r['task_label']);
        $this->assertSame('valid', AnalysisContract::parse($this->reply(['task_label' => str_repeat('a', 80)]), self::REF, [4, 9])['status']);
    }

    public function test_an_unassessable_reply_is_a_result_but_only_with_a_known_reason(): void
    {
        foreach (AnalysisContract::UNASSESSABLE as $detail) {
            $r = AnalysisContract::parse(json_encode(['request_ref' => self::REF, 'rubric_version' => 'v1', 'error' => 'unassessable', 'detail' => $detail]), self::REF, [4]);
            $this->assertSame(['status' => 'unassessable', 'detail' => $detail], $r);
        }
        $this->rejected(json_encode(['request_ref' => self::REF, 'rubric_version' => 'v1', 'error' => 'unassessable', 'detail' => 'because']), 'bad_error');
        $this->rejected(json_encode(['request_ref' => self::REF, 'rubric_version' => 'v1', 'error' => 'I cannot do that']), 'bad_error');
        // An error next to scores is still an error: no scores are taken from it.
        $this->rejected($this->reply(['error' => 'unassessable']), 'bad_error');
    }

    public function test_extra_keys_are_ignored_and_never_returned(): void
    {
        $r = AnalysisContract::parse($this->reply(['average' => 5.0, 'overall' => 5, 'reasons' => ['clarity' => 'x'], 'note' => 'hi']), self::REF, [4, 9]);

        $this->assertSame(['status', 'task_label', 'topic_ids', 'scores', 'tip'], array_keys($r));
    }

    // ---- what gets stored ------------------------------------------------------------------------

    public function test_the_average_is_computed_here_from_all_five_scores(): void
    {
        $s = AnalysisContract::summarize(['clarity' => 4, 'context' => 3, 'specifics' => 2, 'refinement' => 4, 'verification' => 1], 3);

        $this->assertSame(5, $s['dimensions_scored']);
        $this->assertSame(2.8, $s['average']);
        $this->assertSame(4, $s['scores']['refinement']);
    }

    public function test_a_single_employee_message_leaves_refinement_out_of_the_average(): void
    {
        $s = AnalysisContract::summarize(['clarity' => 4, 'context' => 3, 'specifics' => 2, 'refinement' => 1, 'verification' => 1], 1);

        $this->assertNull($s['scores']['refinement']);
        $this->assertSame(4, $s['dimensions_scored']);
        $this->assertSame(2.5, $s['average']); // (4+3+2+1)/4, not (4+3+2+1+1)/5
        $this->assertSame(4, $s['scores']['clarity']);
    }

    public function test_two_employee_messages_are_enough_for_refinement_to_count(): void
    {
        $s = AnalysisContract::summarize(['clarity' => 5, 'context' => 5, 'specifics' => 5, 'refinement' => 2, 'verification' => 3], 2);

        $this->assertSame(5, $s['dimensions_scored']);
        $this->assertSame(4.0, $s['average']);
    }

    public function test_the_version_hash_changes_when_a_message_is_added_edited_or_replaced(): void
    {
        $base = [['id' => 1, 'role' => 'user', 'content' => 'hi'], ['id' => 2, 'role' => 'assistant', 'content' => 'hello']];
        $hash = AnalysisContract::versionHash($base);

        $this->assertSame($hash, AnalysisContract::versionHash($base));
        $this->assertNotSame($hash, AnalysisContract::versionHash([...$base, ['id' => 3, 'role' => 'user', 'content' => 'more']]));
        $this->assertNotSame($hash, AnalysisContract::versionHash([$base[0], ['id' => 2, 'role' => 'assistant', 'content' => 'hello!']]));
        $this->assertNotSame($hash, AnalysisContract::versionHash([$base[0], ['id' => 5, 'role' => 'assistant', 'content' => 'hello']]));
        $this->assertSame(64, strlen($hash));
    }

    // ---- the request -----------------------------------------------------------------------------

    public function test_the_request_has_the_agreed_shape_and_never_carries_file_contents(): void
    {
        $json = AnalysisContract::buildRequest(self::REF, [
            ['role' => 'user', 'content' => 'Summarize this', 'files' => [['name' => 'q3.xlsx', 'type' => 'xlsx', 'extracted_text' => 'SECRET CELL VALUES']]],
            ['role' => 'assistant', 'content' => 'Here is a summary'],
        ], $this->topics);
        $req = json_decode($json, true);

        $this->assertSame(['task', 'request_ref', 'rubric_version', 'include_reasons', 'topics', 'conversation'], array_keys($req));
        $this->assertSame(['analyze_conversation', self::REF, 'v1', false], [$req['task'], $req['request_ref'], $req['rubric_version'], $req['include_reasons']]);
        $this->assertSame($this->topics, $req['topics']);
        $this->assertSame([['n' => 1, 'role' => 'employee', 'text' => 'Summarize this', 'files' => [['name' => 'q3.xlsx', 'type' => 'xlsx']]], ['n' => 2, 'role' => 'assistant', 'text' => 'Here is a summary']], $req['conversation']['messages']);
        $this->assertStringNotContainsString('SECRET', $json);
        $this->assertArrayNotHasKey('omitted_messages', $req['conversation']);
    }

    public function test_long_messages_are_cut_and_marked(): void
    {
        $req = json_decode(AnalysisContract::buildRequest(self::REF, [
            ['role' => 'user', 'content' => str_repeat('é', 5000)],
            ['role' => 'assistant', 'content' => str_repeat('b', 2000)],
        ], $this->topics), true);
        [$employee, $assistant] = $req['conversation']['messages'];

        $this->assertSame(4000, mb_strlen($employee['text']));
        $this->assertTrue($employee['truncated']);
        $this->assertSame(1500, mb_strlen($assistant['text']));
        $this->assertTrue($assistant['truncated']);
    }

    public function test_a_huge_conversation_is_shortened_further_and_then_trimmed_in_the_middle(): void
    {
        $messages = [];
        for ($i = 1; $i <= 40; $i++) {
            $messages[] = ['role' => $i % 2 ? 'user' : 'assistant', 'content' => "message {$i} ".str_repeat('x', 3000)];
        }

        $json = AnalysisContract::buildRequest(self::REF, $messages, $this->topics);
        $req = json_decode($json, true);
        $ns = array_column($req['conversation']['messages'], 'n');

        $this->assertLessThanOrEqual(AnalysisContract::MAX_REQUEST_CHARS, strlen($json));
        $this->assertSame(28, $req['conversation']['omitted_messages']);
        $this->assertSame([1, 2, 31, 32, 33, 34, 35, 36, 37, 38, 39, 40], $ns);
        $this->assertSame(1500, mb_strlen($req['conversation']['messages'][0]['text']));
    }
}
