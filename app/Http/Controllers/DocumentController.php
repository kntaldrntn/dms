<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Http\Requests\AnalyzeDocumentRequest;
use App\Http\Requests\DispatchDocumentRequest;
use App\Http\Requests\DocumentMutationRequest;
use App\Http\Requests\SaveDocumentRequest;
use App\Services\DispatchService;
use App\Services\DocumentIntakeAnalyzer;
use App\Services\DocumentService;
use App\Models\TransactionType;
use App\Models\DocumentType;
use App\Models\DocumentClassification;
use App\Models\DeliveryMethod;
use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Document::class);
        $query = Document::visibleTo($request->user())->with(['documentType', 'department', 'transactionType', 'holders:id,code,name']);

        if ($request->filled('search')) {
            $searchTerm = $request->search;
            $query->where(function($q) use ($searchTerm) {
                $q->where('barcode', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('subject_matter', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('source_name', 'LIKE', "%{$searchTerm}%");
            });
        }

        $documents = $query->latest()->paginate(6)->withQueryString();

        return Inertia::render('documents/Index', [
            'documents' => $documents, 
            'departments' => Department::where('state', 1)->get(),
            'filters' => $request->only(['search']),
            'flash' => [
                'success' => session('success'),
                'error' => session('error')
            ],
            'transactionTypes' => TransactionType::where('state', 1)->get(),
            'documentTypes' => $this->intakeDocumentTypes(),
            'classifications' => DocumentClassification::where('state', 1)->get(),
            'deliveryMethods' => DeliveryMethod::where('state', 1)->get(),
            'generatedAccessCode' => strtoupper(Str::random(6)),
            'canCreate' => $request->user()->can('create', Document::class),
        ]);
    }

    public function create()
    {
        $recentDocuments = Document::with('documentType')
            ->where('state', 1)
            ->latest()
            ->take(5)
            ->get();

        return Inertia::render('documents/Create', [
            'transactionTypes' => TransactionType::where('state', 1)->get(),
            'documentTypes' => DocumentType::where('state', 1)->get(),
            'classifications' => DocumentClassification::where('state', 1)->get(),
            'deliveryMethods' => DeliveryMethod::where('state', 1)->get(),
            'departments' => Department::where('state', 1)->get(),
            'generatedAccessCode' => strtoupper(Str::random(6)),
            'recentDocuments' => $recentDocuments,
        ]);
    }

    public function store(SaveDocumentRequest $request, DocumentService $documents)
    {
        $documents->save(null, $request->user(), $request->validated());

        return redirect()->route('documents.index')->with('success', 'Document registered successfully.');
    }

    public function show(Document $document)
    {
        $this->authorize('view', $document);
        $document->load([
            'transactionType', 
            'documentType', 
            'classification', 
            'deliveryMethod', 
            'department',
            'trails.office',          
            'trails.receiver',        
            'trails.releaser',        
            'trails.releasedToOffice',
            'trails.signer'
        ]);
        
        // A release can go to several offices (multi-office dispatch); resolve them all for the trail table.
        $releaseOffices = Department::whereIn('id', $document->trails->pluck('released_to_offices')->flatten()->filter()->unique())
            ->get(['id', 'code', 'name'])->keyBy('id');
        $document->trails->each(function ($trail) use ($releaseOffices) {
            $offices = collect($trail->released_to_offices ?? [])
                ->map(fn ($id) => $releaseOffices->get((int) $id))->filter()->values();
            // Single-office releases (route, referral, approvals) only store released_to.
            if ($offices->isEmpty() && $trail->releasedToOffice) {
                $offices = collect([$trail->releasedToOffice->only(['id', 'code', 'name'])]);
            }
            $trail->setAttribute('released_to_list', $offices);
        });

        return Inertia::render('documents/Show', [
            'document' => $document,
            'trails' => $document->trails,
            // Offices a pending referral slip will dispatch to, for the "Next" route step.
            'slipTargets' => Department::whereIn('id', $document->approved_routing_slip ?? [])->get(['id', 'code', 'name']),
            // The Primary office first, then every office holding a view-only copy (pivot.copy_role).
            'holders' => $document->holders()->get(['departments.id', 'departments.code', 'departments.name']),
            'canChangePrimary' => request()->user()->can('changePrimary', $document),
            'offices' => fn () => Department::where('state', 1)->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function edit(Document $document)
    {
        $this->authorize('update', $document);
        $this->ensureActive($document);
        return Inertia::render('documents/Edit', [
            'document' => $document,
            'transactionTypes' => TransactionType::where('state', 1)->get(),
            'documentTypes' => $this->intakeDocumentTypes(),
            'classifications' => DocumentClassification::where('state', 1)->get(),
            'deliveryMethods' => DeliveryMethod::where('state', 1)->get(),
            'departments' => Department::where('state', 1)->get(),
        ]);
    }

    public function update(SaveDocumentRequest $request, Document $document, DocumentService $documents)
    {
        $documents->save($document, $request->user(), $request->validated());

        return redirect()->route('documents.index')->with('success', 'Document updated successfully.');
    }

    public function destroy(DocumentMutationRequest $request, Document $document, DocumentService $documents)
    {
        $documents->deactivate($document, $request->user(), $request->validated('workflow_version'));
        return redirect()->back()->with('success', 'Document deactivated successfully.');
    }

    public function analyze(AnalyzeDocumentRequest $request, DocumentIntakeAnalyzer $analyzer)
    {
        try {
            return response()->json($analyzer->analyze($request->validated('extracted_text')));
        } catch (\Throwable $exception) {
            Log::warning('Document intake analysis failed.', [
                'user_id' => $request->user()?->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json(['error' => 'Document analysis is unavailable. Please try again later.'], 502);
        }
    }

    // =====================================================================
    // CORE WORKFLOW & ROUTING MECHANICS 
    // =====================================================================

    public function routeDocument(DispatchDocumentRequest $request, Document $document, DispatchService $dispatch)
    {
        $dispatch->dispatch($document, $request->user(), $request->validated());

        return redirect()->back()->with('success', 'Document successfully routed.');
    }

    // --- SMART SEARCH API ---
    public function searchBarcodes(Request $request)
    {
        $this->authorize('viewAny', Document::class);
        $query = $request->get('q');
        
        if (!$query || strlen($query) < 2) {
            return response()->json([]);
        }

        $results = Document::visibleTo($request->user())->where('barcode', 'LIKE', "%{$query}%")
                           ->select('barcode', 'subject_matter')
                           ->take(5) 
                           ->get();
                           
        return response()->json($results);
    }

    /** Active types with their rulebook entry, for the intake/edit forms (category default, route, classifications). */
    private function intakeDocumentTypes()
    {
        return DocumentType::where('state', 1)->with('classifications:id')
            ->get(['id', 'document_code', 'document_type', 'signature_type', 'may_be_confidential', 'default_transaction_type_id']);
    }

    private function ensureActive(Document $document): void
    {
        abort_if($document->state !== 1 || $document->status === 'Completed', 409, 'This document is no longer active.');
    }
}
