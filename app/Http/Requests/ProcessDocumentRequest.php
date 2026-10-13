<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ProcessDocumentRequest extends DocumentMutationRequest
{
    protected string $ability = 'process';

    public function rules(): array
    {
        return parent::rules() + [
            'action' => ['required', Rule::in(['end', 'route'])],
            'remarks' => ['required', 'string', 'max:1000'],
            'routed_to' => ['nullable', 'required_if:action,route', 'integer', Rule::exists('departments', 'id')->where('state', 1), Rule::notIn([$this->user()?->department_id])],
            'attach_signature' => ['sometimes', 'boolean'],
        ];
    }
}
