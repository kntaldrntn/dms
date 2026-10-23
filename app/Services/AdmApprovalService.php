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
 * FGD Phase 2c: an ADM user receives the document, then the City Administrator gives the single approval and
 * routes it on to the City Mayor or straight to the departments. It never returns to Records.
 */
class AdmApprovalService
{
    public function __construct(private DocumentWorkflow $workflow, private PdfApprovalStampService $pdfStamps) {}

    public function receive(Document $document, User $user, int $version): void
    {
        $this->workflow->mutate($document, $user, $version, 'receiveAdm', function (Document $document, User $user) {
            $entry = $this->approvalEntry($document);
            $entry->fill(['received_by' => $user->id, 'received_at' => now('Asia/Manila'), 'received_action' => 'Received by ADM'])->save();
        });
    }

    /**
     * @param  array{workflow_version:int, target:string, routed_to?:list<int>, primary_office_id?:int, remarks?:?string,
     *               signed_scan?:UploadedFile, stamp_placement?:array}  $data
     */
    public function approve(Document $document, User $user, array $data): void
    {
        $signedFile = null;
        try {
            $this->workflow->mutate($document, $user, (int) $data['workflow_version'], 'approveAdm', function (Document $document, User $user) use ($data, &$signedFile) {
                $this->approvalEntry($document);
                $paperless = $document->route_mode === 'paperless';
                if ($paperless && strtolower(pathinfo((string) $document->attached_file, PATHINFO_EXTENSION)) !== 'pdf') {
                    throw ValidationException::withMessages(['stamp_placement' => 'Paperless approval needs the uploaded PDF.']);
                }
                if ($paperless) {
                    $signedFile = $this->pdfStamps->stamp($document, $user, $data['stamp_placement'], PdfApprovalStampService::ADM);
                } else {
                    $signedFile = $data['signed_scan']->store('signed-documents', 'local');
                    if (! $signedFile) {
                        throw new RuntimeException('The signed scan could not be saved.');
                    }
                }

                $mayor = $data['target'] === 'ocm' ? $this->workflow->office('OCM')->id : null;
                $targets = $mayor ? [$mayor] : $this->workflow->targets($data['routed_to'], $document->department_id, 'routed_to');
                $primary = $mayor ?? (int) $data['primary_office_id'];

                // A wet signature is recorded as an approval by the City Administrator, not as a digital signature.
                $this->workflow->release($document, $user, 'Approved by City Administrator', $primary, $data['remarks'] ?? null,
                    $paperless ? $user->id : null, [
                        'trail_type' => 'approval', 'signed_by' => $user->id, 'signed_file_path' => $signedFile,
                        'signature_mode' => $paperless ? 'electronic' : 'physical', 'released_to_offices' => $targets,
                    ]);

                if ($mayor) {
                    $this->workflow->receive($document, $mayor, 'Awaiting City Mayor approval', DocumentTrail::PRIMARY, DocumentTrail::STAGE_OCM_APPROVAL);
                    $document->fill(['department_id' => $mayor, 'current_assignee_id' => null, 'requires_signature_from' => 'city_mayor']);
                } else {
                    $this->workflow->distribute($document, $user, $targets, $primary, 'Routed from ADM', 'Copy from ADM');
                    $document->requires_signature_from = 'none';
                }
                $document->approved_routing_slip = null;
            });
        } catch (Throwable $exception) {
            if ($signedFile) {
                Storage::disk('local')->delete($signedFile);
            }
            throw $exception;
        }
    }

    /** The open ADM entry waiting on approval, locked for this transaction. */
    private function approvalEntry(Document $document): DocumentTrail
    {
        $entry = DocumentTrail::where('document_id', $document->id)->where('copy_role', DocumentTrail::PRIMARY)
            ->whereNull('released_at')->where('stage', DocumentTrail::STAGE_ADM_APPROVAL)->lockForUpdate()->latest('id')->first();
        if (! $entry) {
            throw ValidationException::withMessages(['document' => 'This document is no longer waiting on City Administrator approval. Refresh the page.']);
        }

        return $entry;
    }
}
