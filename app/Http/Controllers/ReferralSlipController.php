<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Department;
use App\Http\Requests\GenerateReferralRequest;
use App\Services\ReferralService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReferralSlipController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewReferrals', Document::class);

        $documents = Document::with(['documentType', 'transactionType'])
            ->where(function ($query) {
                $query->where('requires_signature_from', 'city_administrator')
                    ->orWhereHas('department', fn ($department) => $department->where('code', 'ADM')->where('state', 1));
            })
            ->where('status', 'Pending')
            ->where('state', 1)
            ->latest()
            ->paginate(10);

        return Inertia::render('referral-slips/Index', [
            'documents' => $documents,
            'departments' => Department::where('state', 1)->where('code', '!=', 'ADM')->get(),
            'flash' => [
                'success' => session('success'),
                'error' => session('error')
            ],
        ]);
    }

    public function generate(GenerateReferralRequest $request, Document $document, ReferralService $referrals)
    {
        $referrals->generate($document, $request->user(), $request->validated());

        return redirect()->back()->with('success', 'Routing Slip approved. You can view or print it from the document actions.');
    }

    public function print(Request $request, Document $document)
    {
        $this->authorize('viewReferralSlip', $document);

        $trail = $document->trails()
            ->with(['releaser', 'signer'])
            ->where('released_action', 'Approved Routing Slip Generated')
            ->latest('id')
            ->firstOrFail();

        $departments = Department::where('state', 1)->orderBy('code')->get();

        return view('referral-slips.print', compact('document', 'trail', 'departments'));
    }
}
