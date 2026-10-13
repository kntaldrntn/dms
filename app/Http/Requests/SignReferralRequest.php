<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class SignReferralRequest extends DocumentMutationRequest
{
    protected string $ability = 'signReferral';

    public function rules(): array
    {
        return parent::rules() + [
            'signature_mode' => ['required', Rule::in(['electronic', 'physical'])],
        ];
    }
}
