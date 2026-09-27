@extends('layouts.site')

@section('heading', $live ? __('site.live_casino') : __('site.slots'))

@section('mainClass', 'mx-auto w-full max-w-[90rem] px-4 py-4 md:px-6 md:py-6')

@section('content')
@php
    $tabs = ['all' => __('site.all'), 'popular' => __('site.popular_games'), 'favorites' => __('site.favorites'), 'recent' => __('site.recent')];
@endphp
<div class="grid gap-4 lg:grid-cols-[15rem_minmax(0,1fr)] lg:items-start lg:gap-5">
    <aside class="hidden rounded-2xl border border-[var(--site-line)] bg-[var(--site-panel)] p-3 lg:sticky lg:top-24 lg:block">
        @include('site._filters')
    </aside>

    <div class="flex min-w-0 flex-col gap-4">
        <div class="flex flex-col gap-3 rounded-2xl border border-[var(--site-line)] bg-[var(--site-panel)] p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="flex items-center gap-2 text-xl font-extrabold text-[var(--site-text)] md:text-2xl">
                    {{ $live ? __('site.live_casino') : __('site.slots') }}
                    <span class="rounded-full border border-[var(--site-line)] px-2.5 py-0.5 font-numeric text-sm font-bold text-[var(--accent)]">{{ number_format($total, 0, ',', '.') }}</span>
                </h1>
                <nav class="no-scrollbar flex gap-2 overflow-x-auto">
                    @foreach ($tabs as $key => $label)
                        <a class="inline-flex h-9 shrink-0 items-center rounded-full px-3.5 text-sm font-bold {{ $list === $key ? 'bg-[var(--accent)] text-[var(--site-on-accent)]' : 'border border-[var(--site-line)] text-[var(--site-muted)]' }}" href="{{ request()->fullUrlWithQuery(['list' => $key === 'all' ? null : $key]) }}">{{ $label }}</a>
                    @endforeach
                </nav>
            </div>
            <form method="GET">
                @if ($vendor !== '')<input type="hidden" name="vendor" value="{{ $vendor }}">@endif
                @if ($list !== 'all')<input type="hidden" name="list" value="{{ $list }}">@endif
                <input class="h-11 w-full rounded-xl border border-[var(--site-line)] bg-[var(--site-bg)] px-4 text-[15px] text-[var(--site-text)] outline-none focus:border-[var(--accent)]" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('site.search') }}" enterkeyhint="search">
            </form>
            <div class="no-scrollbar flex gap-2 overflow-x-auto lg:hidden">
                <a class="inline-flex h-9 shrink-0 items-center rounded-full px-3.5 text-sm font-bold {{ $vendor === '' ? 'bg-[var(--accent)] text-[var(--site-on-accent)]' : 'border border-[var(--site-line)] text-[var(--site-muted)]' }}" href="{{ request()->fullUrlWithQuery(['vendor' => null]) }}">{{ __('site.all_providers') }}</a>
                @foreach ($vendors as $v)
                    <a class="inline-flex h-9 shrink-0 items-center rounded-full px-3.5 text-sm font-bold {{ $vendor === $v['slug'] ? 'bg-[var(--accent)] text-[var(--site-on-accent)]' : 'border border-[var(--site-line)] text-[var(--site-muted)]' }}" href="{{ request()->fullUrlWithQuery(['vendor' => $v['slug']]) }}">{{ $v['name'] }}</a>
                @endforeach
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:gap-4 lg:grid-cols-4 xl:grid-cols-5">
            @forelse ($games as $game)
                @include('site._card', ['game' => $game])
            @empty
                <p class="col-span-full text-[var(--site-muted)]">{{ __('site.empty_games') }}</p>
            @endforelse
        </div>
    </div>
</div>
@endsection
