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
            && $document->requires_signature_from !== 'city_administrator';
    }

    public function process(User $user, Document $document): bool
    {
        return $user->state === 1 && $this->owns($user, $document)
            && $user->department()->where('state', 1)->exists();
    }

    public function viewReferrals(User $user): bool
    {
        return $user->state === 1 && in_array($user->role, ['city_administrator', 'system_administrator'], true)
            && $user->department()->where('state', 1)->exists();
    }

    public function refer(User $user, Document $document): bool
    {
        return $this->viewReferrals($user)
            && (
                $document->requires_signature_from === 'city_administrator'
                || $document->department()->where('code', 'ADM')->where('state', 1)->exists()
            );
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
