<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DelinquencyTag extends Model
{
    /** Tags the City Administrator's office can put on a delinquent document (FGD Phase 5). */
    public const TAGS = [
        'followed_up' => 'Followed up',
        'justified' => 'Delay justified',
        'escalated' => 'Escalated',
    ];

    protected $fillable = [
        'document_id', 'department_id', 'document_trail_id', 'tag', 'note',
        'tagged_by', 'tagged_at', 'acknowledged_by', 'acknowledged_at',
    ];

    protected $appends = ['label'];

    protected function casts(): array
    {
        return [
            'tagged_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function tagger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tagged_by');
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function getLabelAttribute(): string
    {
        return self::TAGS[$this->tag] ?? $this->tag;
    }
}
