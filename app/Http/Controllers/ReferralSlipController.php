<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Department;
use App\Http\Requests\GenerateReferralRequest;
use App\Services\ReferralService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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

        return view('referral-slips.print', $this->slipData($document));
    }

    public function preview(Request $request, Document $document)
    {
        $this->authorize('viewReferralSlip', $document);

        return Pdf::loadView('referral-slips.print', $this->slipData($document, true))
            ->setPaper('a4')
            ->stream("referral-action-slip-{$document->barcode}.pdf");
    }

    private function slipData(Document $document, bool $forPdf = false): array
    {

        $trail = $document->trails()
            ->with(['releaser', 'signer'])
            ->where('released_action', 'Approved Routing Slip Generated')
            ->latest('id')
            ->firstOrFail();

        $departments = Department::where('state', 1)->orderBy('code')->get();
        $signatureUser = $trail->signer ?? $trail->releaser;
        $signatureUrl = null;

        if ($signatureUser?->role === 'city_administrator' && $signatureUser->signature_path && Storage::disk('public')->exists($signatureUser->signature_path)) {
            $signatureUrl = $forPdf
                ? $this->imageDataUri(Storage::disk('public')->path($signatureUser->signature_path))
                : Storage::disk('public')->url($signatureUser->signature_path);
        }

        $logoUrl = null;
        $logoPath = public_path('images/san_fernando_logo.png');
        if (file_exists($logoPath)) {
            $logoUrl = $forPdf ? $this->imageDataUri($logoPath) : url('images/san_fernando_logo.png');
        }

        return compact('document', 'trail', 'departments', 'signatureUrl', 'logoUrl', 'forPdf');
    }

    private function imageDataUri(string $path): string
    {
        $mimeType = mime_content_type($path) ?: 'image/png';

        return 'data:'.$mimeType.';base64,'.base64_encode(file_get_contents($path));
    }
}
