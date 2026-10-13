<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\TransactionType;
use App\Models\DocumentType;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        
        // Default to current month if not specified
        $selectedMonthName = $request->get('month', Carbon::now('Asia/Manila')->format('F'));
        if (!in_array($selectedMonthName, $months)) {
            $selectedMonthName = Carbon::now('Asia/Manila')->format('F');
        }
        
        $year = Carbon::now('Asia/Manila')->year;
        $monthNumber = Carbon::parse("1 $selectedMonthName $year")->month;
        
        // Base Query: All active documents created in the selected month
        $monthQuery = Document::whereYear('created_at', $year)
                              ->whereMonth('created_at', $monthNumber)
                              ->where('state', 1);

        // ---------------------------------------------------------
        // 1. TOP SUMMARY CARDS
        // ---------------------------------------------------------
        $totalDocuments = (clone $monthQuery)->count();
        $externalClients = (clone $monthQuery)->where('source_type', 'External')->count();
        $internalClients = (clone $monthQuery)->where('source_type', 'Internal')->count();

        // ---------------------------------------------------------
        // 2. DELINQUENT / ACTION REQUIRED (All active departments)
        // ---------------------------------------------------------
        // Each document's accessor resolves the SLA from its current department,
        // so this remains accurate while presenting the dashboard globally.
        $openDocuments = Document::with(['trails', 'documentType'])
            ->where('status', '!=', 'Completed')
            ->where('state', 1)
            ->get();
            
        $todoCount = $openDocuments->count();
        
        $tomorrowStart = Carbon::tomorrow('Asia/Manila')->startOfDay();
        $tomorrowEnd = Carbon::tomorrow('Asia/Manila')->endOfDay();
        
        $dueTomorrowCount = $openDocuments->where('due_date', '>=', $tomorrowStart)
                                       ->where('due_date', '<=', $tomorrowEnd)
                                       ->count();
        
        // Uses the dynamic 'is_delinquent' EODB accessor from your Document model!
        $delinquentCount = $openDocuments->filter(function($doc) {
            return $doc->is_delinquent; 
        })->count();

        // ---------------------------------------------------------
        // 3. E.O.D.B PIPELINE (On-Time, On-Going, Delayed)
        // ---------------------------------------------------------
        $transactionTypes = TransactionType::where('state', 1)->pluck('name', 'id');
        $allMonthDocs = clone $monthQuery->get();
        
        $pipelineData = [
            'On-Time'  => ['Simple' => 0, 'Complex' => 0, 'Highly Technical' => 0],
            'On-Going' => ['Simple' => 0, 'Complex' => 0, 'Highly Technical' => 0],
            'Delayed'  => ['Simple' => 0, 'Complex' => 0, 'Highly Technical' => 0],
        ];
        
        $mapTransType = function($name) {
            if (stripos($name, 'Complex') !== false) return 'Complex';
            if (stripos($name, 'Technical') !== false) return 'Highly Technical';
            return 'Simple';
        };

        $selectedCategory = $request->string('transaction_category')->toString();
        if (!in_array($selectedCategory, ['Simple', 'Complex', 'Highly Technical'], true)) {
            $selectedCategory = null;
        }
        $selectedPipeline = $request->string('pipeline_status')->toString();
        if (!in_array($selectedPipeline, ['on_time', 'on_going', 'delayed'], true)) {
            $selectedPipeline = null;
        }

        $drillDownDocuments = null;
        $reportTitle = null;
        $reportDescription = null;
        if ($selectedCategory) {
            $transactionTypeIds = $transactionTypes
                ->filter(fn ($name) => $mapTransType($name) === $selectedCategory)
                ->keys()
                ->all();

            $drillDownQuery = (clone $monthQuery)
                ->with(['transactionType', 'documentType', 'department'])
                ->whereIn('transaction_type_id', $transactionTypeIds);

            // These conditions deliberately mirror the pipeline counter below:
            // on-time is completed on/before its due date, on-going is still
            // open and within SLA, and delayed has exceeded its due date.
            $dashboardNow = Carbon::now('Asia/Manila');
            if ($selectedPipeline === 'on_time') {
                $drillDownQuery->where('status', 'Completed')
                    ->where(fn ($documents) => $documents->whereNull('due_date')->orWhereColumn('completed_at', '<=', 'due_date'));
            } elseif ($selectedPipeline === 'on_going') {
                $drillDownQuery->where('status', '!=', 'Completed')
                    ->where(fn ($documents) => $documents->whereNull('due_date')->orWhere('due_date', '>=', $dashboardNow));
            } elseif ($selectedPipeline === 'delayed') {
                $drillDownQuery->where(function ($documents) use ($dashboardNow) {
                    $documents->where(fn ($completed) => $completed->where('status', 'Completed')->whereNotNull('due_date')->whereColumn('completed_at', '>', 'due_date'))
                        ->orWhere(fn ($open) => $open->where('status', '!=', 'Completed')->whereNotNull('due_date')->where('due_date', '<', $dashboardNow));
                });
            }

            $drillDownDocuments = $drillDownQuery->latest()->paginate(15, ['*'], 'documents_page')->withQueryString();
            $pipelineLabels = ['on_time' => 'On-Time', 'on_going' => 'On-Going', 'delayed' => 'Delayed'];
            $reportTitle = ($pipelineLabels[$selectedPipeline] ?? 'All').' '.$selectedCategory.' Documents';
            $reportDescription = $selectedMonthName.' '.$year;
        } elseif ($request->boolean('delinquent')) {
            // Delinquency comes from each document's current department's
            // PerformanceStandard, so use the same accessor as the card.
            $delinquentDocuments = $openDocuments->filter(fn (Document $document) => $document->is_delinquent)
                ->sortByDesc('created_at')
                ->values();
            $page = LengthAwarePaginator::resolveCurrentPage('documents_page');
            $perPage = 15;
            $drillDownDocuments = new LengthAwarePaginator(
                $delinquentDocuments->forPage($page, $perPage)->values(),
                $delinquentDocuments->count(),
                $perPage,
                $page,
                ['path' => $request->url(), 'pageName' => 'documents_page', 'query' => $request->query()]
            );
            $reportTitle = 'Delinquent Documents (All Departments)';
            $reportDescription = 'Performance-standard SLA breaches across all active departments';
        }

        $now = Carbon::now('Asia/Manila');
        
        foreach($allMonthDocs as $doc) {
            $transName = $transactionTypes[$doc->transaction_type_id] ?? 'Simple';
            $bucket = $mapTransType($transName);
            
            if ($doc->status === 'Completed') {
                if ($doc->due_date && Carbon::parse($doc->completed_at)->greaterThan($doc->due_date)) {
                    $pipelineData['Delayed'][$bucket]++;
                } else {
                    $pipelineData['On-Time'][$bucket]++;
                }
            } else {
                if ($doc->due_date && $now->greaterThan($doc->due_date)) {
                    $pipelineData['Delayed'][$bucket]++;
                } else {
                    $pipelineData['On-Going'][$bucket]++;
                }
            }
        }

        $pipeline = [
            [
                'title' => 'On-Time', 'subtitle' => 'Documents', 'status' => 'Healthy', 'statusColor' => 'emerald',
                'rows' => [
                    ['label' => 'Simple', 'value' => $pipelineData['On-Time']['Simple'], 'pipeline' => 'on_time'],
                    ['label' => 'Complex', 'value' => $pipelineData['On-Time']['Complex'], 'pipeline' => 'on_time'],
                    ['label' => 'Highly Technical', 'value' => $pipelineData['On-Time']['Highly Technical'], 'pipeline' => 'on_time'],
                ]
            ],
            [
                'title' => 'On-Going', 'subtitle' => 'Documents', 'status' => 'In Progress', 'statusColor' => 'blue',
                'rows' => [
                    ['label' => 'Simple', 'value' => $pipelineData['On-Going']['Simple'], 'pipeline' => 'on_going'],
                    ['label' => 'Complex', 'value' => $pipelineData['On-Going']['Complex'], 'pipeline' => 'on_going'],
                    ['label' => 'Highly Technical', 'value' => $pipelineData['On-Going']['Highly Technical'], 'pipeline' => 'on_going'],
                ]
            ],
            [
                'title' => 'Delayed', 'subtitle' => 'Documents', 'status' => 'Attention', 'statusColor' => 'orange',
                'rows' => [
                    ['label' => 'Simple', 'value' => $pipelineData['Delayed']['Simple'], 'pipeline' => 'delayed'],
                    ['label' => 'Complex', 'value' => $pipelineData['Delayed']['Complex'], 'pipeline' => 'delayed'],
                    ['label' => 'Highly Technical', 'value' => $pipelineData['Delayed']['Highly Technical'], 'pipeline' => 'delayed'],
                ]
            ]
        ];

        // ---------------------------------------------------------
        // 4. AVERAGE PROCESSING TIMES (By Document Type)
        // ---------------------------------------------------------
        $completedDocs = Document::with('documentType')
            ->where('state', 1)
            ->where('status', 'Completed')
            ->whereNotNull('completed_at')
            ->whereYear('completed_at', $year)
            ->whereMonth('completed_at', $monthNumber)
            ->get();
            
        $typeStats = [];
        foreach($completedDocs as $doc) {
            $typeName = $doc->documentType ? $doc->documentType->document_type : 'Unknown';
            if (!isset($typeStats[$typeName])) {
                $typeStats[$typeName] = ['total_days' => 0, 'count' => 0];
            }
            $days = Carbon::parse($doc->created_at)->diffInDays(Carbon::parse($doc->completed_at));
            $typeStats[$typeName]['total_days'] += max($days, 0.1); // Add min 0.1 so same-day docs show up
            $typeStats[$typeName]['count']++;
        }
        
        $processingTimes = [];
        $maxAvg = 0;
        
        foreach($typeStats as $label => $stats) {
            $avg = $stats['total_days'] / $stats['count'];
            if ($avg > $maxAvg) $maxAvg = $avg;
            
            $processingTimes[] = ['label' => $label, 'value' => round($avg, 2), 'tone' => ''];
        }
        
        // Sort highest days first
        usort($processingTimes, fn($a, $b) => $b['value'] <=> $a['value']);
        
        // Calculate the CSS bar width relative to the maximum time
        foreach($processingTimes as &$pt) {
            $pt['width'] = $maxAvg > 0 ? round(($pt['value'] / $maxAvg) * 100) : 0;
            
            if ($pt['width'] > 75) $pt['tone'] = 'emerald-600';
            elseif ($pt['width'] > 50) $pt['tone'] = 'emerald-500';
            elseif ($pt['width'] > 25) $pt['tone'] = 'emerald-400';
            else $pt['tone'] = 'emerald-300';
        }

        return Inertia::render('Dashboard', [
            'selectedMonth' => $selectedMonthName,
            'summaryCards' => [
                ['label' => 'Monthly Documents', 'value' => number_format($totalDocuments), 'badge' => 'Total', 'badgeColor' => 'emerald'],
                ['label' => 'External Clients', 'value' => number_format($externalClients), 'suffix' => 'Served'],
                ['label' => 'Internal Clients', 'value' => number_format($internalClients), 'suffix' => 'Served'],
            ],
            'delinquent' => [
                'value' => $delinquentCount,
                'label' => 'Transactions',
                'todo' => $todoCount,
                'dueTomorrow' => $dueTomorrowCount,
            ],
            'pipeline' => $pipeline,
            'processingTimes' => $processingTimes,
            'months' => $months,
            'selectedCategory' => $selectedCategory,
            'selectedPipeline' => $selectedPipeline,
            'drillDownDocuments' => $drillDownDocuments,
            'reportTitle' => $reportTitle,
            'reportDescription' => $reportDescription,
        ]);
    }
}
