<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The Primary office receives a paper document by scanning (or typing) the barcode on the physical copy, which
 * shows the paper is in hand. The office and the `receive` policy are checked by TransactionService::receivePaper().
 */
class ReceivePaperDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->state === 1 && $this->user()->department_id !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['barcode' => trim((string) $this->input('barcode'))]);
    }

    public function rules(): array
    {
        return ['barcode' => ['required', 'string', 'max:64']];
    }

    public function messages(): array
    {
        return ['barcode.required' => 'Scan the barcode on the physical document.'];
    }
}
