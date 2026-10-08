@extends('layouts.site')

@section('heading', brand()->name())

@section('mainClass', 'mx-auto w-full max-w-[90rem] px-4 py-4 md:px-6 md:py-6')

@section('content')
<div class="flex flex-col gap-5 md:gap-7">

    @if ($slides !== [])
        @include('site._carousel')
    @endif

    <section class="grid grid-cols-2 gap-3 md:gap-4 {{ wegas_sport_available(auth()->user()) ? 'md:grid-cols-4' : 'md:grid-cols-3' }}" data-home-tiles>
        @foreach ([
            ...(wegas_sport_available(auth()->user()) ? [['key' => 'sport', 'route' => 'site.wegas_sport', 'title' => brand()->name().' '.__('site.sport'), 'sub' => __('home.live_matches')]] : []),
            ['key' => 'slot', 'route' => 'site.slots', 'title' => __('home.quick_slot'), 'sub' => __('home.quick_slot_sub', ['count' => \Illuminate\Support\Number::format($quick['slots'], 0, locale: app()->getLocale())])],
            ['key' => 'casino', 'route' => 'site.live_casino', 'title' => __('home.quick_casino'), 'sub' => $quick['casino'] > 0 ? __('home.quick_slot_sub', ['count' => \Illuminate\Support\Number::format($quick['casino'], 0, locale: app()->getLocale())]) : __('home.soon')],
            ['key' => 'mini', 'route' => 'site.mini', 'title' => __('site.mini'), 'sub' => ($quick['mini'] ?? 0) > 0 ? __('home.quick_slot_sub', ['count' => \Illuminate\Support\Number::format($quick['mini'], 0, locale: app()->getLocale())]) : __('home.soon')],
        ] as $tile)
            @php($art = $categoryImages[$tile['key']] ?? ['src' => null, 'srcset' => null])
            <a class="home-cat {{ ($art['src'] ?? null) ? '' : 'is-plain' }} group relative flex h-[110px] items-center overflow-hidden rounded-2xl border border-[var(--site-line)] bg-[var(--site-panel)] last:odd:col-span-2 md:h-[140px] md:last:odd:col-span-1" href="{{ route($tile['route']) }}" data-home-cat="{{ $tile['key'] }}">
                @if ($art['src'] ?? null)
                    <img class="home-cat-blur" src="{{ $art['src'] }}" alt="" aria-hidden="true" loading="lazy" decoding="async">
                    <img class="home-cat-img" src="{{ $art['src'] }}" @if ($art['srcset'] ?? null) srcset="{{ $art['srcset'] }}" sizes="(min-width: 768px) 400px, 50vw" @endif alt="" loading="lazy" decoding="async">
                @endif
                <span class="home-cat-shade pointer-events-none absolute inset-0" aria-hidden="true"></span>
                <span class="relative z-[1] flex min-w-0 flex-1 flex-col gap-1 px-4 md:px-5">
                    <span class="home-cat-title line-clamp-2 text-[15px] font-extrabold leading-tight text-[var(--site-text)] md:text-lg">{{ $tile['title'] }}</span>
                    <span class="home-cat-sub truncate text-xs text-[var(--site-muted)] md:text-[13px]">{{ $tile['sub'] }}</span>
                </span>
                <span class="home-cat-icon relative z-[1] me-4 h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[var(--site-panel-2)] text-xl font-extrabold text-[var(--accent)] md:me-5 rtl:rotate-180" aria-hidden="true">&rarr;</span>
            </a>
        @endforeach
    </section>

    @if ($popularSlots->isNotEmpty())
        <section class="flex flex-col gap-4" data-home-rail="popular">
            <div class="flex items-center justify-between"><h2 class="text-lg font-extrabold text-[var(--site-text)] md:text-xl">{{ __('home.popular_games') }}</h2><a class="text-[13px] font-bold text-[var(--accent)]" href="{{ route('site.slots', ['list' => 'popular']) }}">{{ __('home.show_all') }}</a></div>
            <div class="home-rail no-scrollbar flex gap-3 overflow-x-auto overscroll-x-contain md:grid md:grid-cols-6 md:gap-4 md:overflow-visible">
                @foreach ($popularSlots as $game)
                    <div class="w-36 shrink-0 md:w-auto [&>*]:w-full">@include('site._card', ['game' => $game])</div>
                @endforeach
            </div>
        </section>
    @endif

    @if ($liveTables->isNotEmpty())
        <section class="flex flex-col gap-4" data-home-rail="live">
            <div class="flex items-center justify-between"><h2 class="text-lg font-extrabold text-[var(--site-text)] md:text-xl">{{ __('home.live_casino') }}</h2><a class="text-[13px] font-bold text-[var(--accent)]" href="{{ route('site.live_casino') }}">{{ __('home.show_all') }}</a></div>
            <div class="home-rail no-scrollbar flex gap-3 overflow-x-auto overscroll-x-contain md:grid md:grid-cols-6 md:gap-4 md:overflow-visible">
                @foreach ($liveTables as $game)
                    <div class="w-36 shrink-0 md:w-auto [&>*]:w-full">@include('site._card', ['game' => $game])</div>
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

</div>
@endsection
