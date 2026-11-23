<?php

namespace App\Http\Controllers;

use App\Http\Responses\Api;
use App\Reports\AnalysisReports;
use App\Reports\ReportFilters;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Prompt-quality and topic results. Admins read the company view; everyone reads only their own. */
class AnalysisReportController extends Controller
{
    /** Admin and Super Admin (route guard). */
    public function report(Request $request, AnalysisReports $reports): JsonResponse
    {
        $f = ReportFilters::fromRequest($request);
        $data = $reports->data($f);
        Audit::log('reports.analysis.viewed', $request->user(), metadata: $f->meta());

        return Api::ok($data, $f->meta());
    }

    /** Any signed-in person: the server uses the signed-in person, never an ID from the request. */
    public function mine(Request $request, AnalysisReports $reports): JsonResponse
    {
        return Api::ok($reports->mine($request->user()));
    }
}
