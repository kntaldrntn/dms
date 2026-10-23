<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Department;
use App\Models\DocumentTrail;
use App\Models\TransactionType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class DocumentService
{
    public function __construct(private DocumentWorkflow $workflow) {}

    public function save(?Document $document, User $user, array $data): Document
    {
        $upload = $data['attached_file'] ?? null;
        unset($data['attached_file']);
        $newPath = null;

        try {
            if ($upload instanceof UploadedFile) {
                $newPath = $upload->store('documents', 'public');
                if (! $newPath) {
                    throw new RuntimeException('The attachment could not be saved.');
                }
                $data['attached_file'] = $newPath;
            }
            if (array_key_exists('linked_documents', $data)) {
                $data['linked_documents'] = preg_split('/[\s,]+/', trim($data['linked_documents'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
            }

            if ($document) {
                $version = (int) $data['workflow_version'];
                unset($data['workflow_version']);

                return $this->workflow->mutate($document, $user, $version, 'update', function (Document $document, User $user) use ($data) {
                    $target = empty($data['department_id']) ? $document->department_id : (int) $data['department_id'];
                    if ($target !== (int) $document->department_id) {
                        $this->workflow->targets([$target], $document->department_id, 'department_id');
                        $this->workflow->release($document, $user, 'Reassigned during registration update', $target);
                        $this->workflow->receive($document, $target, 'Registration reassigned', DocumentTrail::PRIMARY, $this->workflow->executiveStageFor($target));
                        $document->current_assignee_id = null;
                        $document->approved_routing_slip = null;
                    }
                    if ((int) $document->transaction_type_id !== (int) $data['transaction_type_id']) {
                        $type = TransactionType::findOrFail($data['transaction_type_id']);
                        $document->due_date = $document->created_at->copy()->addWeekdays($type->processing_days);
                    }
                    $document->fill([...$data, 'department_id' => $target]);
                });
            }

            return DB::transaction(function () use ($user, $data) {
                $user = User::query()->lockForUpdate()->findOrFail($user->id);
                Gate::forUser($user)->authorize('create', Document::class);
                $target = empty($data['department_id']) ? (int) $user->department_id : (int) $data['department_id'];
                $this->workflow->targets([$target], null, 'department_id');
                $executiveOfficeCode = Department::whereKey($target)->where('state', 1)->value('code');
                $requiredExecutiveApproval = match ($executiveOfficeCode) {
                    'ADM' => 'city_administrator',
                    'OCM' => 'city_mayor',
                    default => null,
                };
                $type = TransactionType::findOrFail($data['transaction_type_id']);
                $document = Document::create([
                    ...$data, 'department_id' => $user->department_id, 'status' => 'Pending',
                    'requires_signature_from' => $requiredExecutiveApproval ?? ($data['requires_signature_from'] ?? 'none'),
                    'due_date' => now('Asia/Manila')->addWeekdays($type->processing_days),
                ]);
                $document->trails()->create([
                    'department_id' => $user->department_id, 'received_by' => $user->id,
                    'received_at' => now('Asia/Manila'), 'received_action' => 'Document Scanned & Registered',
                ]);
                if ($requiredExecutiveApproval) {
                    // Executive review is an actual hand-off: custody and the trail both
                    // move to ADM/OCM so the document's path is visible office to office.
                    [$releaseAction, $receiveAction] = $requiredExecutiveApproval === 'city_mayor'
                        ? ['Submitted for City Mayor approval', 'Awaiting City Mayor approval']
                        : ['Forwarded to City Administrator', 'Awaiting referral slip'];
                    $this->workflow->release($document, $user, $releaseAction, $target);
                    $this->workflow->receive($document, $target, $receiveAction, DocumentTrail::PRIMARY, $this->workflow->executiveStageFor($target));
                    $document->department_id = $target;
                    $document->save();
                } elseif ($target !== (int) $user->department_id) {
                    $this->workflow->release($document, $user, 'Direct Dispatch', $target);
                    $this->workflow->receive($document, $target, 'Direct Dispatch from Records');
                    $document->department_id = $target;
                    $document->save();
                }

                return $document;
            });
        } catch (Throwable $exception) {
            if ($newPath) {
                Storage::disk('public')->delete($newPath);
            }
            throw $exception;
        }
    }

    public function deactivate(Document $document, User $user, int $version): void
    {
        $this->workflow->mutate($document, $user, $version, 'delete', function (Document $document, User $user) {
            // Close every office's open copy, not just the recorded current office.
            foreach ($this->workflow->openOffices($document) ?: [(int) $document->department_id] as $office) {
                $this->workflow->release($document, $user, 'Document deactivated', null, null, null, [], $office);
            }
            $document->fill(['state' => 0, 'current_assignee_id' => null]);
        });
    }
}
