<?php

namespace App\Reports;

use App\Http\Controllers\AttendanceController;
use App\Models\AttendanceDay;

final class AttendanceReports
{
    public function query(ReportFilters $f): mixed
    {
        return $f->scope(AttendanceDay::with('user:id,name,username')->whereBetween('work_date', [$f->from, $f->to]))->orderByDesc('work_date')->orderBy('user_id');
    }

    public function present(AttendanceDay $day): array
    {
        return ['name' => $day->user->name, 'username' => $day->user->username] + AttendanceController::present($day);
    }
}
