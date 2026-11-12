<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AcknowledgeDelinquencyTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->route('document');
        $tag = $this->route('tag');

        return $this->user()->can('acknowledgeDelinquencyTag', [$document, $tag]);
    }

    public function rules(): array
    {
        return [];
    }
}
