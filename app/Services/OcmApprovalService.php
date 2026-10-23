<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentTrail;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/** The City Mayor approves an OCM document by signing its PDF with their own PNPKI certificate. */
class OcmApprovalService
{
    public function __construct(private DocumentWorkflow $workflow, private PdfApprovalStampService $pdfStamps) {}

    public function approve(Document $document, User $user, array $data): void
    {
        $createdFile = null;
        try {
            $this->workflow->mutate($document, $user, (int) $data['workflow_version'], 'approveOcm', function (Document $document, User $user) use ($data, &$createdFile) {
                if ($document->stage !== DocumentTrail::STAGE_OCM_APPROVAL) {
                    throw ValidationException::withMessages(['document' => 'This document is not awaiting OCM approval.']);
                }
                if (! $document->attached_file || strtolower(pathinfo($document->attached_file, PATHINFO_EXTENSION)) !== 'pdf') {
                    throw ValidationException::withMessages(['attached_file' => 'OCM approval requires an uploaded PDF.']);
                }

                // Returns to Records for dispatch until Phase 3 lets the Mayor route directly to departments.
                $records = $this->workflow->office('REC');
                $createdFile = $this->pdfStamps->stamp($document, $user, $data['stamp_placement'], PdfApprovalStampService::OCM, $data['certificate_pin']);
                $this->workflow->release($document, $user, 'OCM approved and digitally stamped', $records->id, null, $user->id, [
                    'signed_file_path' => $createdFile,
                ]);
                $this->workflow->receive($document, $records->id, 'OCM approved document returned to Records');
                $document->fill(['department_id' => $records->id, 'current_assignee_id' => null]);
            });
        } catch (Throwable $exception) {
            if ($createdFile) {
                Storage::disk('local')->delete($createdFile);
            }
            throw $exception;
        }
    }
}
