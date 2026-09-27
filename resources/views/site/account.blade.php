@extends('layouts.site')

@section('heading', __('site.account'))

@section('content')
    <p class="font-numeric text-2xl" data-balance>{{ $headerBalance }}</p>
    <a class="mt-3 inline-flex h-11 items-center rounded-lg border border-[var(--site-line)] px-3 text-sm font-semibold" href="{{ route('site.coupons') }}">{{ __('sport.my_coupons') }}</a>
    <p class="mt-2 text-sm text-[var(--site-muted)]">{{ __('site.language') }}: {{ auth()->user()->language->value }} · {{ __('site.currency') }}: {{ auth()->user()->currency->value }}</p>
    <form class="mt-4 flex flex-wrap gap-2" method="GET">
        <input class="h-11 rounded-md border border-[var(--site-line)] bg-[var(--site-panel)] px-3" type="date" name="from" value="{{ request('from') }}">
        <input class="h-11 rounded-md border border-[var(--site-line)] bg-[var(--site-panel)] px-3" type="date" name="to" value="{{ request('to') }}">
        <button class="inline-flex h-11 items-center rounded-lg border border-[var(--site-line)] px-3" type="submit">{{ __('panel.filter') }}</button>
    </form>
    <div class="mt-4 grid gap-3 md:hidden">
        @foreach ($rows as $row)
            <article class="rounded-lg bg-[var(--site-panel)] p-3">
                <p>{{ $row['when'] }}</p>
                <p>{{ $row['party'] }}</p>
                <p class="font-numeric">{{ $row['before'] }} {{ $row['amount'] }} {{ $row['after'] }}</p>
            </article>
        @endforeach
    </div>
    <div class="mt-4 hidden overflow-x-auto rounded-lg bg-[var(--site-panel)] md:block">
        <table class="w-full text-sm">
            @foreach ($rows as $row)
                <tr class="border-b border-[var(--site-line)]">
                    <td class="px-3 py-2">{{ $row['when'] }}</td>
                    <td class="px-3 py-2">{{ $row['party'] }}</td>
                    <td class="px-3 py-2 text-end font-numeric">{{ $row['before'] }}</td>
                    <td class="px-3 py-2 text-end font-numeric">{{ $row['amount'] }}</td>
                    <td class="px-3 py-2 text-end font-numeric">{{ $row['after'] }}</td>
                </tr>
            @endforeach
        </table>
    </div>
    <form class="mt-8 grid max-w-sm gap-3" method="POST" action="{{ route('site.password') }}">
        @csrf
        <h2 class="font-semibold">{{ __('site.password') }}</h2>
        <input class="h-11 rounded-md border border-[var(--site-line)] bg-[var(--site-panel-2)] px-3" type="password" name="current_password" placeholder="{{ __('site.current_password') }}" required>
        <input class="h-11 rounded-md border border-[var(--site-line)] bg-[var(--site-panel-2)] px-3" type="password" name="password" placeholder="{{ __('site.new_password') }}" required>
        <input class="h-11 rounded-md border border-[var(--site-line)] bg-[var(--site-panel-2)] px-3" type="password" name="password_confirmation" placeholder="{{ __('site.confirm_password') }}" required>
        @error('current_password')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
        <button class="inline-flex h-11 items-center justify-center rounded-lg bg-[var(--accent)] font-semibold text-[var(--site-on-accent)]" type="submit">{{ __('panel.save') }}</button>
    </form>
    <form class="mt-4" method="POST" action="{{ route('logout') }}">
        @csrf
        <button class="inline-flex h-11 items-center" type="submit">{{ __('site.logout') }}</button>
    </form>
@php
    $themeSwatches = ['classic' => ['#0E0E10', '#F5B83D', '#C8102E'], 'neon' => ['#0B0A1A', '#FF2E88', '#22D3EE'], 'desert' => ['#140D14', '#FF7A2F', '#B45CFF']];
    $ownTheme = auth()->user()->theme?->value;
@endphp
<section class="mt-4 rounded-xl bg-[var(--site-panel)] p-4 text-start">
    <h2 class="text-base font-bold text-[var(--site-text)]">{{ __('site.theme') }}</h2>
    <p class="mt-1 text-sm text-[var(--site-muted)]">{{ __('site.theme_hint') }}</p>
    <form class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4" method="POST" action="{{ route('site.theme') }}">
        @csrf
        <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-[var(--site-line)] p-3 has-[:checked]:border-[var(--accent)]">
            <input class="accent-[var(--accent)]" type="radio" name="theme" value="" @checked($ownTheme === null) onchange="this.form.submit()">
            <span class="text-sm text-[var(--site-text)]">{{ __('site.theme_default') }}</span>
        </label>
        @foreach ($themeSwatches as $key => $colors)
            <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-[var(--site-line)] p-3 has-[:checked]:border-[var(--accent)]">
                <input class="accent-[var(--accent)]" type="radio" name="theme" value="{{ $key }}" @checked($ownTheme === $key) onchange="this.form.submit()">
                <span class="flex gap-1">
                    @foreach ($colors as $color)
                        <span class="h-4 w-4 rounded-full border border-[var(--site-line)]" style="background: {{ $color }}"></span>
                    @endforeach
                </span>
                <span class="text-sm text-[var(--site-text)]">{{ __('site.themes.'.$key) }}</span>
            </label>
        @endforeach
    </form>
</section>
@endsection
