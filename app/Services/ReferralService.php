<?php

namespace App\Services;

use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ReferralService
{
    public function __construct(private DocumentWorkflow $workflow, private PdfApprovalStampService $pdfStamps) {}

    public function generate(Document $document, User $user, array $data): void
    {
        $createdFile = null;
        try {
            $this->workflow->mutate($document, $user, (int) $data['workflow_version'], 'refer', function (Document $document, User $user) use ($data, &$createdFile) {
            $targets = $this->workflow->targets($data['targets'], $document->department_id, 'targets');
            $records = $this->workflow->office('REC');
            $signer = $this->workflow->signer($user, (bool) ($data['attach_signature'] ?? false));
            $requiresOcmStamp = $document->requires_signature_from === 'city_mayor'
                && (bool) ($data['attach_signature'] ?? false);

            if ($requiresOcmStamp) {
                if (! $document->attached_file || strtolower(pathinfo($document->attached_file, PATHINFO_EXTENSION)) !== 'pdf') {
                    throw ValidationException::withMessages(['attached_file' => 'OCM approval/signature requires an uploaded PDF.']);
                }
                if (empty($data['stamp_placement'])) {
                    throw ValidationException::withMessages(['stamp_placement' => 'AI must find a blank signature area before OCM approval.']);
                }
                $createdFile = $this->pdfStamps->stamp($document, $user, $data['stamp_placement']);
            }
            $this->workflow->release($document, $user, 'Approved Routing Slip Generated', $records->id, $data['remarks'] ?? null, $signer, [
                'required_actions' => $data['required_actions'],
                'other_action' => $data['other_action'] ?? null,
                'concerned_personnel' => $data['concerned_personnel'] ?? null,
                'signed_file_path' => $createdFile,
            ]);
            $this->workflow->receive($document, $records->id, 'Routing Slip returned for dispatch');
            $document->fill([
                'approved_routing_slip' => $targets, 'department_id' => $records->id,
                'requires_signature_from' => 'none', 'current_assignee_id' => null,
            ]);
            });
        } catch (\Throwable $exception) {
            if ($createdFile) {
                Storage::disk('local')->delete($createdFile);
            }
            throw $exception;
        }
    }

    /** Approve an OCM document without creating a referral/action slip. */
    public function approveOcm(Document $document, User $user, array $data): void
    {
        $createdFile = null;
        try {
            $this->workflow->mutate($document, $user, (int) $data['workflow_version'], 'refer', function (Document $document, User $user) use ($data, &$createdFile) {
                if ($document->requires_signature_from !== 'city_mayor') {
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
            if ($createdFile) Storage::disk('local')->delete($createdFile);
            throw $exception;
        }
    }
}
