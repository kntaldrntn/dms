<?php

namespace App\Services;

use App\Models\DocumentTrail;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * "Incoming" documents for the notification bell: entries that arrived at the user's office
 * (open trail entry) that nobody in the office has acted on yet. received_by is set when the
 * Primary office releases its entry, or when a copy office receives its view-only copy.
 * Pings for executive steps go only to the role that acts on them.
 */
class IncomingDocumentService
{
    /** @return array{count: int, items: list<array<string, mixed>>} */
    public function forUser(?User $user): array
    {
        if (! $user || $user->state !== 1 || ! $user->department_id) {
            return ['count' => 0, 'items' => []];
        }

        // An entry's own stage says who acts on it: normal entries ping the whole office, staged ones only that role.
        $stages = $this->stagesFor($user);
        $query = DocumentTrail::query()
            ->where('department_id', $user->department_id)
            ->whereNull('released_at')
            ->whereNull('received_by')
            ->where(fn ($trails) => $trails->whereNull('stage')->orWhereIn('stage', $stages))
            ->whereHas('document', fn ($documents) => $documents->where('state', 1)->where('status', '!=', 'Completed'));

        $items = (clone $query)
            ->with('document:id,barcode,subject_matter,approved_routing_slip')
            ->latest('received_at')->latest('id')->take(5)->get()
            ->map(fn (DocumentTrail $trail) => [
                'id' => $trail->id,
                'barcode' => $trail->document->barcode,
                'subject_matter' => Str::limit((string) $trail->document->subject_matter, 80),
                'action' => $trail->received_action,
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
            DocumentTrail::STAGE_ADM_APPROVAL, DocumentTrail::STAGE_ADM_SIGNATURE => route('referral-slips.index'),
            DocumentTrail::STAGE_OCM_APPROVAL => route('ocm-approvals.index'),
            // A signed slip back in Records is dispatched from the documents list.
            default => $document->approved_routing_slip
                ? route('documents.index', ['search' => $document->barcode])
                : route('transactions.inbox', ['document' => $document->id]),
        };
    }

    /** @return list<string> */
    private function stagesFor(User $user): array
    {
        return match ($user->role) {
            'system_administrator' => DocumentTrail::EXECUTIVE_STAGES,
            'city_administrator' => [DocumentTrail::STAGE_ADM_APPROVAL],
            'department_head' => [DocumentTrail::STAGE_ADM_SIGNATURE],
            'city_mayor' => [DocumentTrail::STAGE_OCM_APPROVAL],
            default => [],
        };
    }
}
