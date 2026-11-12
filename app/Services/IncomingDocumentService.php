<?php

namespace App\Services;

use App\Models\DelinquencyTag;
use App\Models\DocumentTrail;
use App\Models\User;
use Illuminate\Support\Collection;
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
            ->with('document:id,barcode,subject_matter,is_confidential,route_mode')
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
                    // A paper document for the Office Inbox: a notice only, received by scanning it on arrival.
                    default => $this->awaitsPaper($trail)
                        ? 'Incoming paper: scan its barcode when it arrives'
                        : $trail->received_action,
                },
                'received_at' => $trail->received_at,
                'url' => $trail->copy_role === DocumentTrail::COPY
                    ? route('transactions.inbox', ['copy' => $trail->document_id])
                    : $this->actionUrl($trail),
            ])->all();

        $tags = $this->delinquencyTagsFor($user);

        // Tags first: they are follow-ups from the City Administrator on documents already overdue.
        return [
            'count' => $query->count() + $tags->count(),
            'items' => [...$tags->take(5)->all(), ...$items],
        ];
    }

    /** ADM tags the office has not marked as noted, while it still holds the document (FGD Phase 5). */
    private function delinquencyTagsFor(User $user): Collection
    {
        return DelinquencyTag::query()
            ->where('department_id', $user->department_id)
            ->whereNull('acknowledged_at')
            ->whereHas('document', fn ($documents) => $documents->where('state', 1)->where('status', '!=', 'Completed')
                ->whereColumn('documents.department_id', 'delinquency_tags.department_id'))
            ->with('document:id,barcode,subject_matter,is_confidential')
            ->latest('tagged_at')->latest('id')->get()
            ->map(fn (DelinquencyTag $tag) => [
                'id' => "tag-{$tag->id}",
                'barcode' => $tag->document->barcode,
                'subject_matter' => $tag->document->detailsHiddenFrom($user)
                    ? 'Confidential document'
                    : Str::limit((string) $tag->document->subject_matter, 80),
                'action' => "Delinquent, tagged by ADM: {$tag->label}",
                'received_at' => $tag->tagged_at,
                'url' => route('transactions.inbox', ['document' => $tag->document_id]),
            ]);
    }

    private function awaitsPaper(DocumentTrail $trail): bool
    {
        return $trail->copy_role === DocumentTrail::PRIMARY && $trail->stage === null && $trail->received_by === null
            && ! $trail->document->isPaperless();
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
