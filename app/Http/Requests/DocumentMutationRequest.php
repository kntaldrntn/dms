<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;

class DocumentMutationRequest extends FormRequest
{
    protected string $ability = 'delete';

    public function authorize(): bool
    {
        $document = $this->route('document');

        return $document instanceof Document && ($this->user()?->can($this->ability, $document) ?? false);
    }

    public function rules(): array
    {
        return ['workflow_version' => ['required', 'integer', 'min:0']];
    }
}
