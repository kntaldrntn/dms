<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/** A signer uploads their own PNPKI certificate; the PIN only checks that it opens. */
class SigningCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->state === 1 && in_array($this->user()->role, User::SIGNER_ROLES, true);
    }

    public function rules(): array
    {
        return [
            'certificate' => ['required', 'file', 'extensions:p12,pfx', 'max:64'],
            'certificate_pin' => ['required', 'string', 'max:100'],
        ];
    }

    public function attributes(): array
    {
        return ['certificate_pin' => 'certificate PIN'];
    }
}
