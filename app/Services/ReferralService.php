<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentTrail;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ReferralService
{
    public function __construct(private DocumentWorkflow $workflow, private PdfApprovalStampService $pdfStamps) {}

    public const SLIP_GENERATED = 'Referral Slip Generated';

    /** Released-action labels of slip-generation trails, including the pre-2026-10-13 one-step flow. */
    public const SLIP_GENERATED_ACTIONS = [self::SLIP_GENERATED, 'Approved Routing Slip Generated'];

    /**
     * Step 1 of the City Administrator referral: generate the slip only.
     * The document stays in ADM and waits for the ADM Department Head's signature decision.
     */
    public function generate(Document $document, User $user, array $data): void
    {
        $this->workflow->mutate($document, $user, (int) $data['workflow_version'], 'refer', function (Document $document, User $user) use ($data) {
            if ($document->stage === DocumentTrail::STAGE_OCM_APPROVAL) {
                throw ValidationException::withMessages(['document' => 'OCM documents are approved from the OCM Approvals queue.']);
            }
            $admin = $this->workflow->office('ADM');
            $targets = $this->workflow->targets($data['targets'], $admin->id, 'targets');

            // Documents registered before custody moved to ADM at intake are still held by Records;
            // releasing to ADM keeps their trail truthful either way.
            $this->workflow->release($document, $user, self::SLIP_GENERATED, $admin->id, $data['remarks'] ?? null, null, [
                'required_actions' => $data['required_actions'],
                'other_action' => $data['other_action'] ?? null,
                'concerned_personnel' => $data['concerned_personnel'] ?? null,
            ]);
            $this->workflow->receive($document, $admin->id, 'Awaiting Department Head signature', DocumentTrail::PRIMARY, DocumentTrail::STAGE_ADM_SIGNATURE);
            $document->fill([
                'approved_routing_slip' => $targets, 'department_id' => $admin->id,
                'requires_signature_from' => 'department_head', 'current_assignee_id' => null,
            ]);
        });
    }

    /**
     * Step 2: the ADM Department Head chooses E-Signature or Physical Signature,
     * then the document returns to Records for dispatch.
     */
    public function sign(Document $document, User $user, array $data): void
    {
        $this->workflow->mutate($document, $user, (int) $data['workflow_version'], 'signReferral', function (Document $document, User $user) use ($data) {
            $electronic = $data['signature_mode'] === 'electronic';
            if ($electronic && ! $user->signature_path) {
                throw ValidationException::withMessages(['signature_mode' => 'Upload your digital signature first, or choose Physical Signature.']);
            }
            $records = $this->workflow->office('REC');
            $label = $electronic ? 'Referral Slip Signed (E-Signature)' : 'Referral Slip Signed (Physical Signature)';

            $this->workflow->release($document, $user, $label, $records->id, null, null, [
                'trail_type' => 'approval',
                'is_digitally_signed' => $electronic,
                'signed_by' => $user->id,
                'signature_mode' => $data['signature_mode'],
            ]);
            $this->workflow->receive($document, $records->id, 'Signed Referral Slip returned for dispatch');
            $document->fill(['department_id' => $records->id, 'requires_signature_from' => 'none', 'current_assignee_id' => null]);
        });
    }

    /** Approve an OCM document without creating a referral/action slip. */
    public function approveOcm(Document $document, User $user, array $data): void
    {
        $createdFile = null;
        try {
            $this->workflow->mutate($document, $user, (int) $data['workflow_version'], 'refer', function (Document $document, User $user) use ($data, &$createdFile) {
                if ($document->stage !== DocumentTrail::STAGE_OCM_APPROVAL) {
                    throw ValidationException::withMessages(['document' => 'This document is not awaiting OCM approval.']);
                }
                if (! $document->attached_file || strtolower(pathinfo($document->attached_file, PATHINFO_EXTENSION)) !== 'pdf') {
                    throw ValidationException::withMessages(['attached_file' => 'OCM approval requires an uploaded PDF.']);
                }

                $records = $this->workflow->office('REC');
                $createdFile = $this->pdfStamps->stamp($document, $user, $data['stamp_placement']);
                $this->workflow->release($document, $user, 'OCM approved and digitally stamped', $records->id, null, $user->id, [
                    'signed_file_path' => $createdFile,
                ]);
                $this->workflow->receive($document, $records->id, 'OCM approved document returned to Records');
                $document->fill(['department_id' => $records->id, 'requires_signature_from' => 'none', 'current_assignee_id' => null]);
            });
        } catch (\Throwable $exception) {
            if ($createdFile) {
                Storage::disk('local')->delete($createdFile);
            }
            throw $exception;
        }
    }
}
