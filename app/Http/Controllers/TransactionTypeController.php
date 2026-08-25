<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\TransactionType;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TransactionTypeController extends Controller
{
    public function index(Request $request)
    {
        $query = TransactionType::query();
        
        // Search functionality
        if ($request->filled('search')) {
            $searchTerm = $request->search;
            $query->where('name', 'LIKE', "%{$searchTerm}%");
        }
        
        // Paginate with 10 items per page
        $transactiontypes = $query->orderBy('id', 'asc')
                                  ->paginate(10)
                                  ->withQueryString();
        
        return Inertia::render('transaction-types/Index', [
            'transactiontypes' => $transactiontypes,
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
            'name' => 'required|string|max:100',
            'processing_days' => 'required|integer|min:0',
            'state' => 'required|in:0,1',
        ]);

        TransactionType::create([
            ...$validated
        ]);
        
        return redirect()->route('transaction-types.index')->with('success', 'Transaction type created successfully');
    }

    public function update(Request $request, TransactionType $transaction_type)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'processing_days' => 'required|integer|min:0',
            'state' => 'required|in:0,1',
        ]);

        $transaction_type->update($validated);
        
        return redirect()->route('transaction-types.index')->with('success', 'Transaction type updated successfully');
    }

    public function destroy(TransactionType $transaction_type)
    {
        $transaction_type->update(['state' => 0]);

        // Matching your DocumentType controller by returning an 'error' session flash for deletions
        return redirect()->route('transaction-types.index')->with('error', 'Transaction type deleted successfully');
    }
}