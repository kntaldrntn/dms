<?php

namespace App\Http\Requests;

use App\Models\DelinquencyTag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TagDelinquencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('tagDelinquency', $this->route('document'));
    }

    public function rules(): array
    {
        return [
            'tag' => ['required', Rule::in(array_keys(DelinquencyTag::TAGS))],
            'note' => ['required', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'tag.required' => 'Choose a tag.',
            'tag.in' => 'Choose one of the listed tags.',
            'note.required' => 'Write a short note for the office, e.g. who you talked to or why it is late.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['note' => trim((string) $this->input('note'))]);
    }
}
