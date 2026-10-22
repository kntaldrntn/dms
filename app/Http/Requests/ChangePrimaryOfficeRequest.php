<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/** Records or the Supervisory Level corrects the Primary office of a routed document. */
class ChangePrimaryOfficeRequest extends DocumentMutationRequest
{
    protected string $ability = 'changePrimary';

    public function rules(): array
    {
        return [
            'primary_office_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('state', 1)],
        ];
    }
}
