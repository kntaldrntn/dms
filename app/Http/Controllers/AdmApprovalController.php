<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApproveAdmDocumentRequest;
use App\Http\Requests\ReceiveAdmDocumentRequest;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentTrail;
use App\Services\AdmApprovalService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** FGD Phase 2c: ADM receives, the City Administrator approves once and routes on. */
class AdmApprovalController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAdmApprovals', Document::class);
        $user = $request->user();

        $documents = Document::visibleTo($user)->with(['documentType:id,document_type', 'primaryEntry.receiver:id,name'])
            ->inStage([DocumentTrail::STAGE_ADM_APPROVAL])->where('status', '!=', 'Completed')
            ->latest()->paginate(10)->withQueryString()
            ->through(fn (Document $document) => $document->setAttribute('can_receive', $user->can('receiveAdm', $document))
                ->setAttribute('can_approve', $user->can('approveAdm', $document))
                ->setAttribute('stamp_source_url', $document->stampSourceUrl()));

        return Inertia::render('adm-approvals/Index', [
            'documents' => $documents,
            // ADM routes on to OCM (its own option) or to departments; never back to Records.
            'departments' => Department::where('state', 1)->whereNotIn('code', ['REC', 'ADM', 'OCM'])->orderBy('code')->get(['id', 'code', 'name']),
            'hasCertificate' => $user->hasValidCertificate(),
            'flash' => ['success' => session('success'), 'error' => session('error')],
        ]);
    }

    public function receive(ReceiveAdmDocumentRequest $request, Document $document, AdmApprovalService $approvals)
    {
        $approvals->receive($document, $request->user(), $request->integer('workflow_version'));

        return redirect()->back()->with('success', 'Document received. The City Administrator can now approve it.');
    }

    public function approve(ApproveAdmDocumentRequest $request, Document $document, AdmApprovalService $approvals)
    {
        $approvals->approve($document, $request->user(), $request->validated());

        return redirect()->back()->with('success', $request->input('target') === 'ocm'
            ? 'Approved and sent to the City Mayor.'
            : 'Approved and routed to the departments.');
    }
}
