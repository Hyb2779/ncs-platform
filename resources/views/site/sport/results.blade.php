@extends('layouts.site')

@section('heading', __('site.results'))

@section('mainClass', 'mx-auto w-full max-w-4xl px-4 py-4 md:px-6')

@section('content')
    <section class="flex min-w-0 flex-col gap-3">
        <h1 class="text-xl font-extrabold text-[var(--site-text)] md:text-2xl">{{ __('site.results') }}</h1>

        <details class="results-filters rounded-xl border border-[var(--site-line)] bg-[var(--site-panel)] md:border-0 md:bg-transparent" @if ($filtered) open @endif>
            <summary class="cursor-pointer list-none px-3 py-3 text-sm font-bold text-[var(--site-text)] md:hidden">{{ __('sport.results_filter') }}</summary>
            <form class="flex flex-col gap-3 p-3 md:flex-row md:flex-wrap md:items-end md:p-0" method="GET" action="{{ route('site.sport.results') }}">
                <label class="grid min-w-0 gap-1 text-sm md:w-44">
                    <span class="font-semibold text-[var(--site-muted)]">{{ __('sport.results_date') }}</span>
                    <select class="h-11 rounded-lg border border-[var(--site-line)] bg-[var(--site-panel)] px-3 text-[var(--site-text)]" name="tarih" onchange="this.form.requestSubmit()">
                        <option value="">{{ __('sport.results_range') }}</option>
                        @foreach ($dates as $day)
                            <option value="{{ $day['value'] }}" @selected($date === $day['value'])>{{ $day['label'] }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grid min-w-0 flex-1 gap-1 text-sm">
                    <span class="font-semibold text-[var(--site-muted)]">{{ __('sport.results_team') }}</span>
                    <input class="h-11 rounded-lg border border-[var(--site-line)] bg-[var(--site-panel)] px-3 text-[var(--site-text)] outline-none" type="search" name="takim" value="{{ $team }}" placeholder="{{ __('sport.results_team_placeholder') }}" autocomplete="off" enterkeyhint="search" x-data @input.debounce.400ms="const n = $event.target.value.trim().length; if (n !== 1) $event.target.form.requestSubmit()" @keydown.enter.prevent="const n = $event.target.value.trim().length; if (n !== 1) $event.target.form.requestSubmit()">
                </label>
                <div class="relative grid min-w-0 flex-1 gap-1 text-sm" x-data="resultLeague(@js($leagues), @js($leagueId), @js(__('sport.results_all_leagues')))" @keydown.escape="open = false">
                    <span class="font-semibold text-[var(--site-muted)]">{{ __('sport.results_league') }}</span>
                    <input type="hidden" name="lig" value="{{ $leagueId }}" :value="selected">
                    <button class="flex h-11 items-center justify-between gap-2 rounded-lg border border-[var(--site-line)] bg-[var(--site-panel)] px-3 text-start text-[var(--site-text)]" type="button" @click="open = !open">
                        <span class="truncate" x-text="current()"></span>
                        <span aria-hidden="true">▾</span>
                    </button>
                    <div class="absolute start-0 z-20 mt-1 flex max-h-72 w-full flex-col overflow-hidden rounded-lg border border-[var(--site-line)] bg-[var(--site-panel)] shadow-lg" x-show="open" x-cloak @click.outside="open = false" style="top: 100%">
                        <input class="h-11 border-b border-[var(--site-line)] bg-transparent px-3 text-[var(--site-text)] outline-none" type="search" x-model="q" placeholder="{{ __('sport.results_league_search') }}" autocomplete="off">
                        <div class="overflow-y-auto">
                            <button class="block w-full px-3 py-2 text-start text-sm hover:bg-[var(--site-panel-2)]" type="button" @click="choose(null)">{{ __('sport.results_all_leagues') }}</button>
                            <template x-for="item in shown" :key="item.id">
                                <button class="block w-full px-3 py-2 text-start text-sm hover:bg-[var(--site-panel-2)]" type="button" @click="choose(item.id)" x-text="item.label"></button>
                            </template>
                        </div>
                    </div>
                </div>
                <a class="inline-flex h-11 items-center justify-center rounded-lg border border-[var(--site-line)] px-4 text-sm font-bold text-[var(--site-text)]" href="{{ route('site.sport.results') }}">{{ __('sport.results_clear') }}</a>
            </form>
        </details>

        @forelse ($days as $leaguesOnDay)
            <h2 class="mt-2 text-sm font-bold text-white">{{ sport_date(display_instant($leaguesOnDay->first()->first()->starts_at), 'j F Y') }}</h2>
            @foreach ($leaguesOnDay as $group)
                @php $league = $group->first()->league; @endphp
                <section class="overflow-hidden rounded-xl bg-[var(--site-panel)]">
                    <div class="bg-[var(--site-panel-2)] px-3 py-2">
                        <span class="text-[11px] font-semibold text-[var(--site-muted)]">{{ sport_name($league->country) }}</span>
                        <p class="text-sm font-bold text-white">{{ sport_name($league) }}</p>
                    </div>
                    @foreach ($group as $fixture)
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-[var(--site-line)] px-3 py-2.5 text-sm last:border-b-0">
                            <span class="text-xs font-bold text-[var(--site-muted)]">{{ display_clock($fixture->starts_at) }} · {{ sport_status($fixture->status) }}</span>
                            <span class="break-words font-semibold">{{ sport_name($fixture->home) }} – {{ sport_name($fixture->away) }}</span>
                            <span class="font-numeric font-bold">{{ $fixture->score_home ?? '0' }}:{{ $fixture->score_away ?? '0' }}</span>
                            @if ($fixture->ht_home !== null && $fixture->ht_away !== null)
                                <span class="text-xs font-semibold text-[var(--site-muted)]">{{ __('sport.half_time') }} {{ $fixture->ht_home }}:{{ $fixture->ht_away }}</span>
                            @endif
                        </div>
                    @endforeach
                </section>
            @endforeach
        @empty
            <p class="text-[var(--site-muted)]">{{ $filtered ? __('sport.results_none') : __('sport.empty_results') }}</p>
        @endforelse
    </section>
@endsection
