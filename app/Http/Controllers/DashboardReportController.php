<?php

namespace App\Http\Controllers;

use App\Http\Requests\DashboardFilterRequest;
use App\Models\Document;
use App\Services\DashboardReportService;
use Carbon\Carbon;
use Inertia\Inertia;

/** The printable list behind a dashboard card, opened in its own tab like the old DTS report. */
class DashboardReportController extends Controller
{
    public function __invoke(DashboardFilterRequest $request, DashboardReportService $reports)
    {
        $report = $request->string('report')->toString();
        $category = $request->category('transaction_category');
        $pipeline = $request->string('pipeline_status')->toString();
        $today = Carbon::now('Asia/Manila');
        $with = ['documentType', 'department'];

        if (array_key_exists($report, DashboardReportService::ALERT_REPORTS)) {
            // The same office-scoped lists the cards count; they describe today, not the selected month.
            $documents = $reports->alertReport($report, $reports->openDocuments($request->user()))->sortByDesc('created_at')->values();
            $title = DashboardReportService::ALERT_REPORTS[$report].($reports->seesAllDepartments($request->user()) ? ' (All Departments)' : ' (Your Office)');
            $dateRange = $report === 'due_tomorrow'
                ? 'Due '.Carbon::tomorrow('Asia/Manila')->format('m/d/Y')
                : 'As of '.$today->format('m/d/Y');
        } elseif ($category && array_key_exists($pipeline, DashboardReportService::PIPELINES)) {
            $year = $request->year();
            $month = $request->monthNumber();
            $documents = $reports->pipelineQuery($year, $month, $category, $pipeline, $request->user())->with($with)->latest()->get();
            $title = DashboardReportService::PIPELINES[$pipeline].' '.$category.' Documents'
                .($reports->seesAllDepartments($request->user()) ? '' : ' (Your Office)');
            $start = Carbon::create($year, $month, 1, 0, 0, 0, 'Asia/Manila');
            $dateRange = $start->format('m/d/Y').' to '.$start->copy()->endOfMonth()->format('m/d/Y');
        } else {
            abort(404);
        }

        return Inertia::render('dashboard/Report', [
            'title' => $title,
            'dateRange' => $dateRange,
            'rows' => $documents->map(function (Document $document) use ($reports, $request) {
                // Workstation reports count their office's confidential documents but never show their details.
                $hidden = $document->detailsHiddenFrom($request->user());

                return [
                    'id' => $document->id,
                    'details_hidden' => $hidden,
                    // created_at is real UTC; the report reads in Manila time.
                    'date' => $document->created_at->copy()->setTimezone('Asia/Manila')->format('M. d, Y'),
                    'barcode' => $document->barcode,
                    'document_code' => $document->documentType?->document_code,
                    'document_type' => $document->documentType?->document_type,
                    'source_type' => ['External' => 'EXT', 'Internal' => 'INT'][$document->source_type] ?? $document->source_type,
                    'source_name' => $hidden ? null : $document->source_name,
                    'gender' => $hidden ? null : $document->gender,
                    'contact_no' => $hidden ? null : $document->contact_no,
                    'email' => $hidden ? null : $document->email,
                    'subject_matter' => $hidden ? 'Confidential document' : $document->subject_matter,
                    'department' => $document->department?->code,
                    'total_days' => round($reports->totalDays($document), 2),
                ];
            })->all(),
            'generatedBy' => $request->user()->name,
            'generatedOn' => $today->format('M d, Y'),
        ]);
    }
}
