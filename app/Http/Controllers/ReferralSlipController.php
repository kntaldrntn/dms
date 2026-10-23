<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Department;
use App\Models\DocumentTrail;
use App\Http\Requests\GenerateReferralRequest;
use App\Http\Requests\ApproveOcmDocumentRequest;
use App\Http\Requests\SignReferralRequest;
use App\Services\ReferralService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use OpenAI\Laravel\Facades\OpenAI;

class ReferralSlipController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewReferrals', Document::class);

        // City Administrator generates slips; the ADM Department Head signs them; admins see both stages.
        $stages = match ($request->user()->role) {
            'city_administrator' => [DocumentTrail::STAGE_ADM_APPROVAL],
            'department_head' => [DocumentTrail::STAGE_ADM_SIGNATURE],
            default => [DocumentTrail::STAGE_ADM_APPROVAL, DocumentTrail::STAGE_ADM_SIGNATURE],
        };

        $documents = Document::with(['documentType', 'transactionType', 'primaryEntry'])
            ->inStage($stages)
            ->where('status', 'Pending')->where('state', 1)->latest()->paginate(10);

        return Inertia::render('referral-slips/Index', [
            'documents' => $documents,
            'departments' => Department::where('state', 1)->where('code', '!=', 'ADM')->get(),
            'flash' => [
                'success' => session('success'),
                'error' => session('error')
            ],
        ]);
    }

    public function ocmIndex(Request $request)
    {
        $this->authorize('viewOcmApprovals', Document::class);

        $documents = Document::with(['documentType', 'transactionType', 'primaryEntry'])
            ->inStage([DocumentTrail::STAGE_OCM_APPROVAL])
            ->where('status', 'Pending')->where('state', 1)->latest()->paginate(10);

        return Inertia::render('ocm-approvals/Index', [
            'documents' => $documents,
            'departments' => Department::where('state', 1)->where('code', '!=', 'OCM')->get(),
            'flash' => ['success' => session('success'), 'error' => session('error')],
        ]);
    }

    public function approveOcm(ApproveOcmDocumentRequest $request, Document $document, ReferralService $referrals)
    {
        $referrals->approveOcm($document, $request->user(), $request->validated());

        return response()->json(['message' => 'OCM approval completed.']);
    }

    public function generate(GenerateReferralRequest $request, Document $document, ReferralService $referrals)
    {
        $referrals->generate($document, $request->user(), $request->validated());

        return redirect()->back()->with('success', 'Referral Slip generated and forwarded to the Department Head for signature.');
    }

    public function sign(SignReferralRequest $request, Document $document, ReferralService $referrals)
    {
        $referrals->sign($document, $request->user(), $request->validated());

        return redirect()->back()->with('success', 'Referral Slip signed and returned to Records for dispatch.');
    }

    public function findStampPlacement(Request $request, Document $document)
    {
        $this->authorize('refer', $document);
        abort_unless($document->stage === DocumentTrail::STAGE_OCM_APPROVAL, 422, 'Only OCM approval uses the certificate stamp.');

        $validated = $request->validate([
            'thumbnails' => ['required', 'array', 'min:1', 'max:5'],
            'thumbnails.*' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:4096'],
            'pages' => ['required', 'array', 'min:1', 'max:5'],
            'pages.*.page' => ['required', 'integer', 'min:1', 'max:100'],
        ]);
        abort_unless(count($validated['thumbnails']) === count($validated['pages']), 422, 'Page preview data is incomplete.');

        $content = [[
            'type' => 'text',
            'text' => 'Find one completely blank rectangle for a City Mayor digital-signature image on these numbered PDF page previews. Return JSON only: {"page":integer,"x":number,"y":number,"width":number,"height":number}. Coordinates are normalized 0-1000 from the top-left. The rectangle must be at least 250 wide and 70 high, entirely inside the page (x + width <= 1000 and y + height <= 1000), and must not overlap any text, handwriting, seal, barcode, table, footer, or existing signature. A large blank margin is acceptable even when it is not labelled as a signature field; prefer the lower half of a later page. Only return {"page":null} when every page has no blank rectangle of this size.',
        ]];
        foreach ($validated['thumbnails'] as $index => $thumbnail) {
            $content[] = ['type' => 'text', 'text' => 'PDF page '.$validated['pages'][$index]['page']];
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$thumbnail->getMimeType().';base64,'.base64_encode(file_get_contents($thumbnail->path())), 'detail' => 'high']];
        }

        try {
            $response = OpenAI::chat()->create([
                'model' => 'gpt-4o-mini', 'response_format' => ['type' => 'json_object'],
                'messages' => [['role' => 'user', 'content' => $content]], 'temperature' => 0,
            ]);
            $placement = json_decode($response->choices[0]->message->content, true, 512, JSON_THROW_ON_ERROR);
            $allowedPages = collect($validated['pages'])->pluck('page')->map(fn ($page) => (int) $page)->all();
            if (! is_array($placement) || ! in_array((int) ($placement['page'] ?? 0), $allowedPages, true)) {
                throw new \UnexpectedValueException('No suitable blank area was found.');
            }
            validator(['stamp_placement' => $placement], ApproveOcmDocumentRequest::placementRules())
                ->after(fn ($validator) => ApproveOcmDocumentRequest::ensureFitsOnPage($validator))
                ->validate();

            return response()->json(['placement' => $placement]);
        } catch (\Throwable $exception) {
            Log::warning('OCM stamp placement analysis failed.', [
                'document_id' => $document->id,
                'message' => $exception->getMessage(),
            ]);

            return response()->json(['error' => 'AI could not find a safe signature area. Choose another PDF or add a clear signature space.'], 422);
        }
    }

    public function previewSigned(Document $document)
    {
        $this->authorize('view', $document);
        $trail = $document->trails()->whereNotNull('signed_file_path')->latest('id')->firstOrFail();
        abort_unless(Storage::disk('local')->exists($trail->signed_file_path), 404);

        return Storage::disk('local')->response(
            $trail->signed_file_path,
            "{$document->barcode}-ocm-approved.pdf",
            ['Content-Type' => 'application/pdf'],
            'inline',
        );
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
            ->whereIn('released_action', ReferralService::SLIP_GENERATED_ACTIONS)
            ->latest('id')
            ->firstOrFail();

        $departments = Department::where('state', 1)->orderBy('code')->get();

        // The Department Head's signing step decides the signature; Physical Signature leaves the line blank.
        // Legacy one-step slips carried the City Administrator's signature on the generation trail itself.
        $signingTrail = $document->trails()->with('signer')
            ->whereNotNull('signature_mode')->where('id', '>', $trail->id)->latest('id')->first();
        $signatureUser = $signingTrail
            ? ($signingTrail->signature_mode === 'electronic' ? $signingTrail->signer : null)
            : ($trail->is_digitally_signed ? $trail->signer : null);
        $signatureUrl = null;

        if ($signatureUser?->signature_path && Storage::disk('public')->exists($signatureUser->signature_path)) {
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
