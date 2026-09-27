@extends('layouts.panel')

@section('heading', __('panel.theme_title'))

@section('content')
@php
    $swatches = ['classic' => ['#0E0E10', '#F5B83D', '#C8102E'], 'neon' => ['#0B0A1A', '#FF2E88', '#22D3EE'], 'desert' => ['#140D14', '#FF7A2F', '#B45CFF']];
@endphp
<form class="grid max-w-2xl gap-3 text-start" method="POST" action="{{ route('panel.theme.update') }}">
    @csrf
    <p class="text-sm text-slate-600">{{ __('panel.theme_hint') }}</p>
    <div class="grid gap-2 sm:grid-cols-3">
        @foreach ($swatches as $key => $colors)
            <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-[#E3E6EB] bg-white p-3 has-[:checked]:border-[#161A22] has-[:checked]:ring-1 has-[:checked]:ring-[#161A22]">
                <input type="radio" name="theme" value="{{ $key }}" @checked($current === $key)>
                <span class="flex gap-1">
                    @foreach ($colors as $color)
                        <span class="h-5 w-5 rounded-full border border-[#E3E6EB]" style="background: {{ $color }}"></span>
                    @endforeach
                </span>
                <span class="text-sm font-medium">{{ __('site.themes.'.$key) }}</span>
            </label>
        @endforeach
    </div>
    <button class="inline-flex h-11 w-fit items-center rounded-lg bg-[#161A22] px-4 text-sm text-white" type="submit">{{ __('panel.theme_save') }}</button>
</form>
@endsection
