<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
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
        return $this->update($user, $document)
            && ! in_array($document->requires_signature_from, ['city_administrator', 'department_head', 'city_mayor'], true);
    }

    public function process(User $user, Document $document): bool
    {
        return $user->state === 1 && $this->owns($user, $document)
            && $user->department()->where('state', 1)->exists();
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
        if ($document->requires_signature_from === 'department_head') {
            return false;
        }

        if ($user->role === 'system_administrator') {
            return in_array($document->requires_signature_from, ['city_administrator', 'city_mayor'], true)
                || $document->department()->whereIn('code', ['ADM', 'OCM'])->where('state', 1)->exists();
        }

        if ($user->role === 'city_mayor') {
            return $this->viewOcmApprovals($user)
                && ($document->requires_signature_from === 'city_mayor'
                    || $document->department()->where('code', 'OCM')->where('state', 1)->exists());
        }

        if ($user->role !== 'city_administrator' || ! $this->viewReferrals($user)) {
            return false;
        }

        return $document->requires_signature_from === 'city_administrator'
            || $document->department()->where('code', 'ADM')->where('state', 1)->exists();
    }

    /** Step 2 of the ADM referral: only the ADM Department Head (or a system administrator) signs. */
    public function signReferral(User $user, Document $document): bool
    {
        return $document->requires_signature_from === 'department_head'
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

    private function owns(User $user, Document $document): bool
    {
        return $user->department_id !== null && (int) $user->department_id === (int) $document->department_id;
    }
}
