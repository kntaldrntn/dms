<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** People directory entry: an office head or frequent signatory (see the create_people_table migration). */
class Person extends Model
{
    protected $fillable = ['title', 'first_name', 'middle_initial', 'last_name', 'suffix', 'position', 'department_id', 'state'];

    protected $appends = ['full_name'];

    protected function casts(): array
    {
        return ['state' => 'integer', 'department_id' => 'integer'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** "Atty. Gilbert L. Caloza, CPA": the form the intake sender field uses. */
    protected function fullName(): Attribute
    {
        return Attribute::get(function () {
            $initial = $this->middle_initial ? rtrim($this->middle_initial, '.').'.' : null;
            $name = implode(' ', array_filter([$this->title, $this->first_name, $initial, $this->last_name]));

            return $this->suffix ? "{$name}, {$this->suffix}" : $name;
        });
    }
}
