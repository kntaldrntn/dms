<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentType extends Model
{
    use HasFactory;
    protected $fillable = [
        'document_code',
        'document_type',
        'state',
    ];

    protected function casts(): array
    {
        return [
            'state' => 'integer',
        ];
    }
}
