<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class DispatchDocumentRequest extends DocumentMutationRequest
{
    protected string $ability = 'dispatch';

    public function rules(): array
    {
        return parent::rules() + [
            'routed_to' => ['required', 'array', 'min:1', 'max:100'],
            'routed_to.*' => ['required', 'integer', 'distinct', Rule::exists('departments', 'id')->where('state', 1), Rule::notIn([$this->route('document')->department_id])],
            // Omitted: the first selected office is the Primary office.
            'primary_office_id' => ['nullable', 'integer', Rule::in((array) $this->input('routed_to', []))],
        ];
    }

    public function messages(): array
    {
        return ['primary_office_id.in' => 'Choose the Primary office from the selected offices.'];
    }
}
