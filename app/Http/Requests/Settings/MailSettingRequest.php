<?php

namespace App\Http\Requests\Settings;

use App\Models\MailSetting;
use App\Services\MailSettingsService;
use Illuminate\Foundation\Http\FormRequest;

/** The System Administrator sets the SMTP account; a blank password keeps the saved one (or the .env one). */
class MailSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->state === 1 && $this->user()->role === 'system_administrator';
    }

    public function rules(): array
    {
        return [
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'username' => ['required', 'string', 'max:255'],
            // Required only the first time, and only when .env has no MAIL_PASSWORD to fall back on.
            'password' => [
                MailSetting::query()->exists() || app(MailSettingsService::class)->envPassword() ? 'nullable' : 'required',
                'string',
                'max:255',
            ],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'host' => 'SMTP host',
            'from_address' => 'sender email',
            'from_name' => 'sender name',
        ];
    }
}
