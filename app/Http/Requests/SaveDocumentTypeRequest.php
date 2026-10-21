<?php

namespace App\Http\Requests;

use App\Models\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Document type + its FGD rulebook entry. Access is gated by the registry route's role middleware. */
class SaveDocumentTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_code' => ['required', 'string', 'max:50'],
            'document_type' => ['required', 'string', 'max:100'],
            'state' => ['required', 'in:0,1'],
            'signature_type' => ['nullable', Rule::in(DocumentType::SIGNATURE_TYPES)],
            'may_be_confidential' => ['sometimes', 'boolean'],
            'default_transaction_type_id' => ['nullable', 'integer', Rule::exists('transaction_types', 'id')->where('state', 1)],
            'rulebook_remarks' => ['nullable', 'string', 'max:255'],
            'classification_ids' => ['sometimes', 'array'],
            'classification_ids.*' => ['integer', 'distinct', Rule::exists('document_classifications', 'id')->where('state', 1)],
        ];
    }

    public function attributes(): array
    {
        return [
            'default_transaction_type_id' => 'default category',
            'classification_ids.*' => 'classification',
        ];
    }
}
