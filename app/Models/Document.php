<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    use HasFactory;

    protected $fillable = [
        'barcode', 'access_code', 'transaction_type_id', 'document_type_id',
        'classification_id', 'delivery_method_id', 'department_id',
        'source_type', 'source_location', 'source_name', 'gender',
        'contact_no', 'email', 'subject_matter', 'linked_documents', 'state',
        'status', 'due_date', 'completed_at', 'ai_routing_suggestions',
    ];

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
}