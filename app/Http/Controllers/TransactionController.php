<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Department;
use App\Http\Requests\ProcessDocumentRequest;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TransactionController extends Controller
{
    /**
     * Display the Office Inbox (Pending Transactions).
     */
    public function index(Request $request)
    {
        // STRICT FILTER: Only active documents the user's office holds an open copy of
        // (a document dispatched to several offices appears in each office's inbox).
        $this->authorize('viewAny', Document::class);
        // Documents waiting on an executive step belong to the Referral Slips / OCM queues instead.
        $inbox = fn () => Document::visibleTo($request->user())->with(['documentType', 'transactionType'])
            ->heldBy((int) $request->user()->department_id)
            ->whereNotIn('requires_signature_from', Document::EXECUTIVE_STAGES)
            ->where('status', '!=', 'Completed')
            ->where('state', 1);
        $query = $inbox();

        // Explicit barcode search for the scanner feature
        if ($request->filled('barcode')) {
            $query->where('barcode', 'LIKE', "%{$request->barcode}%");
        }

        $documents = $query->latest()->paginate(10)->withQueryString();

        // Opened from the notification bell: preload that document so its Receive modal opens directly,
        // even when it is not on the current page. Null once the office has acted on it.
        $focusDocument = $request->integer('document')
            ? $inbox()->whereKey($request->integer('document'))->first()
            : null;

        return Inertia::render('transactions/Index', [
            'documents' => $documents,
            // Exclude the current department from the route list so they don't route it to themselves
            'departments' => Department::where('state', 1)->where('id', '!=', $request->user()->department_id)->get(),
            'filters' => $request->only(['barcode']),
            'focusDocument' => $focusDocument,
        ]);
    }

    /**
     * Process the document (End or Route).
     */
    public function process(ProcessDocumentRequest $request, Document $document, TransactionService $transactions)
    {
        $transactions->process($document, $request->user(), $request->validated());

        return redirect()->back()->with('success', 'Transaction successfully processed.');
    }
}
