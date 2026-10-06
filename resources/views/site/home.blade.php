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
            ...(wegas_sport_available(auth()->user()) ? [['route' => 'site.wegas_sport', 'title' => brand()->name().' '.__('site.sport'), 'sub' => __('site.wegas_sport_sub')]] : []),
            ['route' => 'site.slots', 'title' => __('home.quick_slot'), 'sub' => __('home.quick_slot_sub', ['count' => \Illuminate\Support\Number::format($quick['slots'], 0, locale: app()->getLocale())])],
            ['route' => 'site.live_casino', 'title' => __('home.quick_casino'), 'sub' => $quick['casino'] > 0 ? __('home.quick_slot_sub', ['count' => \Illuminate\Support\Number::format($quick['casino'], 0, locale: app()->getLocale())]) : __('home.soon')],
            ['route' => 'site.mini', 'title' => __('site.mini'), 'sub' => ($quick['mini'] ?? 0) > 0 ? __('home.quick_slot_sub', ['count' => \Illuminate\Support\Number::format($quick['mini'], 0, locale: app()->getLocale())]) : __('home.soon')],
        ] as $tile)
            <a class="flex h-20 items-center justify-between rounded-2xl border border-[var(--site-line)] bg-[var(--site-panel)] px-4 last:odd:col-span-2 md:h-24 md:px-5 md:last:odd:col-span-1" href="{{ route($tile['route']) }}">
                <span class="flex flex-col gap-1">
                    <span class="text-[15px] font-extrabold text-[var(--site-text)] md:text-lg">{{ $tile['title'] }}</span>
                    <span class="text-xs text-[var(--site-muted)] md:text-[13px]">{{ $tile['sub'] }}</span>
                </span>
                <span class="hidden h-11 w-11 items-center justify-center rounded-xl bg-[var(--site-panel-2)] text-xl font-extrabold text-[var(--accent)] md:flex rtl:rotate-180" aria-hidden="true">&rarr;</span>
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
