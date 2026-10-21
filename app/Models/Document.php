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

    protected $appends = ['is_delayed', 'is_delinquent'];

    /** requires_signature_from values that mean the document waits on an executive queue (slip, signature, OCM). */
    public const EXECUTIVE_STAGES = ['city_administrator', 'department_head', 'city_mayor'];

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
     * Every office currently holding an open copy (trail received, not yet released).
     * After a multi-office dispatch this returns all recipients, while `department_id`
     * keeps only one primary office (the first target, or the latest office a copy moved to).
     */
    public function holders()
    {
        return $this->belongsToMany(Department::class, 'document_trails', 'document_id', 'department_id')
            ->wherePivotNull('released_at')
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

    /** Documents an office currently holds an open copy of (see DocumentWorkflow::openOffices()). */
    public function scopeHeldBy(Builder $query, int $departmentId): Builder
    {
        return $query->whereHas('trails', fn (Builder $trails) => $trails->where('department_id', $departmentId)->whereNull('released_at'));
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
