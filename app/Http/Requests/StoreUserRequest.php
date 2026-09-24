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
        $rules = [
            'username' => ['required', 'string', 'max:64', 'alpha_dash', Rule::unique('users', 'username')],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'commission_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'user_limit' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];

        if ($this->user()?->role === UserRole::Owner) {
            $rules['language'] = ['required', Rule::in(['tr', 'en', 'de', 'ar'])];
            $rules['currency'] = ['required', Rule::in(['TRY', 'USD', 'EUR'])];
            $rules['timezone'] = ['required', 'timezone'];
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
            'commission_rate.required' => __('panel.validation.commission_required'),
            'commission_rate.numeric' => __('panel.validation.commission_numeric'),
            'user_limit.integer' => __('panel.validation.user_limit_integer'),
            'language.required' => __('panel.validation.language_required'),
            'currency.required' => __('panel.validation.currency_required'),
            'timezone.required' => __('panel.validation.timezone_required'),
            'timezone.timezone' => __('panel.validation.timezone_invalid'),
        ];
    }

    public function parentUser(): User
    {
        $parent = $this->route('user');

        return $parent instanceof User ? $parent : $this->user();
    }
}
