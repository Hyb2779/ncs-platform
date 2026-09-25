<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdjustBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/'],
            'direction' => ['required', 'in:add,remove'],
            'note' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => __('wallet.validation.amount_required'),
            'amount.regex' => __('wallet.validation.amount_invalid'),
            'direction.required' => __('wallet.validation.direction_required'),
            'note.max' => __('wallet.validation.note_max'),
            'idempotency_key.required' => __('wallet.validation.idempotency_required'),
            'idempotency_key.uuid' => __('wallet.validation.idempotency_required'),
        ];
    }
}
