<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveDocumentTypeRequest;
use App\Models\DocumentClassification;
use App\Models\DocumentType;
use App\Models\TransactionType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DocumentTypeController extends Controller
{
    public function index(Request $request)
    {
        $query = DocumentType::query()->with(['classifications:id,name', 'defaultTransactionType:id,name']);

        if ($request->filled('search')) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('document_code', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('document_type', 'LIKE', "%{$searchTerm}%");
            });
        }

        $documenttypes = $query->orderBy('id', 'asc')
            ->paginate(14)
            ->withQueryString();

        return Inertia::render('document-types/Index', [
            'documenttypes' => $documenttypes,
            'transactionTypes' => TransactionType::where('state', 1)->orderBy('processing_days')->get(['id', 'name']),
            'classifications' => DocumentClassification::where('state', 1)->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['search']),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    public function store(SaveDocumentTypeRequest $request)
    {
        DB::transaction(function () use ($request) {
            $documentType = DocumentType::create($request->safe()->except('classification_ids'));
            $documentType->classifications()->sync($request->validated('classification_ids', []));
        });

        return redirect()->route('document-types.index')->with('success', 'Document type created successfully');
    }

    public function update(SaveDocumentTypeRequest $request, DocumentType $document_type)
    {
        DB::transaction(function () use ($request, $document_type) {
            $document_type->update($request->safe()->except('classification_ids'));

            if ($request->has('classification_ids')) {
                $document_type->classifications()->sync($request->validated('classification_ids', []));
            }
        });

        return redirect()->route('document-types.index')->with('success', 'Document type updated successfully');
    }

    public function destroy(DocumentType $document_type)
    {
        $document_type->update(['state' => 0]);

        return redirect()->route('document-types.index')->with('error', 'Document Type deleted successfully');
    }
}
