<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveDocumentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_int($this->input('source_location'))) {
            $this->merge(['source_location' => (string) $this->input('source_location')]);
        }
    }

    public function authorize(): bool
    {
        $document = $this->route('document');

        return $document instanceof Document
            ? ($this->user()?->can('update', $document) ?? false)
            : ($this->user()?->can('create', Document::class) ?? false);
    }

    public function rules(): array
    {
        $document = $this->route('document');

        return [
            'workflow_version' => $document ? ['required', 'integer', 'min:0'] : ['prohibited'],
            'barcode' => ['required', 'string', 'max:50', Rule::unique('documents', 'barcode')->ignore($document)],
            'transaction_type_id' => ['required', 'integer', Rule::exists('transaction_types', 'id')->where('state', 1)],
            'document_type_id' => ['required', 'integer', Rule::exists('document_types', 'id')->where('state', 1)],
            'source_type' => ['required', Rule::in(['Internal', 'External'])],
            'source_location' => ['nullable', 'string', 'max:255'],
            'source_name' => ['required', 'string', 'max:255'],
            'delivery_method_id' => ['nullable', 'integer', Rule::exists('delivery_methods', 'id')->where('state', 1)],
            'gender' => ['nullable', Rule::in(['Male', 'Female'])],
            'contact_no' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'subject_matter' => ['required', 'string'],
            'classification_id' => ['nullable', 'integer', Rule::exists('document_classifications', 'id')->where('state', 1)],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('state', 1)],
            'access_code' => ['required', 'string', 'max:20'],
            'ai_routing_suggestions' => ['nullable', 'array', 'max:10'],
            'ai_routing_suggestions.*.department_id' => ['required', 'integer'],
            'ai_routing_suggestions.*.priority' => ['nullable', 'string', 'max:20'],
            'ai_routing_suggestions.*.reason' => ['nullable', 'string', 'max:1000'],
            'linked_documents' => ['nullable', 'string'],
            'attached_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }
}
