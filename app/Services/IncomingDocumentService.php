<?php

namespace App\Services;

use App\Models\DocumentTrail;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * "Incoming" documents for the notification bell: entries that arrived at the user's office
 * (open trail entry) that nobody in the office has received yet. received_by is set when the
 * Primary office clicks Receive (or releases its entry), or when a copy office receives its view-only copy.
 * ADM/OCM approval pings twice: every user of that office until one receives the document, then the executive who signs it.
 */
class IncomingDocumentService
{
    /** @return array{count: int, items: list<array<string, mixed>>} */
    public function forUser(?User $user): array
    {
        if (! $user || $user->state !== 1 || ! $user->department_id) {
            return ['count' => 0, 'items' => []];
        }

        // Unreceived entries ping the whole office, staged ones too (any ADM/OCM user receives them). Once received,
        // a staged entry pings the executive who signs it; the office filter keeps each ping inside its own office.
        $signsStages = array_keys(array_filter([
            DocumentTrail::STAGE_ADM_APPROVAL => in_array($user->role, ['city_administrator', 'system_administrator'], true),
            DocumentTrail::STAGE_OCM_APPROVAL => in_array($user->role, ['city_mayor', 'system_administrator'], true),
        ]));
        $query = DocumentTrail::query()
            ->where('department_id', $user->department_id)
            ->whereNull('released_at')
            ->where(fn ($trails) => $trails->whereNull('received_by')
                ->when($signsStages, fn ($trails) => $trails->orWhere(fn ($received) => $received->whereNotNull('received_by')
                    ->whereIn('stage', $signsStages))))
            ->whereHas('document', fn ($documents) => $documents->where('state', 1)->where('status', '!=', 'Completed'));

        $items = (clone $query)
            ->with('document:id,barcode,subject_matter,is_confidential')
            ->latest('received_at')->latest('id')->take(5)->get()
            ->map(fn (DocumentTrail $trail) => [
                'id' => $trail->id,
                'barcode' => $trail->document->barcode,
                'subject_matter' => $trail->document->detailsHiddenFrom($user)
                    ? 'Confidential document'
                    : Str::limit((string) $trail->document->subject_matter, 80),
                'action' => match ($trail->received_by ? $trail->stage : null) {
                    DocumentTrail::STAGE_ADM_APPROVAL => 'Ready for City Administrator approval',
                    DocumentTrail::STAGE_OCM_APPROVAL => 'Ready for City Mayor approval',
                    default => $trail->received_action,
                },
                'received_at' => $trail->received_at,
                'url' => $trail->copy_role === DocumentTrail::COPY
                    ? route('transactions.inbox', ['copy' => $trail->document_id])
                    : $this->actionUrl($trail),
            ])->all();

        return ['count' => $query->count(), 'items' => $items];
    }

    /** The page where the receiving office acts on this document. */
    private function actionUrl(DocumentTrail $trail): string
    {
        $document = $trail->document;

        return match ($trail->stage) {
            DocumentTrail::STAGE_ADM_APPROVAL => route('adm-approvals.index'),
            DocumentTrail::STAGE_OCM_APPROVAL => route('ocm-approvals.index'),
            default => route('transactions.inbox', ['document' => $document->id]),
        };
    }
}
