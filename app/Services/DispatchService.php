<?php

namespace App\Services;

use App\Models\Document;
use App\Models\User;

class DispatchService
{
    public function __construct(private DocumentWorkflow $workflow) {}

    /** Records sends the document to one Primary office; every other target gets a view-only copy. */
    public function dispatch(Document $document, User $user, array $data): void
    {
        $this->workflow->mutate($document, $user, (int) $data['workflow_version'], 'dispatch', function (Document $document, User $user) use ($data) {
            $targets = $this->workflow->targets($data['routed_to'], $document->department_id, 'routed_to');
            $primary = (int) ($data['primary_office_id'] ?? $targets[0]);
            $this->workflow->release($document, $user, 'Dispatched to target office(s)', $primary, null, null, [
                'released_to_offices' => $targets,
            ]);
            $this->workflow->distribute($document, $user, $targets, $primary, 'Dispatched from Records', 'Copy from Records');
        });
    }
}
