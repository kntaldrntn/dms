<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentTrail;
use App\Models\User;

/**
 * Counts behind the sidebar badges: what is waiting on the signed-in user's office, page by page.
 * Shared as a deferred prop, so a page renders first and the counts arrive in a follow-up request.
 */
class SidebarCountService
{
    public function __construct(private DelinquencyTagService $delinquency) {}

    /**
     * @return array{
     *     transactions: array{open: int, delinquent: int},
     *     admApprovals: int|null,
     *     ocmApprovals: int|null,
     *     untaggedDelinquent: int|null
     * }
     */
    public function forUser(?User $user): array
    {
        $counts = ['transactions' => ['open' => 0, 'delinquent' => 0], 'admApprovals' => null, 'ocmApprovals' => null, 'untaggedDelinquent' => null];
        if (! $user || $user->state !== 1) {
            return $counts;
        }

        if ($user->department_id) {
            // Same documents as the Office Inbox (TransactionController::index).
            $inbox = Document::heldBy((int) $user->department_id)->outsideExecutiveStages()
                ->where('status', '!=', 'Completed')->where('state', 1)->get();
            $counts['transactions'] = [
                'open' => $inbox->count(),
                'delinquent' => $inbox->filter(fn (Document $document) => $document->is_delinquent)->count(),
            ];
        }

        // Same queues as the ADM / OCM Approvals pages.
        if ($user->can('viewAdmApprovals', Document::class)) {
            $counts['admApprovals'] = Document::where('state', 1)->where('status', '!=', 'Completed')
                ->inStage([DocumentTrail::STAGE_ADM_APPROVAL])->count();
        }
        if ($user->can('viewOcmApprovals', Document::class)) {
            $counts['ocmApprovals'] = Document::where('state', 1)->where('status', 'Pending')
                ->inStage([DocumentTrail::STAGE_OCM_APPROVAL])->count();
        }

        // The monitor's to-do: delinquent documents the current holder has no ADM tag for yet.
        if ($user->can('monitorDelinquency', Document::class)) {
            $counts['untaggedDelinquent'] = $this->delinquency->delinquentDocuments(['latestDelinquencyTag'])
                ->filter(fn (Document $document) => DelinquencyTagService::currentTag($document) === null)
                ->count();
        }

        return $counts;
    }
}
