<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\DocumentType;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DocumentTypeController extends Controller
{
    public function index(Request $request)
    {
        $query = DocumentType::query();
        
        // Add search functionality if needed
        if ($request->filled('search')) {
            $searchTerm = $request->search;
            $query->where(function($q) use ($searchTerm) {
                $q->where('document_code', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('document_type', 'LIKE', "%{$searchTerm}%");
            });
        }
        
        // Paginate with 10 items per page (adjust as needed)
        $documenttypes = $query->orderBy('id', 'asc')
                           ->paginate(10)
                           ->withQueryString();
        
        return Inertia::render('document-types/Index', [
            'documenttypes' => $documenttypes,
            'filters' => $request->only(['search']),
            'flash' => [
                'success' => session('success'),
                'error' => session('error')     
            ]
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'document_code' => 'required|string|max:50',
            'document_type' => 'required|string|max:100',
            'state' => 'required|in:0,1',
        ]);

        DocumentType::create([
            ...$validated
        ]);
        return redirect()->route('document-types.index')->with('success','Document type created successfully');
    }

    public function update(Request $request, DocumentType $document_type)
    {
        $validated = $request->validate([
            'document_code' => 'required|string|max:50',
            'document_type' => 'required|string|max:100',
            'state' => 'required|in:0,1',
        ]);

        $document_type->update($validated);
        return redirect()->route('document-types.index')->with('success','Document type updated successfully');
    }

    public function destroy(DocumentType $document_type)
    {
        $document_type->update(['state' => 0]);

        return redirect()->route('document-types.index')->with('error', 'Document Type deleted successfully');
    }
}
