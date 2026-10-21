<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DocumentType extends Model
{
    use HasFactory;

    public const SIGNATURE_TYPES = ['digital', 'handwritten'];

    protected $fillable = [
        'document_code',
        'document_type',
        'signature_type',
        'may_be_confidential',
        'default_transaction_type_id',
        'rulebook_remarks',
        'state',
    ];

    protected function casts(): array
    {
        return [
            'state' => 'integer',
            'may_be_confidential' => 'boolean',
        ];
    }

    public function defaultTransactionType(): BelongsTo
    {
        return $this->belongsTo(TransactionType::class, 'default_transaction_type_id');
    }

    /** Classifications Records may pick for this type at intake (FGD rulebook, Classifications tab). */
    public function classifications(): BelongsToMany
    {
        return $this->belongsToMany(DocumentClassification::class)->withTimestamps();
    }
}
