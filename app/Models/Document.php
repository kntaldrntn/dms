<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Document extends Model
{
    use HasFactory;

    protected $fillable = [
        'barcode', 'access_code', 'transaction_type_id', 'document_type_id',
        'classification_id', 'delivery_method_id', 'department_id',
        'source_type', 'source_location', 'source_name', 'gender',
        'contact_no', 'email', 'subject_matter', 'linked_documents', 'attached_file', // <--- Added attached_file here!
        'state', 'status', 'due_date', 'completed_at', 'ai_routing_suggestions',
    ];

    // Tell Laravel to automatically append these calculated fields to the JSON payload
    protected $appends = ['is_delayed', 'is_delinquent'];

    // Relationships
    public function transactionType() {
        return $this->belongsTo(TransactionType::class);
    }
    public function documentType() {
        return $this->belongsTo(DocumentType::class);
    }
    public function classification() {
        return $this->belongsTo(DocumentClassification::class);
    }
    public function department() {
        return $this->belongsTo(Department::class);
    }
    public function deliveryMethod() {
        return $this->belongsTo(DeliveryMethod::class);
    }
    public function trails() {
        return $this->hasMany(DocumentTrail::class)->orderBy('created_at', 'asc');
    }
    protected function casts(): array
    {
        return [
            'state' => 'integer',
            'due_date' => 'datetime',
            'completed_at' => 'datetime',
            'ai_routing_suggestions' => 'array',
        ];
    }

    // =====================================================================
    // SMART E.O.D.B. STATUS TRACKERS
    // =====================================================================

    /**
     * DELAYED: Has the document missed its overall Transaction Type SLA? (3, 7, 20 days)
     */
    public function getIsDelayedAttribute()
    {
        if ($this->status === 'Completed' || !$this->due_date) {
            return false;
        }
        
        // If the current time is past the global due date, it is delayed
        return Carbon::now('Asia/Manila')->greaterThan($this->due_date);
    }

    /**
     * DELINQUENT: Has the CURRENT OFFICE exceeded their specific allotted minutes?
     */
    public function getIsDelinquentAttribute()
    {
        if ($this->status === 'Completed' || !$this->department_id) {
            return false;
        }

        // 1. Find when this document arrived at the CURRENT office
        // We look for the latest trail where it was "released_to" this department
        $latestTrail = $this->trails()->where('released_to', $this->department_id)->latest()->first();

        // If there's no trail routing it to them, they are the origin. Use document creation time.
        $startTime = $latestTrail ? ($latestTrail->released_at ?? $latestTrail->created_at) : $this->created_at;

        // 2. Look up the allocated minutes in your Performance Standards matrix
        // Assuming your standard model is named PerformanceStandard
        $standard = \App\Models\PerformanceStandard::where('department_id', $this->department_id)
            ->where('document_type_id', $this->document_type_id)
            ->first();

        // Fallback to 7200 minutes (5 working days) if no standard was explicitly set in the matrix
        $allocatedMinutes = $standard ? $standard->allocated_minutes : 7200;

        // 3. Calculate absolute minutes spent in the current office
        // Note: For extreme precision later, you can swap diffInMinutes for a function that skips weekends!
        $minutesSpent = Carbon::now('Asia/Manila')->diffInMinutes($startTime);

        return $minutesSpent > $allocatedMinutes;
    }
}