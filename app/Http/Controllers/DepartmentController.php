<?php

namespace App\Http\Controllers;

use App\Models\Department;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DepartmentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Department::query();
        
        // Add search functionality if needed
        if ($request->filled('search')) {
            $searchTerm = $request->search;
            $query->where(function($q) use ($searchTerm) {
                $q->where('code', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('name', 'LIKE', "%{$searchTerm}%");
            });
        }
        
        // Paginate with 10 items per page (adjust as needed)
        $departments = $query->orderBy('id', 'asc')
                           ->paginate(14)
                           ->withQueryString();
        
        return Inertia::render('departments/Index', [
            'departments' => $departments,
            'filters' => $request->only(['search']),
            'flash' => [
                'success' => session('success'),
                'error' => session('error')     
            ]
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50',
            'name' => 'required|string|max:100',
            'state' => 'required|in:0,1',
        ]);

        Department::create([
            ...$validated
        ]);
        return redirect()->route('departments.index')->with('success','Department created successfully');
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Department $department)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50',
            'name' => 'required|string|max:100',
            'state' => 'required|in:0,1',
        ]);

        $department->update($validated);

        return redirect()->route('departments.index')->with('success','Department updated successfully');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Department $department)
    {
        $department->update(['state' => 0]);

        return redirect()->route('departments.index')->with('error','Department deleted successfully');
    }
}