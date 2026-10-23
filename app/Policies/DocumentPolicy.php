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

    public function process(User $user, Document $document): bool
    {
        // Documents waiting on a slip, Department Head signature, or OCM approval are acted on
        // from their executive queues, never routed or ended from the Office Inbox.
        return $user->state === 1 && $this->owns($user, $document)
            && ! $document->inExecutiveStage()
            && $user->department()->where('state', 1)->exists();
    }

    /** A copy office records receipt of its view-only copy once; it can never act on the document. */
    public function receiveCopy(User $user, Document $document): bool
    {
        return $user->state === 1 && $user->department_id !== null
            && $user->department()->where('state', 1)->exists()
            && $document->trails()->where('department_id', $user->department_id)->where('copy_role', DocumentTrail::COPY)
                ->whereNull('released_at')->whereNull('received_by')->exists();
    }

    /** Records and the Supervisory Level may correct a wrong Primary office. */
    public function changePrimary(User $user, Document $document): bool
    {
        return $user->state === 1
            && in_array($user->role, [...self::RECORDS_ROLES, ...self::SUPERVISORY_ROLES], true)
            && $user->department()->where('state', 1)->exists()
            && $document->trails()->where('copy_role', DocumentTrail::PRIMARY)->whereNull('released_at')->exists()
            // Moving the Primary office would drop a pending approval without anyone approving it.
            && ! $document->inExecutiveStage();
    }

    public function viewReferrals(User $user): bool
    {
        return ($user->state === 1 && in_array($user->role, ['city_administrator', 'system_administrator'], true)
            && $user->department()->where('state', 1)->exists())
            || $this->isAdmDepartmentHead($user);
    }

    public function viewOcmApprovals(User $user): bool
    {
        return $user->state === 1 && in_array($user->role, ['city_mayor', 'system_administrator'], true)
            && $user->department()->where('state', 1)->exists();
    }

    public function refer(User $user, Document $document): bool
    {
        // A generated slip waits for the ADM Department Head; it cannot be regenerated.
        $stage = $document->stage;
        if ($stage === DocumentTrail::STAGE_ADM_SIGNATURE) {
            return false;
        }

        if ($user->role === 'system_administrator') {
            return in_array($stage, [DocumentTrail::STAGE_ADM_APPROVAL, DocumentTrail::STAGE_OCM_APPROVAL], true)
                || $document->department()->whereIn('code', ['ADM', 'OCM'])->where('state', 1)->exists();
        }

        if ($user->role === 'city_mayor') {
            return $this->viewOcmApprovals($user)
                && ($stage === DocumentTrail::STAGE_OCM_APPROVAL
                    || $document->department()->where('code', 'OCM')->where('state', 1)->exists());
        }

        if ($user->role !== 'city_administrator' || ! $this->viewReferrals($user)) {
            return false;
        }

        return $stage === DocumentTrail::STAGE_ADM_APPROVAL
            || $document->department()->where('code', 'ADM')->where('state', 1)->exists();
    }

    /** Step 2 of the ADM referral: only the ADM Department Head (or a system administrator) signs. */
    public function signReferral(User $user, Document $document): bool
    {
        return $document->stage === DocumentTrail::STAGE_ADM_SIGNATURE
            && $document->department()->where('code', 'ADM')->where('state', 1)->exists()
            && ($this->isAdmDepartmentHead($user)
                || ($user->state === 1 && $user->role === 'system_administrator'));
    }

    private function isAdmDepartmentHead(User $user): bool
    {
        return $user->state === 1 && $user->role === 'department_head'
            && $user->department()->where('code', 'ADM')->where('state', 1)->exists();
    }

    public function viewReferralSlip(User $user, Document $document): bool
    {
        if ($this->viewReferrals($user)) {
            return true;
        }

        return $user->state === 1
            && $user->role === 'records_manager'
            && $this->owns($user, $document)
            && !empty($document->approved_routing_slip);
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
