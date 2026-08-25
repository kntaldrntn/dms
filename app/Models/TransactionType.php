<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'processing_days',
        'state',
    ];

    protected function casts(): array
    {
        return [
            'state' => 'integer',
        ];
    }
}
