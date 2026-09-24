<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MintCreditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === \App\Enums\UserRole::Owner;
    }

    public function rules(): array
    {
        return [
            'currency' => ['required', Rule::in(['TRY', 'USD', 'EUR'])],
            'amount' => ['required', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/'],
            'note' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'currency.required' => __('wallet.validation.currency_required'),
            'amount.required' => __('wallet.validation.amount_required'),
            'amount.regex' => __('wallet.validation.amount_invalid'),
            'note.max' => __('wallet.validation.note_max'),
        ];
    }
}
