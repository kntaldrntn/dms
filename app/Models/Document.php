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
        'classification_id', 'delivery_method_id', 'department_id',
        'current_assignee_id', 'requires_signature_from', 'approved_routing_slip',
        'source_type', 'source_location', 'source_name', 'gender',
        'contact_no', 'email', 'subject_matter', 'linked_documents', 'attached_file',
        'state', 'status', 'due_date', 'completed_at', 'ai_routing_suggestions', 'workflow_version',
    ];

    protected $appends = ['is_delayed', 'is_delinquent'];

    // --- Relationships ---
    public function transactionType() { return $this->belongsTo(TransactionType::class); }
    public function documentType() { return $this->belongsTo(DocumentType::class); }
    public function classification() { return $this->belongsTo(DocumentClassification::class); }
    public function department() { return $this->belongsTo(Department::class); }
    public function deliveryMethod() { return $this->belongsTo(DeliveryMethod::class); }
    public function trails() { return $this->hasMany(DocumentTrail::class)->orderBy('created_at', 'asc'); }
    
    // NEW: Links the document directly to the exact person responsible right now
    public function currentAssignee() { return $this->belongsTo(User::class, 'current_assignee_id'); }

    protected function casts(): array
    {
        return [
            'state' => 'integer',
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
        $startTime = $latestTrail ? ($latestTrail->released_at ?? $latestTrail->created_at) : $this->created_at;

        $standard = \App\Models\PerformanceStandard::where('department_id', $this->department_id)
            ->where('document_type_id', $this->document_type_id)
            ->first();

        $allocatedMinutes = $standard ? $standard->allocated_minutes : 7200;
        $minutesSpent = Carbon::parse($startTime, 'Asia/Manila')->diffInMinutes(Carbon::now('Asia/Manila'), true);

        return $minutesSpent > $allocatedMinutes;
    }
}
