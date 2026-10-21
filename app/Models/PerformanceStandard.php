<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PerformanceStandard extends Model
{
    use HasFactory;

    /** Used when an office has no standard for a document type (FGD: 24 h, pending CART calibration). */
    public const DEFAULT_ALLOCATED_MINUTES = 1440;

    protected $fillable = [
        'department_id',
        'document_type_id',
        'allocated_minutes',
    ];
}