<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangePrimaryOfficeRequest;
use App\Http\Requests\ProcessDocumentRequest;
use App\Http\Requests\ReceiveCopyRequest;
use App\Http\Requests\ReceiveDocumentRequest;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentTrail;
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
        // Documents waiting on an executive step belong to the ADM / OCM Approvals queues instead.
        $inbox = fn () => Document::visibleTo($request->user())->with(['documentType', 'transactionType', 'primaryEntry.receiver:id,name'])
            ->heldBy((int) $request->user()->department_id)
            ->outsideExecutiveStages()
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

        // View-only copies sent to this office: it can receive and view them but not act on them.
        $office = (int) $request->user()->department_id;
        $copies = Document::visibleTo($request->user())->copiedTo($office)
            ->where('status', '!=', 'Completed')
            ->with(['documentType:id,document_type', 'department:id,code,name'])
            ->withExists(['trails as copy_received' => fn ($trails) => $trails->where('department_id', $office)
                ->where('copy_role', DocumentTrail::COPY)->whereNull('released_at')->whereNotNull('received_by')])
            ->latest()->paginate(10, ['*'], 'copies_page')->withQueryString();

        return Inertia::render('transactions/Index', [
            'documents' => $documents,
            'copies' => $copies,
            'focusCopy' => $request->integer('copy') ?: null,
            // Exclude the current department from the route list so they don't route it to themselves
            'departments' => Department::where('state', 1)->where('id', '!=', $request->user()->department_id)->get(),
            'filters' => $request->only(['barcode']),
            'focusDocument' => $focusDocument,
            // Receive / Process / Receive copy redirect back here with a message for the toast.
            'flash' => ['success' => session('success'), 'error' => session('error')],
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

    public function receive(ReceiveDocumentRequest $request, Document $document, TransactionService $transactions)
    {
        $transactions->receive($document, $request->user());

        return redirect()->back()->with('success', 'Document received. Process it once your office has acted on it.');
    }

    public function receiveCopy(ReceiveCopyRequest $request, Document $document, TransactionService $transactions)
    {
        $transactions->receiveCopy($document, $request->user());

        return redirect()->back()->with('success', 'Copy received.');
    }

    public function changePrimary(ChangePrimaryOfficeRequest $request, Document $document, TransactionService $transactions)
    {
        $transactions->changePrimary($document, $request->user(), $request->integer('primary_office_id'));

        return redirect()->back()->with('success', 'Primary office changed.');
    }
}
