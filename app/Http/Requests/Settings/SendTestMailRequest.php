<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class SendTestMailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->state === 1 && $this->user()->role === 'system_administrator';
    }

    public function rules(): array
    {
        return [
            'test_address' => ['required', 'email', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return ['test_address' => 'test email address'];
    }
}
