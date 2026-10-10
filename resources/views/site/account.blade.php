@extends('layouts.site')

@section('heading', __('site.account'))

@section('content')
    @unless (auth()->user()->must_change_password)
    <p class="font-numeric text-2xl" data-balance>{{ $headerBalance }}</p>
    @php($sportLink = site_sport_link(auth()->user()))
    @if (auth()->user()->role === \App\Enums\UserRole::Uye || $sportLink)
        <nav class="account-menu" aria-label="{{ __('site.account') }}">
            @if (auth()->user()->role === \App\Enums\UserRole::Uye)
                <a href="{{ route('site.account.movements') }}">
                    <span>{{ __('account.menu') }}</span>
                    <span class="rtl:rotate-180" aria-hidden="true">&rarr;</span>
                </a>
            @endif
            @if ($sportLink)
                <a href="{{ route($sportLink['route'] === 'site.sport' ? 'site.coupons' : 'site.wegas_coupons') }}">
                    <span>{{ __('sport.my_coupons') }}</span>
                    <span class="rtl:rotate-180" aria-hidden="true">&rarr;</span>
                </a>
            @endif
        </nav>
    @endif
    @endunless
    @if (auth()->user()->must_change_password)
        <p class="mb-3 text-sm">{{ __('panel.password_must_change') }}</p>
    @endif
    <form class="mt-8 grid max-w-sm gap-3" method="POST" action="{{ route('site.password') }}">
        @csrf
        <h2 class="font-semibold">{{ __('site.password') }}</h2>
        <input class="h-11 rounded-md border border-[var(--site-line)] bg-[var(--site-panel-2)] px-3" type="password" name="current_password" placeholder="{{ __('site.current_password') }}" required>
        <input class="h-11 rounded-md border border-[var(--site-line)] bg-[var(--site-panel-2)] px-3" type="password" name="password" placeholder="{{ __('site.new_password') }}" required>
        <input class="h-11 rounded-md border border-[var(--site-line)] bg-[var(--site-panel-2)] px-3" type="password" name="password_confirmation" placeholder="{{ __('site.confirm_password') }}" required>
        @error('current_password')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
        @error('password')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
        <button class="inline-flex h-11 items-center justify-center rounded-lg bg-[var(--accent)] font-semibold text-[var(--site-on-accent)]" type="submit">{{ __('panel.save') }}</button>
    </form>
@unless (auth()->user()->must_change_password)
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
@endunless
<form class="account-menu" method="POST" action="{{ route('logout') }}">
    @csrf
    <button class="account-logout" type="submit">
        <span>{{ __('site.logout') }}</span>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10 7V6a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-7a2 2 0 0 1-2-2v-1"></path><path d="M15 12H3m0 0 3-3m-3 3 3 3"></path></svg>
    </button>
</form>
@endsection
