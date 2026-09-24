@extends('layouts.panel')

@section('heading', __('wallet.mint_title'))

@section('content')
    <form class="max-w-md rounded-lg bg-white p-4 text-start" method="POST" action="{{ route('panel.mint.store') }}" x-data="{ key: crypto.randomUUID() }">
        @csrf
        <input type="hidden" name="idempotency_key" :value="key">
        <label class="mb-2 block text-sm">{{ __('panel.fields.currency') }}
            <select class="mt-1 w-full rounded-md border border-[#E3E6EB] px-3 py-2" name="currency" required>
                @foreach (['TRY', 'USD', 'EUR'] as $currency)
                    <option value="{{ $currency }}">{{ $currency }}</option>
                @endforeach
            </select>
        </label>
        <label class="mb-2 block text-sm">{{ __('wallet.amount') }}
            <input class="mt-1 w-full rounded-md border border-[#E3E6EB] px-3 py-2 font-numeric" name="amount" inputmode="decimal" required>
        </label>
        <label class="mb-4 block text-sm">{{ __('wallet.note') }}
            <input class="mt-1 w-full rounded-md border border-[#E3E6EB] px-3 py-2" name="note" maxlength="2000">
        </label>
        @error('amount')
            <p class="mb-3 text-sm text-red-700">{{ $message }}</p>
        @enderror
        <button class="inline-flex h-10 items-center rounded-lg bg-[#161A22] px-3 text-sm text-white" type="submit">{{ __('wallet.mint_submit') }}</button>
    </form>
@endsection
