@extends('layouts.site')

@section('heading', __('site.sport'))

@section('mainClass', 'mx-auto w-full max-w-[90rem] px-4 py-4 md:px-6')

@section('afterHeader')
    @include('site.sport._live')
@endsection

@section('content')
    @php
        $query = fn (array $extra = []) => array_filter([
            'when' => $extra['when'] ?? ($when ?? 'today'),
            'q' => $extra['q'] ?? request('q'),
            'league' => $extra['league'] ?? request('league'),
            'sport' => $extra['sport'] ?? ($sport ?? request('sport', 'football')),
            'market' => $extra['market'] ?? request('market', 'result'),
        ], fn ($value) => $value !== null && $value !== '');
    @endphp
    <div class="lg:grid lg:grid-cols-[14.5rem_minmax(0,1fr)_21.25rem] lg:items-start lg:gap-4">
        <aside class="sticky top-20 hidden self-start lg:flex lg:flex-col lg:gap-4">
            @include('site.sport._sports', ['variant' => 'side'])
        </aside>
        <section class="flex min-w-0 flex-col gap-3">
            @include('site.sport._sports', ['variant' => 'chips'])
            @include('site.sport._pages')
            <form class="flex flex-col gap-3" method="GET">
                <input type="hidden" name="market" value="{{ $market }}">
                <input type="hidden" name="when" value="{{ $when }}">
                <input type="hidden" name="sport" value="{{ $sport }}">
                @if (request()->filled('league'))
                    <input type="hidden" name="league" value="{{ request('league') }}">
                @endif
                <div class="hidden items-center gap-2 md:flex">
                    <label class="flex h-11 min-w-0 flex-1 items-center gap-2.5 rounded-[10px] border border-[var(--site-line)] bg-[var(--site-panel)] px-3.5 text-[var(--site-muted)]">
                        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
                        <span class="sr-only">{{ __('sport.search') }}</span>
                        <input class="min-w-0 flex-1 bg-transparent text-sm text-[var(--site-text)] outline-none" name="q" value="{{ request('q') }}" placeholder="{{ __('sport.search') }}">
                    </label>
                    @foreach (['all' => __('sport.all'), 'today' => __('sport.today'), 'tomorrow' => __('sport.tomorrow'), '3h' => __('sport.hours')] as $key => $label)
                        <a class="inline-flex h-11 items-center rounded-[10px] px-4 text-[13px] {{ $when === $key ? 'bg-[var(--site-text)] font-bold text-[var(--site-on-accent)]' : 'border border-[var(--site-line)] bg-[var(--site-panel)] font-semibold text-[var(--site-text-2)]' }}" href="{{ route('site.sport', $query(['when' => $key])) }}">{{ $label }}</a>
                    @endforeach
                </div>
                <div class="flex gap-5 border-b border-[var(--site-line)] md:hidden">
                    @foreach (['today' => __('sport.today'), 'tomorrow' => __('sport.tomorrow'), '3h' => __('sport.hours'), 'all' => __('sport.all')] as $key => $label)
                        <a class="py-2.5 text-[13px] {{ $when === $key ? 'sport-tab-on font-bold text-white' : 'font-semibold text-[var(--site-muted)]' }}" href="{{ route('site.sport', $query(['when' => $key])) }}">{{ $label }}</a>
                    @endforeach
                </div>
            </form>
            <div class="no-scrollbar flex gap-2 overflow-x-auto">
                @foreach (['result', 'half', 'btts', 'ou'] as $key)
                    <a class="inline-flex h-9 shrink-0 items-center rounded-full px-3.5 text-[13px] {{ $market === $key ? 'bg-[var(--site-text)] font-bold text-[var(--site-on-accent)]' : 'border border-[var(--site-line-strong)] font-semibold text-[var(--site-text-2)]' }}" href="{{ route('site.sport', $query(['market' => $key])) }}">{{ __('sport.filters.'.$key) }}</a>
                @endforeach
            </div>
            @forelse ($fixtures as $group)
                @php $league = $group->first()->league; @endphp
                <p class="text-xs font-bold text-[var(--site-muted)] md:hidden">{{ sport_name($league->country) }} · {{ sport_name($league) }}</p>
                <section class="hidden overflow-x-auto overflow-hidden rounded-xl bg-[var(--site-panel)] md:block">
                    <div class="grid items-center gap-x-1 bg-[var(--site-panel-2)] px-3" style="grid-template-columns: 4.75rem 3.25rem minmax(12rem,1fr) repeat({{ count($columns) }}, 3.625rem) 3.5rem; height: 44px">
                        <div class="col-span-3 flex flex-col">
                            <span class="text-[11px] font-semibold text-[var(--site-muted)]">{{ sport_name($league->country) }}</span>
                            <span class="text-sm font-bold text-white">{{ sport_name($league) }}</span>
                        </div>
                        @foreach ($columns as $column)
                            <span class="text-center text-[11px] font-bold text-[var(--site-muted)]">{{ $column['head'] }}</span>
                        @endforeach
                        <span></span>
                    </div>
                    @foreach ($group as $fixture)
                        @include('site.sport._row', ['fixture' => $fixture])
                    @endforeach
                </section>
                <div class="grid gap-2.5 md:hidden">
                    @foreach ($group as $fixture)
                        @include('site.sport._card', ['fixture' => $fixture])
                    @endforeach
                </div>
            @empty
                <p class="text-[var(--site-muted)]">{{ __('sport.empty') }}</p>
            @endforelse
            @if ($pages->hasPages())
                <div class="flex items-center justify-between gap-3">
                    @if ($pages->previousPageUrl())
                        <a class="inline-flex h-10 items-center rounded-lg border border-[var(--site-line)] px-4 text-sm font-semibold" href="{{ $pages->previousPageUrl() }}">{{ __('sport.page_prev') }}</a>
                    @else
                        <span></span>
                    @endif
                    <span class="font-numeric text-sm text-[var(--site-muted)]">{{ $pages->currentPage() }} / {{ $pages->lastPage() }}</span>
                    @if ($pages->nextPageUrl())
                        <a class="inline-flex h-10 items-center rounded-lg border border-[var(--site-line)] px-4 text-sm font-semibold" href="{{ $pages->nextPageUrl() }}">{{ __('sport.page_next') }}</a>
                    @endif
                </div>
            @endif
        </section>
        <aside class="sticky top-20 hidden self-start lg:flex lg:flex-col lg:gap-3">
            @include('site.sport._coupon')
            @include('site.sport._lookup')
        </aside>
    </div>
    @include('site.sport._sheet')
@endsection
