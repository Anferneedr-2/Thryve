<?php

namespace App\Reports;

use App\Ai\SensitiveData;
use App\Support\BusinessDay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class InspectionReports
{
    public function conversations(ReportFilters $f, Request $request): mixed
    {
        $v = $request->validate(['state' => ['nullable', 'in:all,active,archived,deleted'], 'q' => ['nullable', 'string', 'max:100'], 'agent_id' => ['nullable', 'integer']]);
        $q = ReportQueries::conversations($f)->join('users as u', 'u.id', '=', 'c.user_id')->leftJoin('agents as a', 'a.id', '=', 'c.agent_id')
            ->select('c.id', 'c.title', 'c.user_id', 'u.name', 'c.agent_id', 'a.name as agent', 'c.created_at', 'c.last_message_at', 'c.archived_at', 'c.deleted_at');
        $state = $v['state'] ?? 'all';
        if ($state === 'active') {
            $q->whereNull('c.archived_at')->whereNull('c.deleted_at');
        } elseif ($state === 'archived') {
            $q->whereNotNull('c.archived_at')->whereNull('c.deleted_at');
        } elseif ($state === 'deleted') {
            $q->whereNotNull('c.deleted_at');
        }
        if (filled($v['q'] ?? null)) {
            $q->where('c.title', 'like', '%'.addcslashes($v['q'], '%_\\').'%');
        }
        if (isset($v['agent_id'])) {
            $q->where('c.agent_id', $v['agent_id']);
        }

        return $q->orderByDesc('c.last_message_at')->orderByDesc('c.id');
    }

    public function audit(ReportFilters $f, Request $request): mixed
    {
        $v = $request->validate(['action' => ['nullable', 'string', 'max:100'], 'q' => ['nullable', 'string', 'max:100'], 'target_type' => ['nullable', 'string', 'max:255'], 'target_id' => ['nullable', 'integer', 'min:1']]);
        $q = $f->scope(DB::table('audit_entries'), 'actor_id')->where('created_at', '>=', BusinessDay::startUtc($f->from))->where('created_at', '<', BusinessDay::endUtc($f->to));
        if (filled($v['action'] ?? null)) {
            $q->where('action', $v['action']);
        }
        if (filled($v['target_type'] ?? null)) {
            $q->where('target_type', $v['target_type']);
        }
        if (isset($v['target_id'])) {
            $q->where('target_id', $v['target_id']);
        }
        if (filled($v['q'] ?? null)) {
            $like = '%'.addcslashes($v['q'], '%_\\').'%';
            $q->where(fn ($w) => $w->where('action', 'like', $like)->orWhere('actor_username', 'like', $like)->orWhere('target_type', 'like', $like));
        }

        return $q->select('id', 'actor_id', 'actor_username', 'action', 'target_type', 'target_id', 'metadata', 'created_at')->orderByDesc('id');
    }

    public function presentAudit(object $row): array
    {
        $meta = json_decode($row->metadata ?? 'null', true);

        return ['id' => $row->id, 'actor_id' => $row->actor_id, 'actor' => $row->actor_username, 'action' => $row->action, 'target_type' => $row->target_type, 'target_id' => $row->target_id, 'at' => $row->created_at,
            'metadata' => $this->scrub($meta)];
    }

    private function scrub(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 4) {
            return '[limited]';
        }
        if (is_array($value)) {
            $result = [];
            foreach (array_slice($value, 0, 30, true) as $key => $v) {
                if (preg_match('/password|secret|token|key|authorization/i', (string) $key)) {
                    continue;
                }
                $result[$key] = $this->scrub($v, $depth + 1);
            }

            return $result;
        }
        if (is_string($value)) {
            if (SensitiveData::find($value) || preg_match('/password\s*[:=]|bearer\s|private key/i', $value)) {
                return '[redacted]';
            }

            return mb_substr($value, 0, 500);
        }

        return $value;
    }
}
