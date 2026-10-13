<?php

namespace App\Services;

use App\Models\Document;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class TransactionService
{
    public function __construct(private DocumentWorkflow $workflow) {}

    /**
     * An office acts on its own copy only. After a multi-office dispatch each office ends or routes
     * its copy independently; the document is Completed once no office holds an open copy.
     */
    public function process(Document $document, User $user, array $data): void
    {
        $this->workflow->mutate($document, $user, null, 'process', function (Document $document, User $user) use ($data) {
            $office = (int) $user->department_id;
            $holders = $this->workflow->openOffices($document);
            if (! in_array($office, $holders, true)) {
                throw ValidationException::withMessages(['document' => 'Your office has already acted on this document. Refresh the page.']);
            }
            $signer = $this->workflow->signer($user, (bool) ($data['attach_signature'] ?? false), true);

            if ($data['action'] === 'end') {
                $this->workflow->release($document, $user, 'End Transaction', null, $data['remarks'], $signer, [], $office);
                $remaining = array_values(array_diff($holders, [$office]));
                if ($remaining === []) {
                    $document->fill(['status' => 'Completed', 'completed_at' => now('Asia/Manila'), 'current_assignee_id' => null]);
                } else {
                    $document->fill(['department_id' => $remaining[0], 'current_assignee_id' => null]);
                }

                return;
            }

            $target = $this->workflow->targets([(int) $data['routed_to']], $office, 'routed_to')[0];
            if (in_array($target, $holders, true)) {
                throw ValidationException::withMessages(['routed_to' => 'That office already holds a copy of this document.']);
            }
            $this->workflow->release($document, $user, 'Routed', $target, $data['remarks'], $signer, [], $office);
            $this->workflow->receive($document, $target, 'Routed from '.($user->department?->code ?? 'Previous Office'));
            $document->fill(['department_id' => $target, 'current_assignee_id' => null]);
        });
    }
}
