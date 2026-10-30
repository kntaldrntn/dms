<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\DocumentTrail;
use App\Models\User;

class DocumentPolicy
{
    private const RECORDS_ROLES = ['records_manager', 'system_administrator'];

    /** FGD access levels: City Administrator, City Mayor and Department Heads. */
    private const SUPERVISORY_ROLES = ['city_administrator', 'city_mayor', 'department_head'];

    public function viewAny(User $user): bool
    {
        return $user->state === 1;
    }

    public function view(User $user, Document $document): bool
    {
        return $this->viewAny($user) && $document->state === 1
            && Document::visibleTo($user)->whereKey($document->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->state === 1
            && in_array($user->role, ['records_manager', 'system_administrator'], true)
            && $user->department()->where('state', 1)->exists();
    }

    public function update(User $user, Document $document): bool
    {
        return $this->create($user) && $this->owns($user, $document);
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->update($user, $document);
    }

    public function dispatch(User $user, Document $document): bool
    {
        return $this->update($user, $document) && ! $document->inExecutiveStage();
    }

    /**
     * The Primary office acts (route or end) only after it has received the document, like the old DTS:
     * receive first, act later. Documents waiting on ADM/OCM approval are acted on from their approval queues.
     */
    public function process(User $user, Document $document): bool
    {
        if (! $this->actsInInbox($user, $document)) {
            return false;
        }
        $entry = $this->openPrimaryEntry($user, $document);

        // No open entry (legacy rows held by department_id alone): nothing to receive, so it may act.
        return $entry === null || $entry->received_by !== null;
    }

    /** The receiving officer logs that the Primary office has the document; acting on it is a separate step. */
    public function receive(User $user, Document $document): bool
    {
        $entry = $this->actsInInbox($user, $document) ? $this->openPrimaryEntry($user, $document) : null;

        return $entry !== null && $entry->received_by === null;
    }

    private function actsInInbox(User $user, Document $document): bool
    {
        return $user->state === 1 && $this->owns($user, $document)
            && ! $document->inExecutiveStage()
            && $user->department()->where('state', 1)->exists();
    }

    /** This office's open Primary entry. Offices that registered or held a document before trails existed have none. */
    private function openPrimaryEntry(User $user, Document $document): ?DocumentTrail
    {
        return DocumentTrail::where('document_id', $document->id)->where('department_id', $user->department_id)
            ->where('copy_role', DocumentTrail::PRIMARY)->whereNull('released_at')->latest('id')->first();
    }

    /** A copy office records receipt of its view-only copy once; it can never act on the document. */
    public function receiveCopy(User $user, Document $document): bool
    {
        return $user->state === 1 && $user->department_id !== null
            && $user->department()->where('state', 1)->exists()
            && $document->trails()->where('department_id', $user->department_id)->where('copy_role', DocumentTrail::COPY)
                ->whereNull('released_at')->whereNull('received_by')->exists();
    }

    /**
     * FGD (user decision 2026-10-30): Records and the System Administrator may correct any document; the
     * Supervisory Level sees every document but corrects only those endorsed to them (their office holds the
     * Primary copy — for the City Administrator/Mayor, while it waits at ADM/OCM).
     */
    private function corrects(User $user, Document $document): bool
    {
        if ($user->state !== 1 || ! $user->department()->where('state', 1)->exists()) {
            return false;
        }

        return in_array($user->role, self::RECORDS_ROLES, true)
            || (in_array($user->role, self::SUPERVISORY_ROLES, true) && $this->owns($user, $document));
    }

    /** Records and the Supervisory Level (endorsed documents only) may correct a wrong Primary office. */
    public function changePrimary(User $user, Document $document): bool
    {
        return $this->corrects($user, $document)
            && $document->trails()->where('copy_role', DocumentTrail::PRIMARY)->whereNull('released_at')->exists()
            // Moving the Primary office would drop a pending approval without anyone approving it.
            && ! $document->inExecutiveStage();
    }

    /**
     * Only Records, the System Administrator and the Supervisory Level (endorsed documents only) may change a
     * document's category (Simple / Complex / Highly Technical), which moves its due date.
     */
    public function changeCategory(User $user, Document $document): bool
    {
        return $this->corrects($user, $document)
            && $document->state === 1 && $document->status !== 'Completed';
    }

    /** ADM Approvals queue: every active ADM user (they receive), plus system administrators. */
    public function viewAdmApprovals(User $user): bool
    {
        return $user->state === 1 && ($user->role === 'system_administrator'
            ? $user->department()->where('state', 1)->exists()
            : $user->department()->where('code', 'ADM')->where('state', 1)->exists());
    }

    /** FGD Phase 2c step 1: an ADM user receives a document waiting on City Administrator approval. */
    public function receiveAdm(User $user, Document $document): bool
    {
        $entry = $this->approvalEntry($document, DocumentTrail::STAGE_ADM_APPROVAL);

        return $entry !== null && $entry->received_by === null && $this->viewAdmApprovals($user);
    }

    /**
     * Step 2, only after receipt. Paperless: only the City Administrator (or a system administrator) signs with
     * their own certificate. Paper (hybrid, like the old DTS): the City Administrator signs the paper and any ADM
     * user records the approval.
     */
    public function approveAdm(User $user, Document $document): bool
    {
        $entry = $this->approvalEntry($document, DocumentTrail::STAGE_ADM_APPROVAL);
        $isApprover = ! $document->isPaperless() || $user->role === 'system_administrator'
            || ($user->role === 'city_administrator' && $user->department()->where('code', 'ADM')->exists());

        return $entry !== null && $entry->received_by !== null && $isApprover && $this->viewAdmApprovals($user);
    }

    /** The open Primary entry waiting on an executive approval stage (DocumentTrail::STAGE_*). */
    private function approvalEntry(Document $document, string $stage): ?DocumentTrail
    {
        if ($document->state !== 1 || $document->status === 'Completed') {
            return null;
        }

        return DocumentTrail::where('document_id', $document->id)->where('copy_role', DocumentTrail::PRIMARY)
            ->whereNull('released_at')->where('stage', $stage)->latest('id')->first();
    }

    /** Every OCM user (they record the Mayor's paper approvals) and system administrators. */
    public function viewOcmApprovals(User $user): bool
    {
        return $user->state === 1 && ($user->role === 'system_administrator'
            ? $user->department()->where('state', 1)->exists()
            : $user->department()->where('code', 'OCM')->where('state', 1)->exists());
    }

    /** Step 1, like ADM: any OCM user receives a document waiting on City Mayor approval. */
    public function receiveOcm(User $user, Document $document): bool
    {
        $entry = $this->approvalEntry($document, DocumentTrail::STAGE_OCM_APPROVAL);

        return $entry !== null && $entry->received_by === null && $this->viewOcmApprovals($user);
    }

    /**
     * Step 2, only after receipt. Paperless: only the City Mayor (or a system administrator) signs with their own
     * certificate. Paper: the Mayor signs the paper and any OCM user records the approval.
     */
    public function approveOcm(User $user, Document $document): bool
    {
        $entry = $this->approvalEntry($document, DocumentTrail::STAGE_OCM_APPROVAL);
        $isApprover = ! $document->isPaperless() || in_array($user->role, ['city_mayor', 'system_administrator'], true);

        return $entry !== null && $entry->received_by !== null && $isApprover && $this->viewOcmApprovals($user);
    }

    /**
     * Custody belongs to the office holding the open Primary entry; view-only copies never grant it.
     * Documents without any open Primary entry fall back to their recorded current office.
     */
    private function owns(User $user, Document $document): bool
    {
        if ($user->department_id === null) {
            return false;
        }
        $holders = $document->trails()->whereNull('released_at')->where('copy_role', DocumentTrail::PRIMARY)
            ->pluck('department_id')->map(fn ($id) => (int) $id);

        return $holders->isNotEmpty()
            ? $holders->contains((int) $user->department_id)
            : (int) $user->department_id === (int) $document->department_id;
    }
}
