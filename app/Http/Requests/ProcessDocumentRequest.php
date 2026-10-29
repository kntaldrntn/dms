<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ProcessDocumentRequest extends DocumentMutationRequest
{
    protected string $ability = 'process';

    protected function prepareForValidation(): void
    {
        // A single next office may still be sent as a plain id.
        if ($this->filled('routed_to') && ! is_array($this->input('routed_to'))) {
            $this->merge(['routed_to' => [$this->input('routed_to')]]);
        }
    }

    public function rules(): array
    {
        return parent::rules() + [
            'action' => ['required', Rule::in(['end', 'route'])],
            'remarks' => ['required', 'string', 'max:1000'],
            'routed_to' => ['nullable', 'required_if:action,route', 'array', 'min:1', 'max:100'],
            'routed_to.*' => ['integer', 'distinct', Rule::exists('departments', 'id')->where('state', 1), Rule::notIn([$this->user()?->department_id])],
            'primary_office_id' => ['nullable', 'integer', Rule::in((array) $this->input('routed_to', []))],
            // Paper route: the Department Head signed the paper by hand; the scan of the signed page is optional.
            'head_signed' => ['sometimes', 'boolean'],
            'signed_scan' => ['exclude_unless:head_signed,true,1', 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return ['primary_office_id.in' => 'Choose the Primary office from the selected offices.'];
    }
}
