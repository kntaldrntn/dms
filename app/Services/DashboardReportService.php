<?php

namespace App\Services;

use App\Models\Document;
use App\Models\TransactionType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The document sets behind the dashboard cards, shared by the dashboard (counts) and the printable
 * report (rows), so a card and its report always agree.
 */
class DashboardReportService
{
    public const TRANSACTION_CATEGORIES = ['Simple', 'Complex', 'Highly Technical'];

    public const PIPELINES = ['on_time' => 'On-Time', 'on_going' => 'On-Going', 'delayed' => 'Delayed'];

    /** Office-scoped lists that open from the alert cards. */
    public const ALERT_REPORTS = ['delinquent' => 'Delinquent Documents', 'due_tomorrow' => 'Due Tomorrow', 'todo' => 'To-Do List'];

    /** Same rule as document visibility: Workstation staff see their own department only. */
    public function seesAllDepartments(User $user): bool
    {
        return $user->seesAllDocuments();
    }

    /** Limits a document query to the user's own department unless they see every office. */
    public function scopeToUser(Builder $documents, User $user): Builder
    {
        return $documents->when(! $this->seesAllDepartments($user), fn ($query) => $query->handledBy((int) $user->department_id));
    }

    /** Open documents: every active department for oversight roles, otherwise those the user's office holds. */
    public function openDocuments(User $user, array $with = []): Collection
    {
        return Document::with(['trails', 'documentType', 'department', ...$with])
            ->where('status', '!=', 'Completed')
            ->where('state', 1)
            ->when(! $this->seesAllDepartments($user), fn ($documents) => $documents->heldBy((int) $user->department_id))
            ->get();
    }

    public function dueTomorrow(Collection $openDocuments): Collection
    {
        return $openDocuments->where('due_date', '>=', Carbon::tomorrow('Asia/Manila')->startOfDay())
            ->where('due_date', '<=', Carbon::tomorrow('Asia/Manila')->endOfDay());
    }

    /** Delinquency comes from each document's current department's PerformanceStandard (is_delinquent accessor). */
    public function delinquent(Collection $openDocuments): Collection
    {
        return $openDocuments->filter(fn (Document $document) => $document->is_delinquent);
    }

    public function alertReport(string $report, Collection $openDocuments): Collection
    {
        return match ($report) {
            'delinquent' => $this->delinquent($openDocuments),
            'due_tomorrow' => $this->dueTomorrow($openDocuments),
            default => $openDocuments,
        };
    }

    public static function category(?string $transactionTypeName): string
    {
        if (stripos((string) $transactionTypeName, 'Complex') !== false) {
            return 'Complex';
        }
        if (stripos((string) $transactionTypeName, 'Technical') !== false) {
            return 'Highly Technical';
        }

        return 'Simple';
    }

    /** @return array<int, int> */
    public function transactionTypeIds(string $category): array
    {
        return TransactionType::where('state', 1)->pluck('name', 'id')
            ->filter(fn ($name) => self::category($name) === $category)
            ->keys()
            ->all();
    }

    /** Active documents registered in the given month, within what the user may see. */
    public function monthQuery(int $year, int $month, User $user): Builder
    {
        return $this->scopeToUser(Document::whereYear('created_at', $year)->whereMonth('created_at', $month)->where('state', 1), $user);
    }

    /**
     * One pipeline cell: on-time is completed on/before its due date, on-going is still open and within SLA,
     * and delayed has exceeded its due date. Mirrors the dashboard's pipeline counter.
     */
    public function pipelineQuery(int $year, int $month, string $category, string $pipeline, User $user): Builder
    {
        $now = Carbon::now('Asia/Manila');
        $query = $this->monthQuery($year, $month, $user)->whereIn('transaction_type_id', $this->transactionTypeIds($category));

        return match ($pipeline) {
            'on_time' => $query->where('status', 'Completed')
                ->where(fn ($documents) => $documents->whereNull('due_date')->orWhereColumn('completed_at', '<=', 'due_date')),
            'on_going' => $query->where('status', '!=', 'Completed')
                ->where(fn ($documents) => $documents->whereNull('due_date')->orWhere('due_date', '>=', $now)),
            default => $query->where(fn ($documents) => $documents
                ->where(fn ($completed) => $completed->where('status', 'Completed')->whereNotNull('due_date')->whereColumn('completed_at', '>', 'due_date'))
                ->orWhere(fn ($open) => $open->where('status', '!=', 'Completed')->whereNotNull('due_date')->where('due_date', '<', $now))),
        };
    }

    /**
     * Days from registration to completion (or to now while open). created_at is real UTC, but completed_at is
     * saved as Manila wall-clock time, so it is read back as Manila before the two are compared.
     */
    public function totalDays(Document $document): float
    {
        $end = $document->completed_at
            ? Carbon::parse($document->completed_at->format('Y-m-d H:i:s'), 'Asia/Manila')
            : Carbon::now('Asia/Manila');

        return max($document->created_at->diffInSeconds($end, false), 0) / 86400;
    }
}
