<?php

namespace App\Reports;

use App\Support\BusinessDay;
use Illuminate\Support\Facades\DB;

final class ReportQueries
{
    public static function usage(ReportFilters $f): mixed
    {
        return $f->scope(DB::table('usage_records')->where('purpose', 'chat')->whereBetween('work_date', [$f->from, $f->to]), 'usage_records.user_id');
    }

    public static function messages(ReportFilters $f, ?string $role = null): mixed
    {
        $q = $f->scope(DB::table('messages as m')->join('conversations as c', 'c.id', '=', 'm.conversation_id'), 'c.user_id')
            ->whereNull('m.superseded_at')->where('m.created_at', '>=', BusinessDay::startUtc($f->from))->where('m.created_at', '<', BusinessDay::endUtc($f->to));

        return $role ? $q->where('m.role', $role) : $q;
    }

    public static function users(ReportFilters $f): mixed
    {
        return $f->scope(DB::table('users as u'), 'u.id');
    }

    public static function conversations(ReportFilters $f): mixed
    {
        return $f->scope(DB::table('conversations as c'), 'c.user_id')->whereExists(function ($q) use ($f) {
            $q->selectRaw('1')->from('messages as m')->whereColumn('m.conversation_id', 'c.id')->whereNull('m.superseded_at')
                ->where('m.created_at', '>=', BusinessDay::startUtc($f->from))->where('m.created_at', '<', BusinessDay::endUtc($f->to));
        });
    }

    public static function day(string $column = 'm.created_at'): string
    {
        return "DATE(CONVERT_TZ({$column}, '+00:00', '+08:00'))";
    }
}
