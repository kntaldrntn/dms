<?php

namespace App\Services;

use App\Models\Document;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/** The Archive: every document the user may see (Document::visibleTo), narrowed by any mix of filters. */
class ArchiveSearchService
{
    /** Filter key => documents column, for the plain dropdown filters. */
    private const EXACT_FILTERS = [
        'document_type_id' => 'document_type_id',
        'transaction_type_id' => 'transaction_type_id',
        'classification_id' => 'classification_id',
        'delivery_method_id' => 'delivery_method_id',
        'office_id' => 'department_id',
        'source_type' => 'source_type',
    ];

    public function search(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = Document::visibleTo($user)
            ->with(['documentType:id,document_code,document_type', 'transactionType:id,name', 'holders:id,code,name'])
            ->latest()->latest('id');

        foreach (self::EXACT_FILTERS as $key => $column) {
            if (isset($filters[$key])) {
                $query->where($column, $filters[$key]);
            }
        }

        if (isset($filters['keyword'])) {
            $this->matchKeyword($query, $user, $filters['keyword']);
        }

        if (isset($filters['status'])) {
            $filters['status'] === 'completed'
                ? $query->where('status', 'Completed')
                : $query->where('status', '!=', 'Completed');
        }

        if (isset($filters['from'])) {
            $query->where('created_at', '>=', CarbonImmutable::parse($filters['from'], 'Asia/Manila')->startOfDay());
        }
        if (isset($filters['to'])) {
            $query->where('created_at', '<=', CarbonImmutable::parse($filters['to'], 'Asia/Manila')->endOfDay());
        }

        return $query->paginate($perPage)->withQueryString()->through(fn (Document $document) => $this->row($document, $user));
    }

    /**
     * Barcode always; subject and source only where the user may read them, so a confidential document's hidden
     * details cannot be found by guessing words. (Workstation staff never see confidential documents here anyway.)
     */
    private function matchKeyword(Builder $query, User $user, string $keyword): void
    {
        $like = '%'.addcslashes($keyword, '%_\\').'%';

        $query->where(fn (Builder $match) => $match
            ->where('barcode', 'LIKE', $like)
            ->orWhere(fn (Builder $details) => $details
                ->when(! $user->seesAllDocuments(), fn (Builder $readable) => $readable->where('is_confidential', false))
                ->where(fn (Builder $text) => $text->where('subject_matter', 'LIKE', $like)->orWhere('source_name', 'LIKE', $like))));
    }

    /** Only what the table shows; skips the model's appended SLA attributes, which query per row. */
    private function row(Document $document, User $user): array
    {
        $hidden = $document->detailsHiddenFrom($user);

        return [
            'id' => $document->id,
            'barcode' => $document->barcode,
            'received_at' => $document->created_at?->toIso8601String(),
            'document_type' => $document->documentType
                ? ['code' => $document->documentType->document_code, 'name' => $document->documentType->document_type]
                : null,
            'transaction_type' => $document->transactionType?->name,
            'source_type' => $document->source_type,
            'source_location' => $hidden ? null : $document->source_location,
            'source_name' => $hidden ? null : $document->source_name,
            'subject_matter' => $hidden ? null : $document->subject_matter,
            'details_hidden' => $hidden,
            'status' => $document->status,
            'holders' => $document->holders->map->only(['code', 'name'])->values(),
        ];
    }
}
