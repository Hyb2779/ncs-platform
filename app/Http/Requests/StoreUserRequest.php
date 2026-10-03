<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $allowed = match ($this->user()?->role?->value) {
            'owner' => ['superadmin', 'bayi', 'uye'],
            'superadmin' => ['bayi', 'uye'],
            'bayi' => ['uye'],
            default => [],
        };
        $role = in_array($this->input('role'), $allowed, true) ? $this->input('role') : ($allowed[0] ?? null);

        $rules = [
            'role' => ['nullable', Rule::in($allowed)],
            'parent' => ['nullable', 'integer'],
            'username' => ['required', 'string', 'max:64', 'alpha_dash', Rule::unique('users', 'username')],
            'password' => ['required', 'string', 'min:4', 'max:255'],
            'commission_rate' => $role === 'uye' ? ['exclude'] : ['nullable', 'numeric', 'min:0', 'max:100'],
            'user_limit' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];

        // Süperadmin ve bayi açılırken dil/para birimi seçilir; üye bayiden miras alır.
        if (in_array($role, ['superadmin', 'bayi'], true)) {
            $rules['language'] = ['required', Rule::in(['tr', 'en', 'de', 'ar'])];
            $rules['currency'] = ['required', Rule::in(['TRY', 'USD', 'EUR'])];
        } else {
            $rules['language'] = ['exclude'];
            $rules['currency'] = ['exclude'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'username.required' => __('panel.validation.username_required'),
            'username.unique' => __('panel.validation.username_unique'),
            'username.alpha_dash' => __('panel.validation.username_format'),
            'password.required' => __('panel.validation.password_required'),
            'password.min' => __('panel.validation.password_min'),
            'commission_rate.numeric' => __('panel.validation.commission_numeric'),
            'user_limit.integer' => __('panel.validation.user_limit_integer'),
            'language.required' => __('panel.validation.language_required'),
            'currency.required' => __('panel.validation.currency_required'),
        ];
    }

    public function parentUser(): User
    {
        $parent = $this->route('user');

        return $parent instanceof User ? $parent : $this->user();
    }
}
