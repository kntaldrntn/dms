<?php

namespace App\Http\Controllers;

use App\Http\Requests\DashboardFilterRequest;
use App\Models\Document;
use App\Services\DashboardReportService;
use App\Services\ReportSpreadsheetService;
use Carbon\Carbon;
use Inertia\Inertia;

/** The printable list behind a dashboard card, opened in its own tab like the old DTS report, or as an Excel file. */
class DashboardReportController extends Controller
{
    public function __invoke(DashboardFilterRequest $request, DashboardReportService $reports, ReportSpreadsheetService $spreadsheets)
    {
        $report = $request->string('report')->toString();
        $category = $request->category('transaction_category');
        $pipeline = $request->string('pipeline_status')->toString();
        // From the dashboard's by-office table: the same list for one Primary office.
        $office = $request->office();
        $today = Carbon::now('Asia/Manila');
        $with = ['documentType', 'department'];
        $scopeLabel = $reports->seesAllDepartments($request->user()) ? ' (All Departments)' : ' (Your Office)';

        if (array_key_exists($report, DashboardReportService::ALERT_REPORTS)) {
            // The same office-scoped lists the cards count; they describe today, not the selected month.
            $documents = $reports->alertReport($report, $reports->openDocuments($request->user()))
                ->when($office, fn ($documents) => $documents->where('department_id', $office->id))
                ->sortByDesc('created_at')->values();
            $title = DashboardReportService::ALERT_REPORTS[$report].($office ? " ({$office->code})" : $scopeLabel);
            $dateRange = $report === 'due_tomorrow'
                ? 'Due '.Carbon::tomorrow('Asia/Manila')->format('m/d/Y')
                : 'As of '.$today->format('m/d/Y');
        } elseif ($category && array_key_exists($pipeline, DashboardReportService::PIPELINES)) {
            [$start, $end] = $request->period();
            $documents = $reports->pipelineQuery($start, $end, $category, $pipeline, $request->user())
                ->when($office, fn ($documents) => $documents->where('department_id', $office->id))
                ->with($with)->latest()->get();
            $title = DashboardReportService::PIPELINES[$pipeline].' '.$category.' Documents'
                .($office ? " ({$office->code})" : ($reports->seesAllDepartments($request->user()) ? '' : ' (Your Office)'));
            $dateRange = $start->format('m/d/Y').' to '.$end->format('m/d/Y');
        } else {
            abort(404);
        }

        $rows = $documents->map(function (Document $document) use ($reports, $request) {
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
                // No contact number, email or gender (user, 2026-11-05): reports get printed and passed around.
                'source_name' => $hidden ? null : $document->source_name,
                'subject_matter' => $hidden ? 'Confidential document' : $document->subject_matter,
                'department' => $document->department?->code,
                'total_days' => round($reports->totalDays($document), 2),
            ];
        })->all();

        if ($request->wantsSpreadsheet()) {
            return $spreadsheets->download($title, $dateRange, $rows, $request->user()->name, $today->format('M d, Y'));
        }

        return Inertia::render('dashboard/Report', [
            'title' => $title,
            'dateRange' => $dateRange,
            'rows' => $rows,
            'generatedBy' => $request->user()->name,
            'generatedOn' => $today->format('M d, Y'),
            'downloadUrl' => $request->fullUrlWithQuery(['format' => 'xlsx']),
        ]);
    }
}
