<?php

namespace App\Http\Controllers;

use App\Http\Requests\DashboardFilterRequest;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\TransactionType;
use App\Services\DashboardReportService;
use Carbon\Carbon;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke(DashboardFilterRequest $request, DashboardReportService $reports)
    {
        $selectedMonthName = $request->monthName();
        $year = $request->year();
        $monthNumber = $request->monthNumber();

        // Base Query: All active documents created in the selected month
        $monthQuery = $reports->monthQuery($year, $monthNumber);

        // ---------------------------------------------------------
        // 1. TOP SUMMARY CARDS
        // ---------------------------------------------------------
        $totalDocuments = (clone $monthQuery)->count();
        $externalClients = (clone $monthQuery)->where('source_type', 'External')->count();
        $internalClients = (clone $monthQuery)->where('source_type', 'Internal')->count();

        // ---------------------------------------------------------
        // 2. DELINQUENT / DUE TOMORROW / TO-DO
        // ---------------------------------------------------------
        // Oversight roles see every active department; everyone else sees only documents their office holds.
        $user = $request->user();
        $seesAllDepartments = $reports->seesAllDepartments($user);
        $openDocuments = $reports->openDocuments($user);

        // ---------------------------------------------------------
        // 3. E.O.D.B PIPELINE (On-Time, On-Going, Delayed)
        // ---------------------------------------------------------
        $transactionTypes = TransactionType::where('state', 1)->pluck('name', 'id');
        $pipelineData = array_fill_keys(DashboardReportService::PIPELINES, array_fill_keys(DashboardReportService::TRANSACTION_CATEGORIES, 0));
        $now = Carbon::now('Asia/Manila');

        foreach ($monthQuery->get() as $doc) {
            $bucket = DashboardReportService::category($transactionTypes[$doc->transaction_type_id] ?? 'Simple');

            if ($doc->status === 'Completed') {
                $stage = $doc->due_date && Carbon::parse($doc->completed_at)->greaterThan($doc->due_date) ? 'Delayed' : 'On-Time';
            } else {
                $stage = $doc->due_date && $now->greaterThan($doc->due_date) ? 'Delayed' : 'On-Going';
            }
            $pipelineData[$stage][$bucket]++;
        }

        $stageStyles = [
            'on_time' => ['status' => 'Healthy', 'statusColor' => 'emerald'],
            'on_going' => ['status' => 'In Progress', 'statusColor' => 'blue'],
            'delayed' => ['status' => 'Attention', 'statusColor' => 'orange'],
        ];
        $pipeline = collect(DashboardReportService::PIPELINES)->map(fn ($title, $key) => [
            'title' => $title, 'subtitle' => 'Documents', ...$stageStyles[$key],
            'rows' => collect(DashboardReportService::TRANSACTION_CATEGORIES)
                ->map(fn ($category) => ['label' => $category, 'value' => $pipelineData[$title][$category], 'pipeline' => $key])
                ->all(),
        ])->values()->all();

        // ---------------------------------------------------------
        // 4. AVERAGE PROCESSING TIMES (By Document Type)
        // ---------------------------------------------------------
        // Like the old DTS: one transaction category at a time, every document type of that category listed
        // (0 days when none completed), in a fixed registry order so the grid does not reshuffle month to month.
        $processingCategory = $request->category('processing_category', 'Simple');
        $categoryTransactionTypeIds = $reports->transactionTypeIds($processingCategory);

        $averageDaysByType = Document::where('state', 1)
            ->where('status', 'Completed')
            ->whereNotNull('completed_at')
            ->whereYear('completed_at', $year)
            ->whereMonth('completed_at', $monthNumber)
            ->whereIn('transaction_type_id', $categoryTransactionTypeIds)
            ->get(['id', 'document_type_id', 'created_at', 'completed_at'])
            ->groupBy('document_type_id')
            // At least 0.1 day so same-day documents still register.
            ->map(fn ($documents) => $documents->avg(fn (Document $document) => max($reports->totalDays($document), 0.1)));

        $processingTimes = DocumentType::where('state', 1)->orderBy('document_type')
            ->get(['id', 'document_type', 'default_transaction_type_id'])
            ->filter(fn (DocumentType $type) => $averageDaysByType->has($type->id)
                || ! $type->default_transaction_type_id
                || in_array($type->default_transaction_type_id, $categoryTransactionTypeIds, true))
            ->map(fn (DocumentType $type) => ['label' => $type->document_type, 'value' => round($averageDaysByType[$type->id] ?? 0, 2), 'tone' => ''])
            ->values()
            ->all();
        $maxAvg = collect($processingTimes)->max('value') ?? 0;

        // Calculate the CSS bar width relative to the maximum time
        foreach ($processingTimes as &$pt) {
            $pt['width'] = $maxAvg > 0 ? round(($pt['value'] / $maxAvg) * 100) : 0;

            if ($pt['width'] > 75) {
                $pt['tone'] = 'emerald-600';
            } elseif ($pt['width'] > 50) {
                $pt['tone'] = 'emerald-500';
            } elseif ($pt['width'] > 25) {
                $pt['tone'] = 'emerald-400';
            } else {
                $pt['tone'] = 'emerald-300';
            }
        }

        return Inertia::render('Dashboard', [
            'selectedMonth' => $selectedMonthName,
            'selectedYear' => $year,
            'years' => $request->years(),
            'transactionCategories' => DashboardReportService::TRANSACTION_CATEGORIES,
            'processingCategory' => $processingCategory,
            'summaryCards' => [
                ['label' => 'Monthly Documents', 'value' => number_format($totalDocuments), 'badge' => 'Total', 'badgeColor' => 'emerald'],
                ['label' => 'External Clients', 'value' => number_format($externalClients), 'suffix' => 'Served'],
                ['label' => 'Internal Clients', 'value' => number_format($internalClients), 'suffix' => 'Served'],
            ],
            'delinquent' => [
                'value' => $reports->delinquent($openDocuments)->count(),
                'label' => 'Transactions',
                'todo' => $openDocuments->count(),
                'dueTomorrow' => $reports->dueTomorrow($openDocuments)->count(),
                'scope' => $seesAllDepartments ? 'all' : 'office',
            ],
            'pipeline' => $pipeline,
            'processingTimes' => $processingTimes,
            'months' => DashboardFilterRequest::MONTHS,
        ]);
    }
}
