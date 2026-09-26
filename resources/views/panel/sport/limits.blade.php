@extends('layouts.panel')

@section('heading', __('sport.panel.limits'))

@section('content')
    @if (auth()->user()->role->value === 'owner')
        <div class="mb-4 flex gap-2">
            @foreach (['TRY', 'USD', 'EUR'] as $code)
                <a class="inline-flex h-10 items-center rounded-lg border px-3 text-sm {{ $currency->value === $code ? 'bg-[#161A22] text-white' : '' }}" href="{{ route('panel.sport.limits', ['currency' => $code]) }}">{{ $code }}</a>
            @endforeach
        </div>
    @endif
    <form class="grid max-w-xl gap-6 rounded-lg bg-white p-4" method="POST" action="{{ route('panel.sport.limits.update') }}">
        @csrf
        @method('PUT')
        <input type="hidden" name="currency" value="{{ $currency->value }}">
        @foreach ($groups as $group => $fields)
            <fieldset class="grid gap-3">
                <legend class="text-sm font-semibold">{{ __('sport.panel.limit_groups.'.$group) }}</legend>
                @foreach ($fields as $field)
                    @php
                        $cap = $ceiling->get($field);
                        $own = $limit->exists ? $limit->{$field} : $cap;
                        $open = $cap === null && (string) old('unlimited.'.$field, $own === null ? '1' : '') === '1';
                    @endphp
                    @if ($field === 'cash_out_enabled')
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="cash_out_enabled" value="1" @checked(old('cash_out_enabled', $limit->exists ? $limit->cash_out_enabled : false)) @disabled($cap !== true && auth()->user()->role->value !== 'owner')>
                            <span>{{ __('sport.panel.limit_fields.cash_out_enabled') }}</span>
                        </label>
                        @continue
                    @endif
                    <label class="grid gap-1 text-sm" x-data="{ open: {{ $open ? 'true' : 'false' }} }">
                        <span>{{ __('sport.panel.limit_fields.'.$field) }}</span>
                        <input class="h-11 rounded-md border px-3" name="{{ $field }}" value="{{ $open ? '' : old($field, $own) }}" x-bind:disabled="open">
                        <span class="flex items-center gap-2 text-xs text-slate-500">
                            <input type="checkbox" name="unlimited[{{ $field }}]" value="1" x-model="open" @disabled($cap !== null)>
                            {{ __('sport.panel.unlimited') }}
                        </span>
                        <span class="text-xs text-slate-500">
                            @if ($cap === null)
                                {{ __('sport.panel.ceiling_unlimited') }}
                            @else
                                {{ __(in_array($field, ['min_stake', 'min_coupon_odds', 'min_odds_prematch', 'min_odds_live'], true) ? 'sport.panel.floor' : 'sport.panel.ceiling', ['value' => $cap]) }}
                            @endif
                        </span>
                    </label>
                    @error($field)
                        <p class="text-sm text-red-700">{{ $message }}</p>
                    @enderror
                @endforeach
            </fieldset>
        @endforeach
        <button class="inline-flex h-11 items-center justify-center rounded-lg border" type="submit">{{ __('sport.panel.save') }}</button>
    </form>
    <form class="mt-3" method="POST" action="{{ route('panel.sport.limits.restore') }}">
        @csrf
        <input type="hidden" name="currency" value="{{ $currency->value }}">
        <button class="inline-flex h-11 items-center rounded-lg border px-3" type="submit">{{ __('sport.panel.restore') }}</button>
    </form>
@endsection
