<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Illuminate\Support\Facades\Cache;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with([
            'department',
            'loginHistories' => function ($q) {
                $q->latest('login_at')->take(10);
            },
            'auditLogs' => function ($q) {
                $q->with('actor')->latest()->take(15);
            }
        ]);

        if ($request->filled('search')) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('email', 'LIKE', "%{$searchTerm}%");
            });
        }

        $users = $query->orderBy('id', 'desc')->paginate(10)->withQueryString();

        // Check the cache for each user in the paginated results
        $users->getCollection()->transform(function ($user) {
            $user->is_online = Cache::has('user-is-online-' . $user->id);
            return $user;
        });

        $departments = Department::orderBy('name')->get();

        return Inertia::render('users/Index', [
            'users' => $users,
            'departments' => $departments,
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
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:' . User::class,
            'role' => ['required', Rule::in(['system_administrator', 'records_manager', 'user'])],
            'sex' => ['nullable', Rule::in(['Male', 'Female'])],
            'state' => ['required', 'in:0,1'], // Replaced status
            'department_id' => 'nullable|exists:departments,id',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'sex' => $validated['sex'] ?? null,
            'state' => $validated['state'] ?? 1, // Default to 1 (Active)
            'department_id' => $validated['department_id'],
            'password' => Hash::make($validated['password']),
            'email_verified_at' => now(),
        ]);

        return redirect()->back()->with('success', 'User created successfully.');
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . $user->id,
            'role' => ['required', Rule::in(['system_administrator', 'records_manager', 'user'])],
            'sex' => ['nullable', Rule::in(['Male', 'Female'])],
            'state' => ['required', 'in:0,1'],
            'department_id' => 'nullable|exists:departments,id',
        ]);

        if ($request->filled('password')) {
            $request->validate([
                'password' => ['confirmed', Rules\Password::defaults()]
            ]);
            $user->password = Hash::make($request->password);
        }

        $user->fill($validated);

        // --- AUDIT TRAIL LOGIC: Full Snapshot ---
        $dirty = $user->getDirty(); 
        
        if (!empty($dirty)) {
            unset($dirty['updated_at']);

            if (!empty($dirty)) {
                // 1. Grab the COMPLETE old and new states
                $oldValues = $user->getOriginal();
                $newValues = $user->getAttributes();

                // 2. Strip out sensitive/noisy fields from the logs
                    // In UserController @update
                $hiddenFields = ['password', 'remember_token', 'updated_at', 'created_at', 'email_verified_at'];
                foreach ($hiddenFields as $field) {
                    unset($oldValues[$field], $newValues[$field]);
                }

                // 3. Save the user
                $user->save();

                // 4. Create the full-context audit log
                \App\Models\AuditLog::create([
                    'actor_id' => auth()->id(),
                    'model_type' => User::class,
                    'model_id' => $user->id,
                    'action' => 'updated',
                    'old_values' => $oldValues,
                    'new_values' => $newValues,
                ]);
            } else {
                $user->save(); 
            }
        } else {
            $user->save();
        }

        return redirect()->back()->with('success', 'User updated successfully.');
    }

    public function destroy(User $user)
    {
        // Prevent the user from deactivating themselves accidentally
        if (auth()->id() === $user->id) {
            return redirect()->back()->with('error', 'You cannot deactivate your own account.');
        }

        // Update the state to 0 (Inactive) instead of $user->delete();
        $user->update(['state' => 0]);

        return redirect()->back()->with('success', 'User deactivated successfully.');
    }
}
