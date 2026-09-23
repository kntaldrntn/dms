<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Document;
use App\Models\DocumentTrail;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class TransactionController extends Controller
{
    /**
     * Display the Office Inbox (Pending Transactions).
     */
    public function index(Request $request)
    {
        // STRICT FILTER: Only active documents currently assigned to the user's department
        $query = Document::with(['documentType', 'transactionType'])
            ->where('department_id', Auth::user()->department_id)
            ->where('status', '!=', 'Completed') 
            ->where('state', 1);

        // Explicit barcode search for the scanner feature
        if ($request->filled('barcode')) {
            $query->where('barcode', 'LIKE', "%{$request->barcode}%");
        }

        $documents = $query->latest()->paginate(10)->withQueryString();

        return Inertia::render('transactions/Index', [
            'documents' => $documents,
            // Exclude the current department from the route list so they don't route it to themselves
            'departments' => Department::where('state', 1)->where('id', '!=', Auth::user()->department_id)->get(),
            'filters' => $request->only(['barcode']),
        ]);
    }

    /**
     * Process the document (End or Route).
     */
    public function process(Request $request, Document $document)
    {
        if ($document->department_id !== Auth::user()->department_id) {
            return redirect()->back()->with('error', 'Unauthorized: This document is not currently in your office.');
        }

        $validated = $request->validate([
            'action' => 'required|in:end,route',
            'remarks' => 'required|string|max:1000',
            'routed_to' => 'required_if:action,route|exists:departments,id|nullable',
            'attach_signature' => 'boolean',
        ]);

        $now = Carbon::now('Asia/Manila');
        $user = Auth::user();
        $user->load('department');

        $arrivalTrail = DocumentTrail::where('document_id', $document->id)
            ->where('released_to', $user->department_id)
            ->latest()
            ->first();

        // If an arrival trail exists, use its release time. If not, use the document's creation time.
        $receivedAt = $arrivalTrail ? ($arrivalTrail->released_at ?? $arrivalTrail->created_at) : $document->created_at;

        // Digital Signature & Workflow Flag Logic
        $trailType = 'forward';
        $isSigned = false;
        $signedBy = null;

        if ($validated['attach_signature'] ?? false) {
            $trailType = 'approval';
            $isSigned = true;

            if ($user->role === 'staff') {
                $departmentHead = User::where('department_id', $user->department_id)->where('role', 'department_head')->first();
                if (!$departmentHead || !$departmentHead->signature_path) {
                    return back()->with('error', 'Cannot attach signature. The Department Head has not uploaded a digital signature.');
                }
                $signedBy = $departmentHead->id;
            } else {
                if (!$user->signature_path) return back()->with('error', 'You must upload a digital signature first.');
                $signedBy = $user->id;
            }
        }

        if ($validated['action'] === 'end') {
            DocumentTrail::create([
                'document_id' => $document->id,
                'department_id' => $user->department_id,
                'received_by' => $user->id,
                'received_at' => $receivedAt, 
                'received_action' => 'Receive Document', 
                'released_by' => $user->id,
                'released_at' => $now,        
                'released_action' => 'End Transaction',  
                'remarks' => $validated['remarks'], 
                'trail_type' => $trailType,
                'is_digitally_signed' => $isSigned,
                'signed_by' => $signedBy,
            ]);

            $document->update([
                'status' => 'Completed',
                'completed_at' => $now,
            ]);

            return redirect()->back()->with('success', 'Transaction successfully completed and archived.');
        }

        if ($validated['action'] === 'route') {
            DocumentTrail::create([
                'document_id' => $document->id,
                'department_id' => $user->department_id,
                'received_by' => $user->id,
                'received_at' => $receivedAt, 
                'received_action' => 'Receive Document', 
                'released_by' => $user->id,
                'released_at' => $now,        
                'released_to' => $validated['routed_to'],
                'released_action' => 'Routed',         
                'remarks' => $validated['remarks'],
                'trail_type' => $trailType,
                'is_digitally_signed' => $isSigned,
                'signed_by' => $signedBy,
            ]);

            // AUTO-RECEIVE FIX: Populate target office's action to prevent blank lines
            $senderCode = $user->department->code ?? 'Previous Office';
            DocumentTrail::create([
                'document_id' => $document->id,
                'department_id' => $validated['routed_to'], 
                'received_at' => $now,
                'received_action' => "Routed from {$senderCode}",
            ]);

            $document->update([
                'department_id' => $validated['routed_to'],
            ]);

            return redirect()->back()->with('success', 'Document successfully routed.');
        }
    }
}