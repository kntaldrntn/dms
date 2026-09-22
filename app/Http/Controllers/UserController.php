<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage; // Added for file handling
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
            'role' => ['required', Rule::in(['staff', 'department_head', 'city_administrator', 'city_mayor', 'records_manager', 'system_administrator'])],
            'sex' => ['nullable', Rule::in(['Male', 'Female'])],
            'state' => ['required', 'in:0,1'],
            'department_id' => 'nullable|exists:departments,id',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'signature' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:2048'], // 2MB max limit
        ]);

        $signaturePath = null;
        if ($request->hasFile('signature')) {
            $signaturePath = $request->file('signature')->store('signatures', 'public');
        }

        User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'sex' => $validated['sex'] ?? null,
            'state' => $validated['state'] ?? 1,
            'department_id' => $validated['department_id'],
            'signature_path' => $signaturePath, // Save the path to DB
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
            'role' => ['required', Rule::in(['staff', 'department_head', 'city_administrator', 'city_mayor', 'records_manager', 'system_administrator'])],
            'sex' => ['nullable', Rule::in(['Male', 'Female'])],
            'state' => ['required', 'in:0,1'],
            'department_id' => 'nullable|exists:departments,id',
            'signature' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:2048'],
        ]);

        if ($request->filled('password')) {
            $request->validate([
                'password' => ['confirmed', Rules\Password::defaults()]
            ]);
            $user->password = Hash::make($request->password);
        }

        $user->fill($validated);

        // Handle signature upload and replace old file if it exists
        if ($request->hasFile('signature')) {
            if ($user->signature_path && Storage::disk('public')->exists($user->signature_path)) {
                Storage::disk('public')->delete($user->signature_path);
            }
            $user->signature_path = $request->file('signature')->store('signatures', 'public');
        }

        // --- AUDIT TRAIL LOGIC ---
        $dirty = $user->getDirty(); 
        
        if (!empty($dirty)) {
            unset($dirty['updated_at']);

            if (!empty($dirty)) {
                $oldValues = $user->getOriginal();
                $newValues = $user->getAttributes();

                $hiddenFields = ['password', 'remember_token', 'updated_at', 'created_at', 'email_verified_at'];
                foreach ($hiddenFields as $field) {
                    unset($oldValues[$field], $newValues[$field]);
                }

                $user->save();

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
        if (auth()->id() === $user->id) {
            return redirect()->back()->with('error', 'You cannot deactivate your own account.');
        }

        $user->update(['state' => 0]);

        return redirect()->back()->with('success', 'User deactivated successfully.');
    }
}