<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use HasFactory;
    protected $fillable = [
        'code',
        'name',
        'state',
    ];

    protected function casts(): array
    {
        return [
            'state' => 'integer',
        ];
    }

    public function users()
    {
        return $this->hasMany(User::class, 'department_id');
    }
}
