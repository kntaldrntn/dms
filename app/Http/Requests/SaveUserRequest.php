<?php

namespace App\Http\Requests;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * Add / edit a user (User Management, System Administrator only).
 *
 * FGD slide 9 ties every role but the System Administrator to an office: Workstation staff and Department Heads see
 * and act for their own department, and the ADM / OCM approval queues and Records intake go by office, so a City
 * Administrator outside ADM (or a Mayor outside OCM, a Records Manager outside REC) would never see their work.
 */
class SaveUserRequest extends FormRequest
{
    public const ROLES = ['staff', 'department_head', 'city_administrator', 'city_mayor', 'records_manager', 'system_administrator'];

    /** Roles that only make sense in one office, by department code. */
    public const ROLE_OFFICES = [
        'city_administrator' => ['code' => 'ADM', 'label' => 'City Administrator'],
        'city_mayor' => ['code' => 'OCM', 'label' => 'City Mayor'],
        'records_manager' => ['code' => 'REC', 'label' => 'Records Manager'],
    ];

    public function authorize(): bool
    {
        return $this->user()?->role === 'system_administrator';
    }

    public function rules(): array
    {
        $editing = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($editing?->id)],
            'role' => ['required', Rule::in(self::ROLES)],
            'sex' => ['nullable', Rule::in(['Male', 'Female'])],
            'state' => ['required', 'in:0,1'],
            'department_id' => ['required_unless:role,system_administrator', 'nullable', 'exists:departments,id'],
            // Blank on edit keeps the current password.
            'password' => [$editing ? 'nullable' : 'required', 'confirmed', Password::defaults()],
            'can_sign_for_head' => ['sometimes', 'boolean'],
            // Only for people who sign with PNPKI: the signing roles and staff authorized to sign for the head.
            'signature' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'department_id.required_unless' => 'Choose the department this user works in. Only a System Administrator may have none.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $role = $this->input('role');
            $office = self::ROLE_OFFICES[$role] ?? null;
            if ($office && Department::whereKey($this->integer('department_id'))->value('code') !== $office['code']) {
                $name = Department::where('code', $office['code'])->value('name') ?? $office['code'];
                $validator->errors()->add('department_id', "A {$office['label']} must belong to {$name} ({$office['code']}).");
            }

            if ($this->hasFile('signature') && ! $this->signs()) {
                $validator->errors()->add('signature', 'Only users who sign documents need a signature image.');
            }
        }];
    }

    /** Whether this user will sign with PNPKI (the signature image goes on that stamp). */
    public function signs(): bool
    {
        return in_array($this->input('role'), User::SIGNER_ROLES, true)
            || ($this->input('role') === 'staff' && $this->boolean('can_sign_for_head'));
    }
}
