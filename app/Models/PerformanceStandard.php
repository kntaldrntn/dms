<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PerformanceStandard extends Model
{
    use HasFactory;

    protected $fillable = [
        'department_id',
        'document_type_id',
        'allocated_minutes',
    ];
}