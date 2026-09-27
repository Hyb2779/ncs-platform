@extends('layouts.site')

@section('heading', brand()->name())

@section('mainClass', 'mx-auto w-full max-w-[90rem] px-4 py-4 md:px-6 md:py-6')

@section('content')
@php
    $oddButton = fn ($odd, $label, $accent = false) => $odd;
@endphp
<div class="flex flex-col gap-5 md:gap-7">

    <section class="grid overflow-hidden rounded-2xl border border-[var(--site-line)] bg-[var(--site-panel)] lg:grid-cols-[minmax(0,1fr)_28rem]">
        <div class="flex flex-col justify-center gap-4 p-6 md:p-12">
            <span class="self-start rounded-lg bg-[var(--site-panel-2)] px-3 py-1.5 text-xs font-extrabold tracking-wider text-[var(--accent)]">{{ __('home.hero_badge') }}</span>
            <h1 class="font-numeric text-4xl font-bold leading-none text-[var(--site-text)] md:text-6xl">{{ __('home.hero_title', ['brand' => brand()->name()]) }}</h1>
            <p class="max-w-xl text-[15px] leading-relaxed text-[var(--site-muted)] md:text-[17px]">{{ __('home.hero_text') }}</p>
            <div class="flex flex-wrap gap-3">
                <a class="inline-flex h-12 items-center rounded-xl bg-[var(--accent)] px-6 text-[15px] font-extrabold text-[var(--site-on-accent)]" href="{{ route('site.sport') }}">{{ __('home.go_bulletin') }}</a>
                <a class="inline-flex h-12 items-center rounded-xl border border-[var(--site-line)] px-6 text-[15px] font-bold text-[var(--site-text)]" href="{{ route('site.sport.live') }}">{{ __('home.live_matches') }}</a>
            </div>
        </div>
        @if ($featured)
            <div class="flex flex-col justify-center gap-3 bg-[var(--site-panel-2)] p-6 md:p-8">
                <div class="flex justify-between text-[13px] font-bold text-[var(--site-muted)]"><span>{{ $featured['league'] }}</span><span>{{ __('home.today') }} {{ $featured['time'] }}</span></div>
                <div class="flex flex-col gap-1 text-xl font-extrabold text-[var(--site-text)] md:text-2xl"><span>{{ $featured['home'] }}</span><span>{{ $featured['away'] }}</span></div>
                @include('site._home_odds', ['m' => $featured, 'size' => 'lg'])
            </div>
        @endif
    </section>

    @if ($dailyGames->isNotEmpty())
        <section class="flex flex-col gap-4">
            <div class="flex items-center justify-between"><h2 class="text-lg font-extrabold text-[var(--site-text)] md:text-xl">{{ __('home.daily_games') }}</h2><a class="text-[13px] font-bold text-[var(--accent)]" href="{{ route('site.slots') }}">{{ __('home.show_all') }}</a></div>
            <div class="no-scrollbar flex gap-3 overflow-x-auto md:grid md:grid-cols-6 md:gap-4 md:overflow-visible">
                @foreach ($dailyGames as $game)
                    <div class="w-40 shrink-0 md:w-auto">@include('site._card', ['game' => $game])</div>
                @endforeach
            </div>
        </section>
    @endif

    <section class="grid grid-cols-2 gap-3 md:grid-cols-4 md:gap-4">
        @foreach ([
            ['route' => 'site.sport.live', 'title' => __('home.quick_live'), 'sub' => __('home.quick_live_sub', ['count' => $quick['live']])],
            ['route' => 'site.sport', 'title' => __('home.quick_sport'), 'sub' => __('home.quick_sport_sub', ['count' => $quick['today']])],
            ['route' => 'site.slots', 'title' => __('home.quick_slot'), 'sub' => __('home.quick_slot_sub', ['count' => number_format($quick['slots'], 0, ',', '.')])],
            ['route' => 'site.live_casino', 'title' => __('home.quick_casino'), 'sub' => $quick['casino'] > 0 ? __('home.quick_slot_sub', ['count' => $quick['casino']]) : __('home.soon')],
        ] as $tile)
            <a class="flex h-20 items-center justify-between rounded-2xl border border-[var(--site-line)] bg-[var(--site-panel)] px-4 md:h-24 md:px-5" href="{{ route($tile['route']) }}">
                <span class="flex flex-col gap-1">
                    <span class="text-[15px] font-extrabold text-[var(--site-text)] md:text-lg">{{ $tile['title'] }}</span>
                    <span class="text-xs text-[var(--site-muted)] md:text-[13px]">{{ $tile['sub'] }}</span>
                </span>
                <span class="hidden h-11 w-11 items-center justify-center rounded-xl bg-[var(--site-panel-2)] text-xl font-extrabold text-[var(--accent)] md:flex rtl:rotate-180" aria-hidden="true">&rarr;</span>
            </a>
        @endforeach
    </section>

    <section class="grid gap-4 lg:grid-cols-3 lg:gap-5">
        <div class="order-2 flex flex-col gap-3 rounded-2xl border border-[var(--site-line)] bg-[var(--site-panel)] p-4 md:p-5 lg:order-1">
            <div class="flex items-center justify-between"><h2 class="text-lg font-extrabold text-[var(--site-text)] md:text-xl">{{ __('home.upcoming') }}</h2><a class="text-[13px] font-bold text-[var(--accent)]" href="{{ route('site.sport') }}">{{ __('home.all') }}</a></div>
            @forelse ($upcoming as $m)
                <a class="flex gap-3 rounded-xl bg-[var(--site-panel-2)] p-3" href="{{ route('site.sport.show', $m['id']) }}">
                    <span class="flex w-14 flex-col"><span class="text-xs text-[var(--site-muted)]">{{ __('home.today') }}</span><span class="font-numeric text-xl font-bold text-[var(--site-text)]">{{ $m['time'] }}</span></span>
                    <span class="flex min-w-0 flex-col"><span class="truncate text-[15px] font-bold text-[var(--site-text)]">{{ $m['home'] }} - {{ $m['away'] }}</span><span class="truncate text-xs text-[var(--site-muted)]">{{ $m['league'] }}</span></span>
                </a>
            @empty
                <p class="text-sm text-[var(--site-muted)]">{{ __('home.empty') }}</p>
            @endforelse
        </div>

        <div class="order-3 flex flex-col gap-3 rounded-2xl border border-[var(--site-line)] bg-[var(--site-panel)] p-4 md:p-5 lg:order-2">
            <div class="flex items-center justify-between"><h2 class="text-lg font-extrabold text-[var(--site-text)] md:text-xl">{{ __('home.popular') }}</h2><a class="text-[13px] font-bold text-[var(--accent)]" href="{{ route('site.sport') }}">{{ __('home.all') }}</a></div>
            @forelse ($popular as $m)
                <div class="flex flex-col gap-2.5 rounded-xl bg-[var(--site-panel-2)] p-3">
                    <div class="flex justify-between gap-2"><span class="truncate text-[15px] font-bold text-[var(--site-text)]">{{ $m['home'] }} - {{ $m['away'] }}</span><span class="font-numeric text-[17px] font-bold text-[var(--site-muted)]">{{ $m['time'] }}</span></div>
                    @include('site._home_odds', ['m' => $m, 'size' => 'sm'])
                </div>
            @empty
                <p class="text-sm text-[var(--site-muted)]">{{ __('home.empty') }}</p>
            @endforelse
        </div>

        @if ($combo)
            <form class="order-1 flex flex-col gap-3 rounded-2xl border border-[var(--accent)] bg-[var(--site-panel)] p-4 md:p-5 lg:order-3" method="POST" action="{{ route('site.sport.combo') }}">
                @csrf
                <h2 class="text-lg font-extrabold text-[var(--site-text)] md:text-xl">{{ __('home.combo') }}</h2>
                <div class="flex items-baseline justify-between px-0.5"><span class="text-sm font-bold text-[var(--site-muted)]">{{ __('home.combo_count', ['count' => count($combo['rows'])]) }}</span><span class="font-numeric text-3xl font-bold text-[var(--accent)]">{{ $combo['total'] }}</span></div>
                @foreach ($combo['rows'] as $row)
                    <input type="hidden" name="odds[]" value="{{ $row['odd']->id }}">
                    <div class="flex items-center justify-between rounded-xl bg-[var(--site-panel-2)] px-3 py-2.5">
                        <span class="flex min-w-0 flex-col"><span class="truncate text-sm font-bold text-[var(--site-text)]">{{ $row['match']['home'] }} - {{ $row['match']['away'] }}</span><span class="text-xs text-[var(--site-muted)]">{{ $row['label'] }} · {{ $row['match']['time'] }}</span></span>
                        <span class="font-numeric text-xl font-bold text-[var(--accent)]">{{ $row['odd']->shown_odd }}</span>
                    </div>
                @endforeach
                <button class="mt-1 h-12 rounded-xl bg-[var(--accent)] text-[15px] font-extrabold text-[var(--site-on-accent)]" type="submit">{{ __('home.combo_add') }}</button>
            </form>
        @endif
    </section>

    @if ($slots->isNotEmpty())
        <section class="flex flex-col gap-4 rounded-2xl border border-[var(--site-line)] bg-[var(--site-panel)] p-4 md:p-5">
            <div class="flex items-center justify-between"><h2 class="text-lg font-extrabold text-[var(--site-text)] md:text-xl">{{ __('home.popular_slots') }}</h2><a class="text-[13px] font-bold text-[var(--accent)]" href="{{ route('site.slots') }}">{{ __('home.show_all') }}</a></div>
            <div class="no-scrollbar flex gap-3 overflow-x-auto md:grid md:grid-cols-6 md:overflow-visible">
                @foreach ($slots->take(12) as $game)
                    <div class="w-32 shrink-0 md:w-auto">@include('site._card', ['game' => $game])</div>
                @endforeach
            </div>
        </section>
    @endif

    @if (! empty($winners))
        <section class="flex flex-col gap-3 rounded-2xl border border-[var(--site-line)] bg-[var(--site-panel)] p-4 md:p-5">
            <h2 class="text-lg font-extrabold text-[var(--site-text)] md:text-xl">{{ __('home.winners') }}</h2>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-6">
                @foreach ($winners as $w)
                    <div class="flex flex-col gap-1 rounded-xl bg-[var(--site-panel-2)] p-3">
                        <span class="text-xs text-[var(--site-muted)]">{{ $w['user'] }} · {{ $w['product'] }}</span>
                        <span class="font-numeric text-xl font-bold text-[var(--site-gold)]">{{ $w['amount'] }}</span>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <footer class="flex flex-col gap-4 border-t border-[var(--site-line)] pt-6 text-[13px] text-[var(--site-muted)]">
        <div class="flex items-center justify-between gap-4">
            <span>{{ __('home.footer_note', ['brand' => brand()->name()]) }}</span>
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border-2 border-[var(--site-live)] font-extrabold text-[var(--site-text)]">18+</span>
        </div>
    </footer>
</div>
@endsection
