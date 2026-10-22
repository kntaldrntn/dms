<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentTrail;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class TransactionService
{
    public function __construct(private DocumentWorkflow $workflow) {}

    /**
     * Only the Primary office acts. Ending the transaction completes the document and closes every
     * view-only copy; routing on makes the next office Primary and may send copies to others.
     */
    public function process(Document $document, User $user, array $data): void
    {
        $this->workflow->mutate($document, $user, null, 'process', function (Document $document, User $user) use ($data) {
            $office = (int) $user->department_id;
            if ($this->workflow->primaryOffice($document) !== $office) {
                throw ValidationException::withMessages(['document' => 'Your office no longer holds the Primary copy of this document. Refresh the page.']);
            }
            $signer = $this->workflow->signer($user, (bool) ($data['attach_signature'] ?? false), true);

            if ($data['action'] === 'end') {
                $this->workflow->release($document, $user, 'End Transaction', null, $data['remarks'], $signer, [], $office);
                $this->workflow->closeCopies($document, $user, 'Closed with document');
                $document->fill(['status' => 'Completed', 'completed_at' => now('Asia/Manila'), 'current_assignee_id' => null]);

                return;
            }

            $targets = $this->workflow->targets((array) $data['routed_to'], $office, 'routed_to');
            $primary = (int) ($data['primary_office_id'] ?? $targets[0]);
            $this->workflow->release($document, $user, 'Routed', $primary, $data['remarks'], $signer, ['released_to_offices' => $targets], $office);
            $from = $user->department?->code ?? 'Previous Office';
            $this->workflow->distribute($document, $user, $targets, $primary, 'Routed from '.$from, 'Copy from '.$from);
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
