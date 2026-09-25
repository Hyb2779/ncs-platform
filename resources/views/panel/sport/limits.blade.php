@extends('layouts.panel')

@section('heading', __('sport.panel.limits'))

@section('content')
    <form class="grid max-w-lg gap-3 rounded-lg bg-white p-4" method="POST" action="{{ route('panel.sport.limits.update') }}">
        @csrf
        @method('PUT')
        @foreach (['min_stake', 'max_stake', 'max_win', 'combo_min', 'combo_max', 'min_total_odds', 'min_odd', 'daily_max', 'cancel_minutes'] as $field)
            <label class="grid gap-1 text-sm">
                <span>{{ __('sport.panel.limit_fields.'.$field) }}</span>
                <input class="h-11 rounded-md border px-3" name="{{ $field }}" value="{{ old($field, $limit->{$field}) }}">
            </label>
        @endforeach
        <button class="inline-flex h-11 items-center justify-center rounded-lg border" type="submit">{{ __('sport.panel.save') }}</button>
    </form>
@endsection
