<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\DeliveryMethod;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DeliveryMethodController extends Controller
{
    public function index(Request $request)
    {
        $query = DeliveryMethod::query();

        // Add search functionality if needed
        if ($request->filled('search')) {
            $searchTerm = $request->search;
            $query->where(function($q) use ($searchTerm) {
                $q->where('delivery_method_name', 'LIKE', "%{$searchTerm}%");
            });
        }

        // Paginate with 10 items per page (adjust as needed)
        $deliverymethods = $query->orderBy('id', 'asc')
                           ->paginate(10)
                           ->withQueryString();

        return Inertia::render('delivery-methods/Index', [
            'deliverymethods' => $deliverymethods,
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
            'delivery_method_name' => 'required|string|max:100',
            'state' => 'required|in:0,1',
        ]);

        DeliveryMethod::create([
            ...$validated
        ]);
        return redirect()->route('delivery-methods.index')->with('success','Delivery method created successfully');
    }
    public function update(Request $request, DeliveryMethod $delivery_method)
    {
        $validated = $request->validate([
            'delivery_method_name' => 'required|string|max:100',
            'state' => 'required|in:0,1',
        ]);

        $delivery_method->update($validated);
        return redirect()->route('delivery-methods.index')->with('success','Delivery method updated successfully');
    }

    public function destroy(DeliveryMethod $delivery_method)
    {
        $delivery_method->update(['state' => 0]);

        return redirect()->route('delivery-methods.index')->with('error', 'Delivery Method deleted successfully');
    }
}
