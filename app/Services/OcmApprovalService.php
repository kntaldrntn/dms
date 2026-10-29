<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentTrail;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * OCM receives the document, then the City Mayor's approval is recorded and it goes straight to the departments (FGD Phase 3; never back to Records).
 * Paperless: the Mayor signs the PDF with their own PNPKI certificate. Paper (hybrid, like the old DTS): the Mayor
 * signs the paper and any OCM user records the approval; the scan of the signed copy is optional.
 */
class OcmApprovalService
{
    public function __construct(private DocumentWorkflow $workflow, private PdfApprovalStampService $pdfStamps) {}

    /** OCM logs that the paper copy arrived before the Mayor's approval is recorded. */
    public function receive(Document $document, User $user, int $version): void
    {
        $this->workflow->mutate($document, $user, $version, 'receiveOcm', function (Document $document, User $user) {
            $entry = DocumentTrail::where('document_id', $document->id)->where('copy_role', DocumentTrail::PRIMARY)
                ->whereNull('released_at')->where('stage', DocumentTrail::STAGE_OCM_APPROVAL)->lockForUpdate()->latest('id')->first();
            if (! $entry) {
                throw ValidationException::withMessages(['document' => 'This document is no longer waiting on City Mayor approval. Refresh the page.']);
            }
            $entry->fill(['received_by' => $user->id, 'received_at' => now('Asia/Manila'), 'received_action' => 'Received by OCM'])->save();
        });
    }

    /**
     * @param  array{workflow_version:int, routed_to:list<int>, primary_office_id:int, remarks?:?string,
     *               signed_scan?:UploadedFile, stamp_placement?:array, certificate_pin?:string}  $data
     */
    public function approve(Document $document, User $user, array $data): void
    {
        $signedFile = null;
        try {
            $this->workflow->mutate($document, $user, (int) $data['workflow_version'], 'approveOcm', function (Document $document, User $user) use ($data, &$signedFile) {
                if ($document->stage !== DocumentTrail::STAGE_OCM_APPROVAL) {
                    throw ValidationException::withMessages(['document' => 'This document is not awaiting OCM approval.']);
                }
                $paperless = $document->isPaperless();
                if ($paperless) {
                    if (! $document->attached_file || strtolower(pathinfo($document->attached_file, PATHINFO_EXTENSION)) !== 'pdf') {
                        throw ValidationException::withMessages(['attached_file' => 'Paperless OCM approval requires an uploaded PDF.']);
                    }
                    $signedFile = $this->pdfStamps->stamp($document, $user, $data['stamp_placement'], PdfApprovalStampService::OCM, $data['certificate_pin']);
                } elseif (($data['signed_scan'] ?? null) instanceof UploadedFile) {
                    $signedFile = $data['signed_scan']->store('signed-documents', 'local');
                    if (! $signedFile) {
                        throw new RuntimeException('The signed scan could not be saved.');
                    }
                }

                $targets = $this->workflow->targets($data['routed_to'], $document->department_id, 'routed_to');
                $primary = (int) $data['primary_office_id'];
                // signed_by names the Mayor only when they act themselves; OCM staff recording it appear as the releaser.
                $this->workflow->release($document, $user, 'Approved by City Mayor', $primary, $data['remarks'] ?? null,
                    $paperless ? $user->id : null, [
                        'trail_type' => 'approval', 'signed_by' => $paperless || $user->role === 'city_mayor' ? $user->id : null,
                        'signed_file_path' => $signedFile, 'signature_mode' => $paperless ? 'electronic' : 'physical',
                        'released_to_offices' => $targets,
                    ]);
                $this->workflow->distribute($document, $user, $targets, $primary, 'Routed from OCM', 'Copy from OCM');
            });
        } catch (Throwable $exception) {
            if ($signedFile) {
                Storage::disk('local')->delete($signedFile);
            }
            throw $exception;
        }
    }
}
