<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class Document extends Model
{
    use HasFactory;

    protected $fillable = [
        'barcode', 'access_code', 'transaction_type_id', 'document_type_id',
        'classification_id', 'is_confidential', 'route_mode', 'delivery_method_id', 'department_id',
        'current_assignee_id', 'requires_signature_from', 'approved_routing_slip',
        'source_type', 'source_location', 'source_name', 'gender',
        'contact_no', 'email', 'subject_matter', 'linked_documents', 'attached_file',
        'state', 'status', 'due_date', 'completed_at', 'ai_routing_suggestions', 'workflow_version',
    ];

    protected $appends = ['is_delayed', 'is_delinquent', 'stage'];

    // --- Relationships ---
    public function transactionType() { return $this->belongsTo(TransactionType::class); }
    public function documentType() { return $this->belongsTo(DocumentType::class); }
    public function classification() { return $this->belongsTo(DocumentClassification::class); }
    public function department() { return $this->belongsTo(Department::class); }
    public function deliveryMethod() { return $this->belongsTo(DeliveryMethod::class); }
    public function trails() { return $this->hasMany(DocumentTrail::class)->orderBy('created_at', 'asc'); }
    
    // NEW: Links the document directly to the exact person responsible right now
    public function currentAssignee() { return $this->belongsTo(User::class, 'current_assignee_id'); }

    /**
     * Every office currently holding an open entry, Primary first. `pivot.copy_role` says whether the
     * office is the Primary (acts on the document) or holds a view-only copy. `department_id` is the Primary.
     */
    public function holders()
    {
        return $this->belongsToMany(Department::class, 'document_trails', 'document_id', 'department_id')
            ->withPivot('copy_role')
            ->wherePivotNull('released_at')
            ->orderByPivot('copy_role', 'desc')
            ->distinct();
    }

    protected function casts(): array
    {
        return [
            'state' => 'integer',
            'is_confidential' => 'boolean',
            'due_date' => 'datetime',
            'completed_at' => 'datetime',
            'ai_routing_suggestions' => 'array',
            'linked_documents' => 'array',
            'approved_routing_slip' => 'array',
            'workflow_version' => 'integer',
        ];
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where('state', 1);
    }

    /** Documents an office holds the open Primary entry of, i.e. the documents it must act on. */
    public function scopeHeldBy(Builder $query, int $departmentId): Builder
    {
        return $query->whereHas('trails', fn (Builder $trails) => $trails->where('department_id', $departmentId)
            ->where('copy_role', DocumentTrail::PRIMARY)->whereNull('released_at'));
    }

    /** The open Primary entry: where the document is acted on, and the executive stage it waits on. */
    public function primaryEntry()
    {
        return $this->hasOne(DocumentTrail::class)->ofMany(['id' => 'max'], fn (Builder $trails) => $trails
            ->where('copy_role', DocumentTrail::PRIMARY)->whereNull('released_at'));
    }

    /**
     * The executive step the document waits on (DocumentTrail::STAGE_*), or null for normal processing.
     * Uses an eager-loaded primaryEntry when present; otherwise reads it fresh, so a policy check after
     * a hand-off never sees a stale cached entry.
     */
    public function getStageAttribute(): ?string
    {
        if ($this->relationLoaded('primaryEntry')) {
            return $this->primaryEntry?->stage;
        }

        return $this->trails()->getQuery()->reorder()->where('copy_role', DocumentTrail::PRIMARY)
            ->whereNull('released_at')->latest('id')->value('stage');
    }

    /**
     * The PDF an approval stamps: the newest signed version, else the uploaded scan. Stamp placement is measured
     * on this same file in the browser, so a second approval does not cover the first signature.
     */
    public function stampSourceUrl(): ?string
    {
        $signed = $this->trails()->getQuery()->reorder()->whereNotNull('signed_file_path')->latest('id')->value('signed_file_path');
        if ($signed && str_ends_with(strtolower($signed), '.pdf')) {
            return route('documents.ocm-approved-pdf', $this);
        }

        return $this->attached_file ? '/storage/'.$this->attached_file : null;
    }

    public function inExecutiveStage(): bool
    {
        return in_array($this->stage, DocumentTrail::EXECUTIVE_STAGES, true);
    }

    /** @param  list<string>  $stages  DocumentTrail::STAGE_* values */
    public function scopeInStage(Builder $query, array $stages): Builder
    {
        return $query->whereHas('trails', fn (Builder $trails) => $trails->where('copy_role', DocumentTrail::PRIMARY)
            ->whereNull('released_at')->whereIn('stage', $stages));
    }

    /** Documents not waiting on any executive step: the ones offices process from their inbox. */
    public function scopeOutsideExecutiveStages(Builder $query): Builder
    {
        return $query->whereDoesntHave('trails', fn (Builder $trails) => $trails->where('copy_role', DocumentTrail::PRIMARY)
            ->whereNull('released_at')->whereIn('stage', DocumentTrail::EXECUTIVE_STAGES));
    }

    /** Documents an office holds an open view-only copy of. */
    public function scopeCopiedTo(Builder $query, int $departmentId): Builder
    {
        return $query->whereHas('trails', fn (Builder $trails) => $trails->where('department_id', $departmentId)
            ->where('copy_role', DocumentTrail::COPY)->whereNull('released_at'));
    }

    // =====================================================================
    // SMART E.O.D.B. STATUS TRACKERS
    // =====================================================================

    public function getIsDelayedAttribute()
    {
        if ($this->status === 'Completed' || !$this->due_date) {
            return false;
        }
        return Carbon::now('Asia/Manila')->greaterThan($this->due_date);
    }

    public function getIsDelinquentAttribute()
    {
        if ($this->status === 'Completed' || !$this->department_id) {
            return false;
        }

        $latestTrail = $this->trails()->where('released_to', $this->department_id)->latest()->first();
        // released_at is Manila wall-clock stored as if UTC, while created_at is true UTC
        // (documentation.md §6 #15), so each is converted to a real Manila time before diffing.
        $startTime = $latestTrail?->released_at
            ? Carbon::parse($latestTrail->released_at->format('Y-m-d H:i:s'), 'Asia/Manila')
            : ($latestTrail?->created_at ?? $this->created_at)->copy()->setTimezone('Asia/Manila');

        $standard = \App\Models\PerformanceStandard::where('department_id', $this->department_id)
            ->where('document_type_id', $this->document_type_id)
            ->first();

        $allocatedMinutes = $standard ? $standard->allocated_minutes : \App\Models\PerformanceStandard::DEFAULT_ALLOCATED_MINUTES;
        $minutesSpent = $startTime->diffInMinutes(Carbon::now('Asia/Manila'), true);

        return $minutesSpent > $allocatedMinutes;
    }
}
