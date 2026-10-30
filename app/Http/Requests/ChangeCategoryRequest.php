<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/** Records, the System Administrator or the Supervisory Level changes a document's category. */
class ChangeCategoryRequest extends DocumentMutationRequest
{
    protected string $ability = 'changeCategory';

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'transaction_type_id' => ['required', 'integer', Rule::exists('transaction_types', 'id')->where('state', 1)],
        ];
    }

    public function attributes(): array
    {
        return ['transaction_type_id' => 'category'];
    }
}
