<?php

namespace App\Http\Controllers;

use App\Http\Responses\Api;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use App\Reports\AdoptionReports;
use App\Reports\AttendanceReports;
use App\Reports\InspectionReports;
use App\Reports\OperationalReports;
use App\Reports\QualityReports;
use App\Reports\ReportExporter;
use App\Reports\ReportFilters;
use App\Reports\UsageReports;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function attendance(Request $request, AttendanceReports $reports): JsonResponse
    {
        $f = ReportFilters::fromRequest($request);
        $p = $reports->query($f)->paginate((int) $request->input('per_page', 20));
        Audit::log('reports.attendance.viewed', $request->user(), metadata: $f->meta());

        return Api::ok($p->getCollection()->map(fn ($day) => $reports->present($day)), $f->meta() + ['page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()]);
    }

    public function usage(Request $request, UsageReports $reports): JsonResponse
    {
        $f = ReportFilters::fromRequest($request);
        $data = $reports->data($f);
        Audit::log('reports.usage.viewed', $request->user(), metadata: $f->meta());

        return Api::ok($data, $f->meta());
    }

    public function quality(Request $request, QualityReports $reports): JsonResponse
    {
        $f = ReportFilters::fromRequest($request);
        $data = $reports->data($f);
        Audit::log('reports.quality.viewed', $request->user(), metadata: $f->meta());

        return Api::ok($data, $f->meta());
    }

    public function adoption(Request $request, AdoptionReports $reports): JsonResponse
    {
        $f = ReportFilters::fromRequest($request);
        $request->validate(['agent_page' => ['nullable', 'integer', 'min:1']]);
        $data = $reports->data($f, (int) $request->input('page', 1), (int) $request->input('agent_page', 1));
        Audit::log('reports.adoption.viewed', $request->user(), metadata: $f->meta());

        return Api::ok($data, $f->meta());
    }

    public function conversations(Request $request, InspectionReports $reports): JsonResponse
    {
        $f = ReportFilters::fromRequest($request);
        $p = $reports->conversations($f, $request)->paginate((int) $request->input('per_page', 20));
        Audit::log('reports.conversations.viewed', $request->user(), metadata: $f->meta());

        return Api::ok($p->items(), $f->meta() + ['page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()]);
    }

    public function inspect(Request $request, int $conversation): JsonResponse
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'history' => ['nullable', 'boolean']]);
        $c = Conversation::findOrFail($conversation);
        $q = $c->messages()->orderBy('id');
        if (! $request->boolean('history')) {
            $q->whereNull('superseded_at');
        }
        $p = $q->paginate(30);
        $files = Attachment::where('conversation_id', $c->id)->where('user_id', $c->user_id)->whereIn('message_id', $p->pluck('id'))->get()->groupBy('message_id');
        $messages = $p->getCollection()->map(fn ($m) => ['id' => $m->id, 'role' => $m->role, 'content' => $m->content, 'rating' => $m->rating, 'created_at' => $m->created_at->toIso8601String(), 'superseded_at' => $m->superseded_at?->toIso8601String(),
            'attachments' => collect($files[$m->id] ?? [])->map(fn ($a) => AttachmentController::present($a))->values()->all()])->values();
        Audit::log('reports.conversation.inspected', $request->user(), $c, ['page' => $p->currentPage(), 'history' => $request->boolean('history')]);

        return Api::ok(['id' => $c->id, 'title' => $c->title, 'user_id' => $c->user_id, 'archived' => (bool) $c->archived_at, 'deleted' => (bool) $c->deleted_at, 'messages' => $messages], ['page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()]);
    }

    public function download(Request $request, int $conversation, int $attachment): StreamedResponse
    {
        $c = Conversation::findOrFail($conversation);
        $a = Attachment::where('conversation_id', $c->id)->where('user_id', $c->user_id)->findOrFail($attachment);
        abort_unless(Storage::disk('private')->exists($a->storage_key), 404);
        Audit::log('reports.file.downloaded', $request->user(), $a, ['conversation_id' => $c->id]);

        return Storage::disk('private')->download($a->storage_key, $a->original_name, ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function audit(Request $request, InspectionReports $reports): JsonResponse
    {
        $f = ReportFilters::fromRequest($request);
        $p = $reports->audit($f, $request)->paginate((int) $request->input('per_page', 20));
        $data = $p->getCollection()->map(fn ($r) => $reports->presentAudit($r))->values();
        Audit::log('reports.audit.viewed', $request->user(), metadata: $f->meta());

        return Api::ok($data, $f->meta() + ['page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()]);
    }

    public function export(Request $request, ReportExporter $exporter): Response
    {
        $f = ReportFilters::fromRequest($request);
        $v = $request->validate(['report' => ['required', 'in:overview,usage,quality,adoption,people,attendance,conversations,audit,attempts,agents,feedback'], 'format' => ['required', 'in:csv,xlsx,pdf']]);
        $file = $exporter->build($v['report'], $v['format'], $f, $request);
        Audit::log('reports.exported', $request->user(), metadata: $f->meta() + ['report' => $v['report'], 'format' => $v['format'], 'rows' => $file['rows']]);

        return response($file['bytes'], 200, ['Content-Type' => $file['mime'], 'Content-Disposition' => 'attachment; filename="thryve-'.$v['report'].'-'.$f->from.'-'.$f->to.'.'.$v['format'].'"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function overview(Request $request, OperationalReports $reports): JsonResponse
    {
        $filters = ReportFilters::fromRequest($request);
        $data = $reports->overview($filters);
        Audit::log('reports.overview.viewed', $request->user(), metadata: $filters->meta());

        return Api::ok($data, $filters->meta());
    }

    public function people(Request $request, OperationalReports $reports): JsonResponse
    {
        $filters = ReportFilters::fromRequest($request);
        $page = $reports->people($filters, (int) $request->input('per_page', 10));
        Audit::log('reports.people.viewed', $request->user(), metadata: $filters->meta());

        return Api::ok($page->getCollection()->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'username' => $r->username, 'is_active' => (bool) $r->is_active,
            'tokens' => (int) $r->tokens, 'requests' => (int) $r->requests, 'failed' => (int) $r->failed, 'active_seconds' => (int) $r->active_seconds, 'idle_seconds' => (int) $r->idle_seconds, 'attendance_days' => (int) $r->attendance_days])->values(),
            $filters->meta() + ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }

    public function filters(Request $request): JsonResponse
    {
        $v = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'between:1,100'], 'selected_id' => ['nullable', 'integer']]);
        $q = User::query()->select('id', 'name', 'username', 'is_active')->orderBy('name')->orderBy('id');
        if (filled($v['q'] ?? null)) {
            $like = '%'.addcslashes($v['q'], '%_\\').'%';
            $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('username', 'like', $like));
        }
        $page = $q->paginate($v['per_page'] ?? 20);
        $selected = isset($v['selected_id']) ? User::select('id', 'name', 'username', 'is_active')->find($v['selected_id']) : null;

        return Api::ok(['users' => $page->items(), 'selected_user' => $selected, 'departments' => Department::orderBy('name')->get(['id', 'name'])],
            ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }
}
