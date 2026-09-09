<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\DocumentClassification;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DocumentClassificationController extends Controller
{
    public function index(Request $request)
    {
        $query = DocumentClassification::query();
        
        // Add search functionality matching the reference format
        if ($request->filled('search')) {
            $searchTerm = $request->search;
            $query->where(function($q) use ($searchTerm) {
                $q->where('name', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('description', 'LIKE', "%{$searchTerm}%");
            });
        }
        
        // Paginate with 10 items per page, ordered by ID
        $classifications = $query->orderBy('id', 'asc')
                           ->paginate(14)
                           ->withQueryString();
        
        return Inertia::render('document-classifications/Index', [
            'classifications' => $classifications,
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
            'name' => 'required|string|max:255|unique:document_classifications,name',
            'description' => 'nullable|string|max:500',
            'state' => 'required|in:0,1',
        ]);

        DocumentClassification::create([
            ...$validated
        ]);
        
        return redirect()->route('document-classifications.index')->with('success', 'Document classification created successfully');
    }

    public function update(Request $request, DocumentClassification $document_classification)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:document_classifications,name,' . $document_classification->id,
            'description' => 'nullable|string|max:500',
            'state' => 'required|in:0,1',
        ]);

        $document_classification->update($validated);
        
        return redirect()->route('document-classifications.index')->with('success', 'Document classification updated successfully');
    }

    public function destroy(DocumentClassification $document_classification)
    {
        // Soft delete by setting state to 0
        $document_classification->update(['state' => 0]);

        return redirect()->route('document-classifications.index')->with('error', 'Document classification deleted successfully');
    }
}