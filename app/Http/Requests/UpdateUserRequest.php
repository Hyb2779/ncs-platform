<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'status' => ['required', Rule::in(['active', 'passive', 'banned'])],
            'commission_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'user_limit' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.min' => __('panel.validation.password_min'),
            'status.required' => __('panel.validation.status_required'),
            'commission_rate.required' => __('panel.validation.commission_required'),
            'commission_rate.numeric' => __('panel.validation.commission_numeric'),
            'user_limit.integer' => __('panel.validation.user_limit_integer'),
        ];
    }
}
