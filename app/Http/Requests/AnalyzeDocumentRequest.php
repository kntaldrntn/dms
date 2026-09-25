<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;

class AnalyzeDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Document::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'ai_thumbnail' => ['required', 'file', 'mimes:jpg,jpeg', 'max:5120'],
            'extracted_text' => ['required', 'string', 'max:100000'],
        ];
    }
}
