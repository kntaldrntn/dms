<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/** A signer uploads the handwritten-signature image shown on the left of their PNPKI stamp. */
class SignatureImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->state === 1 && in_array($this->user()->role, User::SIGNER_ROLES, true);
    }

    public function rules(): array
    {
        return [
            'signature_image' => ['required', 'image', 'mimes:png,jpg,jpeg', 'max:2048'],
        ];
    }

    public function attributes(): array
    {
        return ['signature_image' => 'signature image'];
    }
}
