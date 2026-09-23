<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Document;
use App\Models\DocumentTrail;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class ReferralSlipController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();

        if (!in_array($user->role, ['city_administrator', 'system_administrator'])) {
            abort(403, 'Unauthorized. Only the City Administrator can generate Referral Slips.');
        }

        $documents = Document::with(['documentType', 'transactionType'])
            ->where('department_id', 3)
            ->where('status', 'Pending')
            ->where('state', 1)
            ->latest()
            ->paginate(10);

        return Inertia::render('referral-slips/Index', [
            'documents' => $documents,
            'departments' => Department::where('state', 1)->where('id', '!=', 3)->get(),
            'flash' => [
                'success' => session('success'),
                'error' => session('error')
            ],
        ]);
    }

    public function generate(Request $request, Document $document)
    {
        $user = Auth::user();

        if (!in_array($user->role, ['city_administrator', 'system_administrator'])) {
            abort(403, 'Unauthorized. Only the City Administrator can generate Referral Slips.');
        }

        if ($document->department_id !== 3) {
            return redirect()->back()->with('error', 'Document is not at the City Administrator office.');
        }

        $validated = $request->validate([
            'targets' => 'required|array|min:1',
            'targets.*' => 'exists:departments,id',
            'remarks' => 'nullable|string',
            'attach_signature' => 'boolean',
        ]);

        $now = Carbon::now('Asia/Manila');

        $activeTrail = DocumentTrail::where('document_id', $document->id)
            ->where('department_id', 3)
            ->whereNull('released_at')
            ->latest('id')
            ->first();

        // Strictly return physical custody to the Records Office (43) for dispatch
        $recordsDeptId = 43;

        $isSigned = false;
        $signedBy = null;

        if ($validated['attach_signature'] ?? false) {
            if (!$user->signature_path) {
                return back()->with('error', 'You must upload your digital signature in your profile first.');
            }
            $isSigned = true;
            $signedBy = $user->id;
        }

        if ($activeTrail) {
            $activeTrail->update([
                'released_by' => $user->id,
                'released_at' => $now,
                'released_action' => 'Approved Routing Slip Generated',
                'remarks' => $validated['remarks'],
                'released_to' => $recordsDeptId, 
                'trail_type' => $isSigned ? 'approval' : 'forward',
                'is_digitally_signed' => $isSigned,
                'signed_by' => $signedBy,
            ]);
        }

        DocumentTrail::create([
            'document_id' => $document->id,
            'department_id' => $recordsDeptId, 
            'received_at' => $now,
            'received_action' => 'Routing Slip returned for dispatch',
        ]);

        $document->update([
            'approved_routing_slip' => $validated['targets'], 
            'department_id' => $recordsDeptId, 
        ]);

        return redirect()->back()->with('success', 'Routing Slip approved. Document returned to Records for physical dispatch.');
    }
}