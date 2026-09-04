<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\PerformanceStandard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PerformanceStandardController extends Controller
{
    public function index()
    {
        // Get rows (Departments) and columns (Document Types) that are active
        $departments = Department::where('state', 1)->orderBy('name')->get(['id', 'name']);
        
        // Assuming your document_types table has a 'document_code' (like 'AOC', 'PR') based on your earlier code
        $documentTypes = DocumentType::where('state', 1)->orderBy('document_code')->get(['id', 'document_code']);
        
        // Get all existing saved standards
        $standards = PerformanceStandard::all(['department_id', 'document_type_id', 'allocated_minutes']);

        return Inertia::render('performance-standards/Index', [
            'departments' => $departments,
            'documentTypes' => $documentTypes,
            'existingStandards' => $standards,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'standards' => 'required|array',
            'standards.*.department_id' => 'required|exists:departments,id',
            'standards.*.document_type_id' => 'required|exists:document_types,id',
            'standards.*.allocated_minutes' => 'required|integer|min:0',
        ]);

        // Use a database transaction for a safe, bulk update
        DB::transaction(function () use ($request) {
            foreach ($request->standards as $standard) {
                PerformanceStandard::updateOrCreate(
                    [
                        'department_id' => $standard['department_id'],
                        'document_type_id' => $standard['document_type_id']
                    ],
                    [
                        'allocated_minutes' => $standard['allocated_minutes']
                    ]
                );
            }
        });

        return redirect()->back()->with('success', 'Performance standards updated successfully.');
    }
}