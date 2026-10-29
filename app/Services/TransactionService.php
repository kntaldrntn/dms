<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentTrail;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class TransactionService
{
    public function __construct(private DocumentWorkflow $workflow) {}

    /**
     * Only the Primary office acts. Ending the transaction completes the document and closes every
     * view-only copy; routing on makes the next office Primary and may send copies to others.
     */
    public function process(Document $document, User $user, array $data): void
    {
        $signedFile = null;
        try {
            $this->workflow->mutate($document, $user, null, 'process', function (Document $document, User $user) use ($data, &$signedFile) {
                $office = (int) $user->department_id;
                if ($this->workflow->primaryOffice($document) !== $office) {
                    throw ValidationException::withMessages(['document' => 'Your office no longer holds the Primary copy of this document. Refresh the page.']);
                }
                $signature = $this->headSignature($user, $data, $signedFile);

                if ($data['action'] === 'end') {
                    $this->workflow->release($document, $user, 'End Transaction', null, $data['remarks'], null, $signature, $office);
                    $this->workflow->closeCopies($document, $user, 'Closed with document');
                    $document->fill(['status' => 'Completed', 'completed_at' => now('Asia/Manila'), 'current_assignee_id' => null]);

                    return;
                }

                $targets = $this->workflow->targets((array) $data['routed_to'], $office, 'routed_to');
                $primary = (int) ($data['primary_office_id'] ?? $targets[0]);
                $this->workflow->release($document, $user, 'Routed', $primary, $data['remarks'], null, ['released_to_offices' => $targets, ...$signature], $office);
                $from = $user->department?->code ?? 'Previous Office';
                $this->workflow->distribute($document, $user, $targets, $primary, 'Routed from '.$from, 'Copy from '.$from);
            });
        } catch (Throwable $exception) {
            if ($signedFile) {
                Storage::disk('local')->delete($signedFile);
            }
            throw $exception;
        }
    }

    /**
     * Paper route: the Department Head's wet signature is recorded on the release (signer + "physical"), with an
     * optional scan of the signed page. No e-signature image is applied.
     */
    private function headSignature(User $user, array $data, ?string &$signedFile): array
    {
        if (! ($data['head_signed'] ?? false)) {
            return [];
        }
        $signature = ['trail_type' => 'approval', 'signed_by' => $this->workflow->departmentHeadSigner($user), 'signature_mode' => 'physical'];
        if (($data['signed_scan'] ?? null) instanceof UploadedFile) {
            $signedFile = $data['signed_scan']->store('signed-documents', 'local');
            if (! $signedFile) {
                throw new RuntimeException('The signed scan could not be saved.');
            }
            $signature['signed_file_path'] = $signedFile;
        }

        return $signature;
    }

    /** The Primary office logs receipt (who and when); routing or ending it is a later, separate step. */
    public function receive(Document $document, User $user): void
    {
        $this->workflow->mutate($document, $user, null, 'receive', function (Document $document, User $user) {
            $entry = DocumentTrail::where('document_id', $document->id)->where('department_id', $user->department_id)
                ->where('copy_role', DocumentTrail::PRIMARY)->whereNull('released_at')->whereNull('received_by')
                ->lockForUpdate()->latest('id')->first();
            if (! $entry) {
                throw ValidationException::withMessages(['document' => 'Your office has already received this document. Refresh the page.']);
            }
            // received_action keeps how it arrived (e.g. "Routed from ADM"); received_at becomes the actual receipt time.
            $entry->fill(['received_by' => $user->id, 'received_at' => now('Asia/Manila')])->save();
        });
    }

    /** A copy office records that it received its view-only copy; the copy stays open until the document closes. */
    public function receiveCopy(Document $document, User $user): void
    {
        $this->workflow->mutate($document, $user, null, 'receiveCopy', function (Document $document, User $user) {
            $copy = DocumentTrail::where('document_id', $document->id)->where('department_id', $user->department_id)
                ->where('copy_role', DocumentTrail::COPY)->whereNull('released_at')->whereNull('received_by')->lockForUpdate()->first();
            if (! $copy) {
                throw ValidationException::withMessages(['document' => 'Your office has already received this copy. Refresh the page.']);
            }
            $copy->fill(['received_by' => $user->id, 'received_at' => now('Asia/Manila'), 'received_action' => 'Copy received'])->save();
        });
    }

    /**
     * Correct a wrong Primary office. The old Primary keeps a view-only copy; both hand-offs are new trail
     * entries so the history shows who changed it and when.
     */
    public function changePrimary(Document $document, User $user, int $newPrimary): void
    {
        $this->workflow->mutate($document, $user, null, 'changePrimary', function (Document $document, User $user) use ($newPrimary) {
            $current = $this->workflow->primaryOffice($document);
            if ($current === null) {
                throw ValidationException::withMessages(['primary_office_id' => 'This document has no Primary office to change.']);
            }
            $newPrimary = $this->workflow->targets([$newPrimary], $current, 'primary_office_id')[0];
            $code = Department::whereKey($newPrimary)->value('code');
            $this->workflow->release($document, $user, "Primary changed to {$code}", $newPrimary, null, null, [], $current);
            // Confidential documents stay with one office only, so the old Primary keeps no copy.
            if (! $document->is_confidential) {
                $this->workflow->receive($document, $current, 'Kept as copy after Primary change', DocumentTrail::COPY);
            }
            $this->workflow->closeCopies($document, $user, 'Copy closed: office made Primary', [$newPrimary]);
            $this->workflow->receive($document, $newPrimary, 'Made Primary office');
            $document->fill(['department_id' => $newPrimary, 'current_assignee_id' => null]);
        });
    }
}
