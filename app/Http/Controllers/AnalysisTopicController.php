<?php

namespace App\Http\Controllers;

use App\Analysis\AnalysisContract;
use App\Http\Responses\Api;
use App\Models\AnalysisTopic;
use App\Models\ConversationAnalysis;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The managed topic list the report agent chooses from. Admin and Super Admin (route guard). Topics are deactivated,
 * never deleted, so results already stored keep their topic. The fallback topic keeps its name and stays active,
 * because the agent is told to use it when nothing else fits.
 */
class AnalysisTopicController extends Controller
{
    public function index(): JsonResponse
    {
        $uses = [];
        ConversationAnalysis::valid()->where('rubric_version', AnalysisContract::RUBRIC_VERSION)->pluck('topic_ids')->each(function ($ids) use (&$uses) {
            foreach ($ids ?? [] as $id) {
                $uses[$id] = ($uses[$id] ?? 0) + 1;
            }
        });

        return Api::ok(AnalysisTopic::ordered()->get()->map(fn ($t) => $this->present($t, $uses[$t->id] ?? 0)));
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate($this->rules(), $this->messages());
        $topic = AnalysisTopic::create(['name' => trim($d['name']), 'description' => trim($d['description']), 'priority' => $d['priority'] ?? (int) AnalysisTopic::max('priority') + 10, 'is_active' => true, 'is_fallback' => false]);
        Audit::log('analysis.topic_created', $request->user(), null, ['topic_id' => $topic->id, 'name' => $topic->name]);

        return Api::ok($this->present($topic, 0), status: 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $topic = AnalysisTopic::findOrFail($id);
        $d = $request->validate(array_map(fn ($r) => array_merge(['sometimes'], $r), $this->rules($topic->id)) + ['is_active' => ['sometimes', 'boolean']], $this->messages());
        if ($topic->is_fallback && isset($d['name']) && trim($d['name']) !== $topic->name) {
            return Api::error('validation_failed', 'This topic is the fallback and keeps its name.', 422, ['name' => ['The fallback topic keeps its name.']]);
        }
        if ($topic->is_fallback && ($d['is_active'] ?? true) === false) {
            return Api::error('validation_failed', 'The fallback topic must stay active.', 422, ['is_active' => ['The fallback topic must stay active.']]);
        }
        foreach (['name', 'description'] as $key) {
            if (isset($d[$key])) {
                $d[$key] = trim($d[$key]);
            }
        }
        $topic->update($d);
        Audit::log('analysis.topic_updated', $request->user(), null, ['topic_id' => $topic->id, 'fields' => array_keys($d)]);

        return Api::ok($this->present($topic->fresh(), 0));
    }

    private function rules(?int $ignore = null): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:80', Rule::unique('analysis_topics', 'name')->ignore($ignore)],
            'description' => ['required', 'string', 'min:1', 'max:200'],
            'priority' => ['nullable', 'integer', 'between:0,65535'],
        ];
    }

    private function messages(): array
    {
        return [
            'name.required' => 'Enter a name for the topic.', 'name.max' => 'Use 80 characters or fewer.', 'name.unique' => 'A topic with this name already exists.',
            'description.required' => 'Describe the topic in a few words. The report agent reads this to decide when it fits.', 'description.max' => 'Use 200 characters or fewer.',
        ];
    }

    private function present(AnalysisTopic $t, int $uses): array
    {
        return ['id' => $t->id, 'name' => $t->name, 'description' => $t->description, 'priority' => $t->priority, 'is_active' => $t->is_active, 'is_fallback' => $t->is_fallback, 'conversations' => $uses];
    }
}
