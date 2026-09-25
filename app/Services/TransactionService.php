<?php

namespace App\Services;

use App\Models\Document;
use App\Models\User;

class TransactionService
{
    public function __construct(private DocumentWorkflow $workflow) {}

    public function process(Document $document, User $user, array $data): void
    {
        $this->workflow->mutate($document, $user, (int) $data['workflow_version'], 'process', function (Document $document, User $user) use ($data) {
            $signer = $this->workflow->signer($user, (bool) ($data['attach_signature'] ?? false), true);

            if ($data['action'] === 'end') {
                $this->workflow->release($document, $user, 'End Transaction', null, $data['remarks'], $signer);
                $document->fill([
                    'status' => 'Completed',
                    'completed_at' => now('Asia/Manila'),
                    'current_assignee_id' => null,
                ]);

                return;
            }

            $target = $this->workflow->targets([(int) $data['routed_to']], $document->department_id, 'routed_to')[0];
            $this->workflow->release($document, $user, 'Routed', $target, $data['remarks'], $signer);
            $this->workflow->receive($document, $target, 'Routed from '.($user->department?->code ?? 'Previous Office'));
            $document->fill(['department_id' => $target, 'current_assignee_id' => null]);
        });
    }
}
