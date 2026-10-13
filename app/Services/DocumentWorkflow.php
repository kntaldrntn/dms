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
     * Offices currently holding an open copy (received, not yet released), locked for this transaction.
     * A multi-office dispatch leaves one open copy per office; each office acts on its own copy.
     *
     * @return list<int>
     */
    public function openOffices(Document $document): array
    {
        return DocumentTrail::where('document_id', $document->id)->whereNull('released_at')
            ->lockForUpdate()->pluck('department_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** Close an office's custody entry (default: the document's current office) without changing its existing receiver. */
    public function release(Document $document, User $user, string $action, ?int $target, ?string $remarks = null, ?int $signer = null, array $metadata = [], ?int $office = null): void
    {
        $office ??= $document->department_id;
        $trail = DocumentTrail::where('document_id', $document->id)
            ->where('department_id', $office)->whereNull('released_at')->latest('id')->first();
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

    public function receive(Document $document, int $department, string $action): void
    {
        DocumentTrail::create([
            'document_id' => $document->id, 'department_id' => $department,
            'received_at' => now('Asia/Manila'), 'received_action' => $action,
        ]);
    }

    public function signer(User $user, bool $requested, bool $allowDepartmentHead = false): ?int
    {
        if (! $requested) {
            return null;
        }
        $signer = $allowDepartmentHead && $user->role === 'staff'
            ? User::where('department_id', $user->department_id)->where('role', 'department_head')->where('state', 1)->lockForUpdate()->first()
            : $user;
        if (! $signer?->signature_path) {
            throw ValidationException::withMessages(['attach_signature' => 'The signing user must upload a digital signature first.']);
        }

        return $signer->id;
    }
}
