<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\TransactionType;
use App\Models\DocumentType;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        // 2. DELINQUENT / ACTION REQUIRED (For the current user's office)
        // ---------------------------------------------------------
        $userDeptId = Auth::user()->department_id;
        
        // Get all pending docs currently sitting in the user's department
        $myDeptDocs = Document::with(['trails', 'documentType'])
            ->where('department_id', $userDeptId)
            ->where('status', '!=', 'Completed')
            ->where('state', 1)
            ->get();
            
        $todoCount = $myDeptDocs->count();
        
        $tomorrowStart = Carbon::tomorrow('Asia/Manila')->startOfDay();
        $tomorrowEnd = Carbon::tomorrow('Asia/Manila')->endOfDay();
        
        $dueTomorrowCount = $myDeptDocs->where('due_date', '>=', $tomorrowStart)
                                       ->where('due_date', '<=', $tomorrowEnd)
                                       ->count();
        
        // Uses the dynamic 'is_delinquent' EODB accessor from your Document model!
        $delinquentCount = $myDeptDocs->filter(function($doc) {
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
                    ['label' => 'Simple', 'value' => $pipelineData['On-Time']['Simple']],
                    ['label' => 'Complex', 'value' => $pipelineData['On-Time']['Complex']],
                    ['label' => 'Highly Technical', 'value' => $pipelineData['On-Time']['Highly Technical']],
                ]
            ],
            [
                'title' => 'On-Going', 'subtitle' => 'Documents', 'status' => 'In Progress', 'statusColor' => 'blue',
                'rows' => [
                    ['label' => 'Simple', 'value' => $pipelineData['On-Going']['Simple']],
                    ['label' => 'Complex', 'value' => $pipelineData['On-Going']['Complex']],
                    ['label' => 'Highly Technical', 'value' => $pipelineData['On-Going']['Highly Technical']],
                ]
            ],
            [
                'title' => 'Delayed', 'subtitle' => 'Documents', 'status' => 'Attention', 'statusColor' => 'orange',
                'rows' => [
                    ['label' => 'Simple', 'value' => $pipelineData['Delayed']['Simple']],
                    ['label' => 'Complex', 'value' => $pipelineData['Delayed']['Complex']],
                    ['label' => 'Highly Technical', 'value' => $pipelineData['Delayed']['Highly Technical']],
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
            'months' => $months
        ]);
    }
}