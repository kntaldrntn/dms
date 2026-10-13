<?php

namespace App\Services;

use App\Models\Document;
use App\Models\User;

class DispatchService
{
    public function __construct(private DocumentWorkflow $workflow) {}

    public function dispatch(Document $document, User $user, array $data): void
    {
        $this->workflow->mutate($document, $user, (int) $data['workflow_version'], 'dispatch', function (Document $document, User $user) use ($data) {
            $targets = $this->workflow->targets($data['routed_to'], $document->department_id, 'routed_to');
            $this->workflow->release($document, $user, 'Dispatched to target office(s)', $targets[0], null, null, [
                'released_to_offices' => $targets,
            ]);
            foreach ($targets as $target) {
                $this->workflow->receive($document, $target, 'Dispatched from Records');
            }
            $document->department_id = $targets[0];
            $document->current_assignee_id = null;
            // A referral is consumed once, including an explicit Records override.
            $document->approved_routing_slip = null;
        });
    }
}
