<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentTrail extends Model
{
    use HasFactory;

    /** The office that acts on the document. */
    public const PRIMARY = 'primary';

    /** A view-only copy: the office can receive and view the document but not act on it. */
    public const COPY = 'copy';

    protected $fillable = [
        'document_id',
        'department_id',
        'copy_role',
        'received_by',
        'received_at',
        'received_action',
        'released_by',
        'released_at',
        'released_to',
        'released_to_offices',
        'released_action',
        'remarks',
        'required_actions',
        'other_action',
        'concerned_personnel',
        'trail_type',
        'is_digitally_signed',
        'signed_by',
        'signature_mode',
        'signed_file_path',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'released_at' => 'datetime',
            'is_digitally_signed' => 'boolean', // Ensures clean JSON boolean outputs
            'required_actions' => 'array',
            'released_to_offices' => 'array',
        ];
    }

    // --- Relationships ---
    public function document() { return $this->belongsTo(Document::class); }
    public function office() { return $this->belongsTo(Department::class, 'department_id'); }
    public function receiver() { return $this->belongsTo(User::class, 'received_by'); }
    public function releaser() { return $this->belongsTo(User::class, 'released_by'); }
    public function releasedToOffice() { return $this->belongsTo(Department::class, 'released_to'); }
    
    // NEW: Proves exactly who attached their digital signature to this specific routing action
    public function signer() { return $this->belongsTo(User::class, 'signed_by'); }
}
