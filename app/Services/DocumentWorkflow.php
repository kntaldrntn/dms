<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentTrail;
use App\Models\User;
use Closure;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class DocumentWorkflow
{
    use DetectsConcurrencyErrors;

    /**
     * Pass a null $version for per-office actions on a multi-office document: another office acting on
     * its own copy must not make this office's form stale. Those callers guard with openOffices() instead.
     */
    public function mutate(Document $document, User $user, ?int $version, string $ability, Closure $change): Document
    {
        return $this->transaction(function () use ($document, $user, $version, $ability, $change) {
            $document = Document::query()->lockForUpdate()->findOrFail($document->id);
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            Gate::forUser($user)->authorize($ability, $document);
            abort_if($document->state !== 1 || $document->status === 'Completed', 409, 'This document is no longer active.');
            if ($version !== null && $document->workflow_version !== $version) {
                throw ValidationException::withMessages(['workflow_version' => 'This document changed after you opened it. Refresh the page and try again.']);
            }
            $change($document, $user);
            $document->workflow_version++;
            $document->save();

            return $document;
        });
    }

    private function transaction(Closure $change): Document
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction($change);
            } catch (Throwable $exception) {
                if (! $this->causedByConcurrencyError($exception) || DB::transactionLevel() > 0) {
                    throw $exception;
                }
                if ($attempt === 3) {
                    throw ValidationException::withMessages(['workflow_version' => 'Another update is in progress. Refresh the page and try again.']);
                }
                // Let the competing writer commit before re-reading its version.
                usleep(50000 * $attempt);
            }
        }
    }

    /** Validate again under the transaction, after acquiring the document lock. */
    public function targets(array $ids, ?int $source, string $field): array
    {
        Validator::make([$field => $ids], [
            $field => ['required', 'array', 'min:1', 'max:100'],
            $field.'.*' => ['required', 'integer', 'distinct', Rule::notIn([$source])],
        ])->validate();
        $ids = array_map('intval', array_values($ids));
        $departments = Department::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        if ($departments->count() !== count($ids) || $departments->contains(fn ($department) => $department->state !== 1)) {
            throw ValidationException::withMessages([$field => 'Select active destination offices.']);
        }

        return $ids;
    }

    public function office(string $code): Department
    {
        $offices = Department::where('code', $code)->where('state', 1)->lockForUpdate()->get();
        if ($offices->count() !== 1) {
            throw ValidationException::withMessages(['department_id' => "Exactly one active {$code} office must be configured."]);
        }

        return $offices->first();
    }

    /**
     * Offices currently holding an open entry (received, not yet released), locked for this transaction.
     * A multi-office dispatch leaves one Primary entry and one view-only copy per other office.
     *
     * @return list<int>
     */
    public function openOffices(Document $document, ?string $role = null): array
    {
        return DocumentTrail::where('document_id', $document->id)->whereNull('released_at')
            ->when($role, fn ($trails) => $trails->where('copy_role', $role))
            ->lockForUpdate()->pluck('department_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** The office holding the open Primary entry, the only office that may act on the document. */
    public function primaryOffice(Document $document): ?int
    {
        return $this->openOffices($document, DocumentTrail::PRIMARY)[0] ?? null;
    }

    /**
     * Close open view-only copies without recording a receiver: an office that never received its copy
     * must not appear as having received it. Limit to $offices to close only some copies.
     */
    public function closeCopies(Document $document, User $user, string $action, ?array $offices = null): void
    {
        DocumentTrail::where('document_id', $document->id)->whereNull('released_at')->where('copy_role', DocumentTrail::COPY)
            ->when($offices !== null, fn ($trails) => $trails->whereIn('department_id', $offices))
            ->get()->each(fn (DocumentTrail $trail) => $trail->fill([
                'released_by' => $user->id, 'released_at' => now('Asia/Manila'), 'released_action' => $action,
            ])->save());
    }

    /**
     * Send the document to a Primary office plus view-only copies to the other targets. A target that
     * already holds a copy keeps it; a copy holder that becomes Primary has its copy closed first.
     *
     * @param  list<int>  $targets
     */
    public function distribute(Document $document, User $user, array $targets, int $primary, string $primaryAction, string $copyAction): void
    {
        if (! in_array($primary, $targets, true)) {
            throw ValidationException::withMessages(['primary_office_id' => 'Choose the Primary office from the selected offices.']);
        }
        if ($document->is_confidential && count($targets) > 1) {
            throw ValidationException::withMessages(['routed_to' => 'Confidential documents go to the Primary office only; no copies are sent.']);
        }
        $copyHolders = $this->openOffices($document, DocumentTrail::COPY);
        if (in_array($primary, $copyHolders, true)) {
            $this->closeCopies($document, $user, 'Copy closed: office made Primary', [$primary]);
        }
        $this->receive($document, $primary, $primaryAction);
        foreach (array_diff($targets, [$primary], $copyHolders) as $office) {
            $this->receive($document, (int) $office, $copyAction, DocumentTrail::COPY);
        }
        $document->department_id = $primary;
        $document->current_assignee_id = null;
    }

    /** Close an office's custody entry (default: the document's current office) without changing its existing receiver. */
    public function release(Document $document, User $user, string $action, ?int $target, ?string $remarks = null, ?int $signer = null, array $metadata = [], ?int $office = null): void
    {
        $office ??= $document->department_id;
        // Prefer the office's Primary entry; a copy entry is only released when nothing else is open there.
        $trail = DocumentTrail::where('document_id', $document->id)
            ->where('department_id', $office)->whereNull('released_at')
            ->orderByRaw("case when copy_role = 'primary' then 0 else 1 end")->latest('id')->first();
        if (! $trail) {
            $trail = new DocumentTrail([
                'document_id' => $document->id, 'department_id' => $office,
                'received_at' => $document->created_at, 'received_action' => 'Receive Document',
            ]);
        }
        $trail->fill([
            'received_by' => $trail->received_by ?? $user->id,
            'released_by' => $user->id, 'released_at' => now('Asia/Manila'),
            'released_action' => $action, 'released_to' => $target, 'remarks' => $remarks,
            'trail_type' => $signer === null ? 'forward' : 'approval',
            'is_digitally_signed' => $signer !== null, 'signed_by' => $signer,
            ...$metadata,
        ])->save();
    }

    /** A copy never carries a stage: only the Primary office waits on (and acts on) an executive step. */
    public function receive(Document $document, int $department, string $action, string $role = DocumentTrail::PRIMARY, ?string $stage = null): void
    {
        DocumentTrail::create([
            'document_id' => $document->id, 'department_id' => $department, 'copy_role' => $role,
            'stage' => $role === DocumentTrail::PRIMARY ? $stage : null,
            'received_at' => now('Asia/Manila'), 'received_action' => $action,
        ]);
    }

    /** The approval stage a document opens when it is sent to ADM or OCM; null for any other office. */
    public function executiveStageFor(int $department): ?string
    {
        return match (Department::whereKey($department)->value('code')) {
            'ADM' => DocumentTrail::STAGE_ADM_APPROVAL,
            'OCM' => DocumentTrail::STAGE_OCM_APPROVAL,
            default => null,
        };
    }

    /**
     * Paper route: the office's Department Head signed the paper copy by hand; staff record it. A Department Head
     * recording it is the signer themself.
     */
    public function departmentHeadSigner(User $user): int
    {
        $head = $user->role === 'department_head'
            ? $user
            : User::where('department_id', $user->department_id)->where('role', 'department_head')->where('state', 1)->first();
        if (! $head) {
            throw ValidationException::withMessages(['head_signed' => 'Your office has no active Department Head account to record as signer.']);
        }

        return $head->id;
    }
}
