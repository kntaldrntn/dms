<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentClassification extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'state',
    ];

    protected function casts(): array
    {
        return [
            'state' => 'integer',
        ];
    }
}