<?php

namespace App\Services;

use App\Models\Document;
use App\Models\User;

class ReferralService
{
    public function __construct(private DocumentWorkflow $workflow) {}

    public function generate(Document $document, User $user, array $data): void
    {
        $this->workflow->mutate($document, $user, (int) $data['workflow_version'], 'refer', function (Document $document, User $user) use ($data) {
            $targets = $this->workflow->targets($data['targets'], $document->department_id, 'targets');
            $records = $this->workflow->office('REC');
            $signer = $this->workflow->signer($user, (bool) ($data['attach_signature'] ?? false));
            $this->workflow->release($document, $user, 'Approved Routing Slip Generated', $records->id, $data['remarks'] ?? null, $signer, [
                'required_actions' => $data['required_actions'],
                'other_action' => $data['other_action'] ?? null,
                'concerned_personnel' => $data['concerned_personnel'] ?? null,
            ]);
            $this->workflow->receive($document, $records->id, 'Routing Slip returned for dispatch');
            $document->fill([
                'approved_routing_slip' => $targets, 'department_id' => $records->id,
                'requires_signature_from' => 'none', 'current_assignee_id' => null,
            ]);
        });
    }
}
