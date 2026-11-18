<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveUserRequest;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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
            $user->signing_status = $this->signingStatus($user);
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

    /**
     * Whether a signer is ready for the paperless route: certificate state + expiry and whether a signature image
     * is on file. Status only: the .p12 file and the PIN are never sent; signers upload their own certificate.
     * Null for roles that do not sign.
     */
    private function signingStatus(User $user): ?array
    {
        if (! in_array($user->role, User::SIGNER_ROLES, true) && ! $user->can_sign_for_head) {
            return null;
        }
        $validUntil = $user->certificate_path ? $user->certificate_valid_until : null;
        $certificate = match (true) {
            $validUntil === null => 'none',
            ! $validUntil->isFuture() => 'expired',
            $validUntil->lessThanOrEqualTo(now()->addDays(30)) => 'expiring',
            default => 'valid',
        };

        return [
            'certificate' => $certificate,
            'valid_until' => $validUntil?->toDateString(),
            'signature_image' => (bool) $user->signature_path,
        ];
    }

    public function store(SaveUserRequest $request)
    {
        $validated = $request->validated();

        $signaturePath = null;
        if ($request->hasFile('signature')) {
            $signaturePath = $request->file('signature')->store('signatures', 'local');
        }

        User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'sex' => $validated['sex'] ?? null,
            'state' => $validated['state'] ?? 1,
            'department_id' => $validated['department_id'] ?? null,
            // Signing for the head is a department staff authority; other roles sign as themselves.
            'can_sign_for_head' => $validated['role'] === 'staff' && ($validated['can_sign_for_head'] ?? false),
            'signature_path' => $signaturePath, // Save the path to DB
            'password' => Hash::make($validated['password']),
            'email_verified_at' => now(),
        ]);

        return redirect()->back()->with('success', 'User created successfully.');
    }

    public function update(SaveUserRequest $request, User $user)
    {
        $validated = $request->safe()->except(['password', 'signature']);

        if ($request->filled('password')) {
            $user->password = Hash::make($request->validated('password'));
        }

        $user->fill($validated);
        if ($user->role !== 'staff') {
            $user->can_sign_for_head = false;
        }

        // Handle signature upload and replace old file if it exists
        if ($request->hasFile('signature')) {
            if ($user->signature_path && Storage::disk('local')->exists($user->signature_path)) {
                Storage::disk('local')->delete($user->signature_path);
            }
            $user->signature_path = $request->file('signature')->store('signatures', 'local');
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