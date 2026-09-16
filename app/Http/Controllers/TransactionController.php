<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Document;
use App\Models\DocumentTrail;
use App\Models\Department;
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
            // Exclude the current department from the reroute list so they don't route it to themselves
            'departments' => Department::where('state', 1)->where('id', '!=', Auth::user()->department_id)->get(),
            'filters' => $request->only(['barcode']),
        ]);
    }

    /**
     * Process the document (End or Reroute).
     */
    public function process(Request $request, Document $document)
    {
        if ($document->department_id !== Auth::user()->department_id) {
            return redirect()->back()->with('error', 'Unauthorized: This document is not currently in your office.');
        }

        $validated = $request->validate([
            'action' => 'required|in:end,reroute',
            'remarks' => 'required|string|max:1000',
            'routed_to' => 'required_if:action,reroute|exists:departments,id|nullable',
        ]);

        $now = Carbon::now('Asia/Manila');

        $arrivalTrail = DocumentTrail::where('document_id', $document->id)
            ->where('released_to', Auth::user()->department_id)
            ->latest()
            ->first();

        // If an arrival trail exists, use its release time. If not, use the document's creation time.
        $receivedAt = $arrivalTrail ? ($arrivalTrail->released_at ?? $arrivalTrail->created_at) : $document->created_at;

        if ($validated['action'] === 'end') {
            DocumentTrail::create([
                'document_id' => $document->id,
                'department_id' => Auth::user()->department_id,
                'received_by' => Auth::id(),
                'received_at' => $receivedAt, // <-- Now uses true arrival time!
                'received_action' => 'Receive Document', 
                'released_by' => Auth::id(),
                'released_at' => $now,        // <-- Now uses processing time!
                'released_action' => 'End Transaction',  
                'remarks' => $validated['remarks'], 
            ]);

            $document->update([
                'status' => 'Completed',
                'completed_at' => $now,
            ]);

            return redirect()->back()->with('success', 'Transaction successfully completed and archived.');
        }

        if ($validated['action'] === 'reroute') {
            DocumentTrail::create([
                'document_id' => $document->id,
                'department_id' => Auth::user()->department_id,
                'received_by' => Auth::id(),
                'received_at' => $receivedAt, // <-- Now uses true arrival time!
                'received_action' => 'Receive Document', 
                'released_by' => Auth::id(),
                'released_at' => $now,        // <-- Now uses processing time!
                'released_to' => $validated['routed_to'],
                'released_action' => 'Rerouted',         
                'remarks' => $validated['remarks'],
            ]);

            $document->update([
                'department_id' => $validated['routed_to'],
            ]);

            return redirect()->back()->with('success', 'Document successfully rerouted.');
        }
    }
}