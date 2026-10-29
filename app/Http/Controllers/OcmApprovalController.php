<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApproveOcmDocumentRequest;
use App\Http\Requests\ReceiveOcmDocumentRequest;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentTrail;
use App\Services\OcmApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use OpenAI\Laravel\Facades\OpenAI;

/** OCM approval queue, the stamp-placement helper shared with ADM, and the signed-PDF preview. */
class OcmApprovalController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewOcmApprovals', Document::class);
        $user = $request->user();

        $documents = Document::with(['documentType', 'transactionType', 'primaryEntry.receiver:id,name'])
            ->inStage([DocumentTrail::STAGE_OCM_APPROVAL])
            ->where('status', 'Pending')->where('state', 1)->latest()->paginate(10)
            ->through(fn (Document $document) => $document->setAttribute('stamp_source_url', $document->stampSourceUrl())
                ->setAttribute('can_receive', $user->can('receiveOcm', $document))
                ->setAttribute('can_approve', $user->can('approveOcm', $document))
                ->setAttribute('is_paperless', $document->isPaperless()));

        return Inertia::render('ocm-approvals/Index', [
            'documents' => $documents,
            // The Mayor's approval routes straight to departments (FGD Phase 3); never back to Records.
            'departments' => Department::where('state', 1)->whereNotIn('code', ['REC', 'ADM', 'OCM'])->orderBy('code')->get(['id', 'code', 'name']),
            'hasCertificate' => $user->hasValidCertificate(),
            'flash' => ['success' => session('success'), 'error' => session('error')],
        ]);
    }

    public function receive(ReceiveOcmDocumentRequest $request, Document $document, OcmApprovalService $approvals)
    {
        $approvals->receive($document, $request->user(), $request->integer('workflow_version'));

        return redirect()->back()->with('success', 'Document received. Record the City Mayor approval once it is signed.');
    }

    public function approve(ApproveOcmDocumentRequest $request, Document $document, OcmApprovalService $approvals)
    {
        $approvals->approve($document, $request->user(), $request->validated());

        return redirect()->back()->with('success', 'Approved and routed to the departments.');
    }

    public function findStampPlacement(Request $request, Document $document)
    {
        // The City Mayor (OCM) and the City Administrator (ADM, paperless) both stamp approved PDFs.
        $ability = match ($document->stage) {
            DocumentTrail::STAGE_OCM_APPROVAL => 'approveOcm',
            DocumentTrail::STAGE_ADM_APPROVAL => 'approveAdm',
            default => abort(422, 'This document is not waiting on an approval stamp.'),
        };
        $this->authorize($ability, $document);

        $validated = $request->validate([
            'thumbnails' => ['required', 'array', 'min:1', 'max:5'],
            'thumbnails.*' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:4096'],
            'pages' => ['required', 'array', 'min:1', 'max:5'],
            'pages.*.page' => ['required', 'integer', 'min:1', 'max:100'],
        ]);
        abort_unless(count($validated['thumbnails']) === count($validated['pages']), 422, 'Page preview data is incomplete.');

        $content = [[
            'type' => 'text',
            'text' => 'Find one completely blank rectangle for an official\'s signature stamp on these numbered PDF page previews. Return JSON only: {"page":integer,"x":number,"y":number,"width":number,"height":number}. Coordinates are normalized 0-1000 from the top-left. The rectangle must be at least 250 wide and 70 high, entirely inside the page (x + width <= 1000 and y + height <= 1000), and must not overlap any text, handwriting, seal, barcode, table, footer, or existing signature. A large blank margin is acceptable even when it is not labelled as a signature field; prefer the lower half of a later page. Only return {"page":null} when every page has no blank rectangle of this size.',
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
            Log::warning('Approval stamp placement analysis failed.', [
                'document_id' => $document->id,
                'message' => $exception->getMessage(),
            ]);

            return response()->json(['error' => 'AI could not find a safe signature area. Choose another PDF or add a clear signature space.'], 422);
        }
    }

    public function previewSigned(Document $document)
    {
        $this->authorize('view', $document);
        // reorder(): trails() sorts oldest first, and the newest signed version (e.g. OCM after ADM) is the one to show.
        $trail = $document->trails()->reorder()->whereNotNull('signed_file_path')->latest('id')->firstOrFail();
        abort_unless(Storage::disk('local')->exists($trail->signed_file_path), 404);

        return Storage::disk('local')->response(
            $trail->signed_file_path,
            "{$document->barcode}-signed.pdf",
            ['Content-Type' => 'application/pdf'],
            'inline',
        );
    }
}
