<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentTrail extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id', 'department_id', 'received_by', 'received_at', 'received_action',
        'released_by', 'released_at', 'released_action', 'released_to', 'remarks'
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    // Relationships
    public function document() { return $this->belongsTo(Document::class); }
    public function office() { return $this->belongsTo(Department::class, 'department_id'); }
    public function receiver() { return $this->belongsTo(User::class, 'received_by'); }
    public function releaser() { return $this->belongsTo(User::class, 'released_by'); }
    public function releasedToOffice() { return $this->belongsTo(Department::class, 'released_to'); }
}