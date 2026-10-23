<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** People directory entry. Access is gated by the registry route's role middleware. */
class SavePersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:30'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_initial' => ['nullable', 'string', 'max:5', 'regex:/^\p{L}{1,2}\.?$/u'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:30'],
            'position' => ['nullable', 'string', 'max:150'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('state', 1)],
            'state' => ['required', 'in:0,1'],
        ];
    }

    public function attributes(): array
    {
        return ['department_id' => 'office'];
    }
}
