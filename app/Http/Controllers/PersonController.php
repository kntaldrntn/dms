<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavePersonRequest;
use App\Models\Department;
use App\Models\Person;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PersonController extends Controller
{
    public function index(Request $request)
    {
        $query = Person::query()->with('department:id,code,name');

        if ($request->filled('search')) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('first_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('last_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('position', 'LIKE', "%{$searchTerm}%");
            });
        }

        return Inertia::render('people/Index', [
            'people' => $query->orderBy('last_name')->orderBy('first_name')->paginate(14)->withQueryString(),
            'departments' => Department::where('state', 1)->orderBy('code')->get(['id', 'code', 'name']),
            'filters' => $request->only(['search']),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    public function store(SavePersonRequest $request)
    {
        Person::create($request->validated());

        return redirect()->route('people.index')->with('success', 'Person added to the directory.');
    }

    public function update(SavePersonRequest $request, Person $person)
    {
        $person->update($request->validated());

        return redirect()->route('people.index')->with('success', 'Person updated.');
    }

    public function destroy(Person $person)
    {
        $person->update(['state' => 0]);

        return redirect()->route('people.index')->with('error', 'Person deactivated.');
    }
}
