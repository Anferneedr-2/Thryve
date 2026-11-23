<?php

namespace App\Analysis;

/**
 * The contract between Thryve and the Report AI agent (see Report_AI_Agent_Specification.md). Pure functions, no
 * database: build the one request, check the one reply, and turn valid scores into the stored numbers.
 *
 * Nothing the agent says is trusted. A reply that is not exactly what the contract allows is rejected, and a rejected
 * reply is never stored as scores. The average is always computed here, never taken from the agent.
 */
final class AnalysisContract
{
    public const RUBRIC_VERSION = 'v1';

    /** @var list<string> */
    public const DIMENSIONS = ['clarity', 'context', 'specifics', 'refinement', 'verification'];

    public const MAX_LABEL = 80;

    public const MAX_TIP = 200;

    public const MAX_TOPICS = 3;

    /** What the agent may say when it cannot assess a conversation. */
    public const UNASSESSABLE = ['no_employee_message', 'no_assessable_content'];

    /** Request size limits: characters per message, then for the whole request. Each step is used only if the one before is too big. */
    private const TIERS = [
        ['employee' => 4000, 'assistant' => 1500],
        ['employee' => 1500, 'assistant' => 400],
    ];

    public const MAX_REQUEST_CHARS = 24000;

    private const KEEP_FIRST = 2;

    private const KEEP_LAST = 10;

    /**
     * A fingerprint of the current messages. When it changes (a new message, an edit, a regenerate), the old result no
     * longer describes the conversation.
     *
     * @param  iterable<array{id: int, role: string, content: string}>  $messages  current messages, oldest first
     */
    public static function versionHash(iterable $messages): string
    {
        $parts = [];
        foreach ($messages as $m) {
            $parts[] = [(int) $m['id'], (string) $m['role'], sha1((string) $m['content'])];
        }

        return hash('sha256', json_encode($parts, JSON_UNESCAPED_UNICODE));
    }

    /**
     * The one request sent to the agent, as the JSON text of a single user message.
     *
     * @param  list<array{role: string, content: string, files?: list<array{name: string, type: string}>}>  $messages  current messages, oldest first (role is user or assistant)
     * @param  list<array{id: int, name: string, description: string}>  $topics  the active topics, in priority order
     */
    public static function buildRequest(string $requestRef, array $messages, array $topics): string
    {
        $total = count($messages);
        foreach (self::TIERS as $tier) {
            $payload = self::payload($requestRef, $messages, $topics, $tier, false);
            if (strlen(json_encode($payload, JSON_UNESCAPED_UNICODE)) <= self::MAX_REQUEST_CHARS) {
                return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        // Still too big: keep the start and the end of the conversation and say how many messages were left out.
        $payload = self::payload($requestRef, $messages, $topics, self::TIERS[array_key_last(self::TIERS)], $total > self::KEEP_FIRST + self::KEEP_LAST);

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed> */
    private static function payload(string $requestRef, array $messages, array $topics, array $limits, bool $trim): array
    {
        $rows = [];
        foreach (array_values($messages) as $i => $m) {
            $employee = $m['role'] === 'user';
            $limit = $employee ? $limits['employee'] : $limits['assistant'];
            $text = (string) $m['content'];
            $cut = mb_strlen($text) > $limit;
            $row = ['n' => $i + 1, 'role' => $employee ? 'employee' : 'assistant', 'text' => $cut ? mb_substr($text, 0, $limit) : $text];
            if ($cut) {
                $row['truncated'] = true;
            }
            if ($employee && ! empty($m['files'])) {
                $row['files'] = array_map(fn ($f) => ['name' => (string) $f['name'], 'type' => (string) $f['type']], array_values($m['files']));
            }
            $rows[] = $row;
        }
        $omitted = 0;
        if ($trim) {
            $omitted = count($rows) - self::KEEP_FIRST - self::KEEP_LAST;
            $rows = array_merge(array_slice($rows, 0, self::KEEP_FIRST), array_slice($rows, -self::KEEP_LAST));
        }
        $conversation = ['messages' => $rows];
        if ($omitted > 0) {
            $conversation['omitted_messages'] = $omitted;
        }

        return [
            'task' => 'analyze_conversation',
            'request_ref' => $requestRef,
            'rubric_version' => self::RUBRIC_VERSION,
            'include_reasons' => false,
            'topics' => array_values($topics),
            'conversation' => $conversation,
        ];
    }

    /**
     * Check the agent's reply. Returns a valid result or an unassessable result; anything else throws.
     *
     * @param  list<int>  $allowedTopicIds  the topic IDs that were sent in the request
     * @return array{status: 'valid', task_label: string, topic_ids: list<int>, scores: array<string, int>, tip: string}|array{status: 'unassessable', detail: string}
     *
     * @throws AnalysisInvalid
     */
    public static function parse(string $reply, string $requestRef, array $allowedTopicIds): array
    {
        $text = trim($reply);
        // The profile must reply with bare JSON. A single code fence around it is tolerated; the content is still checked strictly.
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $text, $m)) {
            $text = $m[1];
        }
        $data = json_decode($text, true, 8);
        if (! is_array($data) || array_is_list($data)) {
            throw new AnalysisInvalid('not_json');
        }
        if (($data['request_ref'] ?? null) !== $requestRef) {
            throw new AnalysisInvalid('ref_mismatch');
        }
        if (($data['rubric_version'] ?? null) !== self::RUBRIC_VERSION) {
            throw new AnalysisInvalid('rubric_mismatch');
        }
        if (array_key_exists('error', $data)) {
            if ($data['error'] === 'unassessable' && in_array($data['detail'] ?? null, self::UNASSESSABLE, true)) {
                return ['status' => 'unassessable', 'detail' => $data['detail']];
            }
            throw new AnalysisInvalid('bad_error');
        }

        $scores = $data['scores'] ?? null;
        if (! is_array($scores) || array_is_list($scores)) {
            throw new AnalysisInvalid('bad_scores');
        }
        $clean = [];
        foreach (self::DIMENSIONS as $d) {
            $v = $scores[$d] ?? null;
            if (! is_int($v) || $v < 1 || $v > 5) {
                throw new AnalysisInvalid('bad_scores');
            }
            $clean[$d] = $v;
        }

        $topics = $data['topic_ids'] ?? null;
        if (! is_array($topics) || ! array_is_list($topics) || count($topics) < 1 || count($topics) > self::MAX_TOPICS
            || count($topics) !== count(array_unique($topics)) || array_filter($topics, fn ($t) => ! is_int($t) || ! in_array($t, $allowedTopicIds, true))) {
            throw new AnalysisInvalid('bad_topics');
        }

        $label = self::shortText($data['task_label'] ?? null, self::MAX_LABEL);
        if ($label === null) {
            throw new AnalysisInvalid('bad_label');
        }
        $tip = self::shortText($data['tip'] ?? null, self::MAX_TIP);
        if ($tip === null) {
            throw new AnalysisInvalid('bad_tip');
        }

        return ['status' => 'valid', 'task_label' => $label, 'topic_ids' => array_values($topics), 'scores' => $clean, 'tip' => $tip];
    }

    private static function shortText(mixed $v, int $max): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $v = trim(preg_replace('/\s+/u', ' ', $v));

        return $v === '' || mb_strlen($v) > $max ? null : $v;
    }

    /**
     * Turn valid scores into what is stored. Refinement is what someone does after the first answer, so with a single
     * employee message it does not apply: it is dropped (stored empty) and the average uses the other four.
     *
     * @param  array<string, int>  $scores
     * @return array{scores: array<string, int|null>, dimensions_scored: int, average: float}
     */
    public static function summarize(array $scores, int $employeeMessages): array
    {
        if ($employeeMessages < 2) {
            $scores['refinement'] = null;
        }
        $counted = array_filter($scores, fn ($v) => $v !== null);

        return [
            'scores' => $scores,
            'dimensions_scored' => count($counted),
            'average' => round(array_sum($counted) / count($counted), 2),
        ];
    }
}
