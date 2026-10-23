<?php

namespace App\Services;

use App\Models\DocumentTrail;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * "Incoming" documents for the notification bell: entries that arrived at the user's office
 * (open trail entry) that nobody in the office has acted on yet. received_by is set when the
 * Primary office releases its entry, or when a copy office receives its view-only copy.
 * Pings for executive steps go only to the role that acts on them. ADM approval pings twice: every ADM user
 * until one receives the document, then the City Administrator, who approves it.
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
        $approvesAdm = in_array($user->role, ['city_administrator', 'system_administrator'], true);
        $query = DocumentTrail::query()
            ->where('department_id', $user->department_id)
            ->whereNull('released_at')
            ->where(fn ($trails) => $trails
                ->where(fn ($unreceived) => $unreceived->whereNull('received_by')
                    ->where(fn ($staged) => $staged->whereNull('stage')->orWhereIn('stage', $stages)))
                ->when($approvesAdm, fn ($trails) => $trails->orWhere(fn ($received) => $received->whereNotNull('received_by')
                    ->where('stage', DocumentTrail::STAGE_ADM_APPROVAL))))
            ->whereHas('document', fn ($documents) => $documents->where('state', 1)->where('status', '!=', 'Completed'));

        $items = (clone $query)
            ->with('document:id,barcode,subject_matter,approved_routing_slip')
            ->latest('received_at')->latest('id')->take(5)->get()
            ->map(fn (DocumentTrail $trail) => [
                'id' => $trail->id,
                'barcode' => $trail->document->barcode,
                'subject_matter' => Str::limit((string) $trail->document->subject_matter, 80),
                'action' => $trail->received_by && $trail->stage === DocumentTrail::STAGE_ADM_APPROVAL
                    ? 'Ready for City Administrator approval'
                    : $trail->received_action,
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
            DocumentTrail::STAGE_ADM_SIGNATURE => route('referral-slips.index'),
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
            // Any ADM user can receive a document waiting on City Administrator approval.
            'system_administrator' => DocumentTrail::EXECUTIVE_STAGES,
            'department_head' => [DocumentTrail::STAGE_ADM_APPROVAL, DocumentTrail::STAGE_ADM_SIGNATURE],
            'city_mayor' => [DocumentTrail::STAGE_ADM_APPROVAL, DocumentTrail::STAGE_OCM_APPROVAL],
            default => [DocumentTrail::STAGE_ADM_APPROVAL],
        };
    }
}
