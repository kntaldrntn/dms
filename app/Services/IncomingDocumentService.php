<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentTrail;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * "Incoming" documents for the notification bell: copies that arrived at the user's office
 * (open trail entry) that nobody in the office has acted on yet (received_by is only set when
 * the office releases its copy). Pings for executive steps go only to the role that acts on them.
 */
class IncomingDocumentService
{
    /** @return array{count: int, items: list<array<string, mixed>>} */
    public function forUser(?User $user): array
    {
        if (! $user || $user->state !== 1 || ! $user->department_id) {
            return ['count' => 0, 'items' => []];
        }

        $stages = $this->stagesFor($user);
        $query = DocumentTrail::query()
            ->where('department_id', $user->department_id)
            ->whereNull('released_at')
            ->whereNull('received_by')
            ->whereHas('document', fn ($documents) => $documents->where('state', 1)
                ->where('status', '!=', 'Completed')
                ->whereIn('requires_signature_from', $stages));

        $items = (clone $query)
            ->with('document:id,barcode,subject_matter,requires_signature_from,approved_routing_slip')
            ->latest('received_at')->latest('id')->take(5)->get()
            ->map(fn (DocumentTrail $trail) => [
                'id' => $trail->id,
                'barcode' => $trail->document->barcode,
                'subject_matter' => Str::limit((string) $trail->document->subject_matter, 80),
                'action' => $trail->received_action,
                'received_at' => $trail->received_at,
                'url' => $this->actionUrl($trail->document),
            ])->all();

        return ['count' => $query->count(), 'items' => $items];
    }

    /** The page where the receiving office acts on this document. */
    private function actionUrl(Document $document): string
    {
        return match ($document->requires_signature_from) {
            'city_administrator', 'department_head' => route('referral-slips.index'),
            'city_mayor' => route('ocm-approvals.index'),
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
            'system_administrator' => ['none', ...Document::EXECUTIVE_STAGES],
            'city_administrator' => ['none', 'city_administrator'],
            'department_head' => ['none', 'department_head'],
            'city_mayor' => ['none', 'city_mayor'],
            default => ['none'],
        };
    }
}
