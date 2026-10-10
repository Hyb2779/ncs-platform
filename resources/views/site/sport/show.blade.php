@extends('layouts.site')

@section('heading', sport_name($fixture->home).' - '.sport_name($fixture->away))

@section('mainClass', 'mx-auto w-full max-w-[90rem] px-4 py-4 md:px-6')

@section('afterHeader')
    @include('site.sport._live')
@endsection

@section('content')
    @php
        $kickoff = display_instant($fixture->starts_at);
        $meta = is_array($fixture->live_meta) ? $fixture->live_meta : [];
        $live = $fixture->isInPlay() && ! in_array((string) $fixture->status, config('football.open_statuses'), true);
    @endphp
    <div class="lg:grid lg:grid-cols-[14.5rem_minmax(0,1fr)_21.25rem] lg:items-start lg:gap-4" x-data="{ q: '' }">
        <aside class="sticky top-20 hidden self-start lg:flex lg:flex-col lg:gap-4">
            @include('site.sport._sports', ['variant' => 'side'])
        </aside>
        <section class="flex min-w-0 flex-col gap-3">
            @include('site.sport._sports', ['variant' => 'chips'])
            @include('site.sport._pages', [
                'pageTab' => $live ? 'live' : 'upcoming',
                'backUrl' => route($live ? 'site.sport.live' : 'site.sport', ['sport' => $fixture->sport ?: 'football']),
            ])
            <div class="rounded-xl bg-[var(--site-panel)] p-4">
                <p class="text-[11px] font-semibold text-[var(--site-muted)]">{{ __('sport.sports.'.($fixture->sport ?: 'football')) }} · {{ sport_name($fixture->league->country) }} · {{ sport_name($fixture->league) }}</p>
                <h1 class="mt-1 flex flex-col gap-0.5 text-xl font-bold text-white">
                    <span class="break-words">{{ sport_name($fixture->home) }}</span>
                    <span class="break-words">{{ sport_name($fixture->away) }}</span>
                </h1>
                @if ($live)
                    <p class="mt-2 font-numeric text-2xl font-bold text-white">{{ $fixture->score_home ?? '0' }} : {{ $fixture->score_away ?? '0' }}</p>
                    <p class="font-numeric text-sm font-bold text-[var(--accent)]">{{ sport_clock($fixture) }}@if (! empty($meta['stoppage'])) +{{ $meta['stoppage'] }}@endif</p>
                @endif
                <p class="mt-1 font-numeric text-sm text-[var(--site-muted)]">{{ sport_date($kickoff, 'j F Y H:i') }} · {{ __('sport.code_prefix', ['code' => $fixture->bulletin_code]) }}</p>
                @if ((int) $fixture->mbs > 1)
                    <p class="mt-1 text-xs font-semibold text-[var(--site-text-2)]">{{ __('sport.mbs', ['count' => $fixture->mbs]) }}</p>
                @endif
                @if ($fixture->sport === 'football' && $meta !== [])
                    <div class="mt-3 grid grid-cols-2 gap-2 text-xs sm:grid-cols-3">
                        @if ($fixture->ht_home !== null)
                            <p class="rounded-lg bg-[var(--site-panel-2)] px-2.5 py-2"><span class="text-[var(--site-muted)]">{{ __('sport.half_time') }}</span> <span class="font-numeric font-bold">{{ $fixture->ht_home }}:{{ $fixture->ht_away }}</span></p>
                        @endif
                        @foreach (['corners' => ['home_corners', 'away_corners'], 'yellow' => ['home_yellow', 'away_yellow'], 'red' => ['home_red', 'away_red']] as $label => $keys)
                            @if (isset($meta[$keys[0]], $meta[$keys[1]]))
                                <p class="rounded-lg bg-[var(--site-panel-2)] px-2.5 py-2"><span class="text-[var(--site-muted)]">{{ __('sport.stats.'.$label) }}</span> <span class="font-numeric font-bold">{{ $meta[$keys[0]] }}:{{ $meta[$keys[1]] }}</span></p>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>
            <label class="flex h-11 items-center gap-2.5 rounded-[10px] border border-[var(--site-line)] bg-[var(--site-panel)] px-3.5 text-[var(--site-muted)]">
                <span class="sr-only">{{ __('sport.market_search') }}</span>
                <input class="min-w-0 flex-1 bg-transparent text-sm text-[var(--site-text)] outline-none" x-model="q" placeholder="{{ __('sport.market_search') }}">
            </label>
            @forelse ($board as $name => $rows)
                @php
                    $title = str_starts_with((string) $name, 'code:')
                        ? __($rows->first()->market->name_key)
                        : sport_group_label((string) $name);
                    $lines = $rows->groupBy(fn ($odd) => (string) $odd->handicap);
                    $wide = $lines->count() === 1 && $rows->count() > 3;
                @endphp
                <section class="rounded-xl bg-[var(--site-panel)] p-3" x-show="q === '' || {{ \Illuminate\Support\Js::from(mb_strtolower($title.' '.$name)) }}.includes(q.toLowerCase())">
                    <h2 class="text-sm font-bold text-white">{{ $title }} <span class="font-numeric text-xs font-semibold text-[var(--site-muted)]">{{ $rows->count() }}</span></h2>
                    <div class="mt-2 flex flex-col gap-2">
                        @foreach ($lines as $handicap => $picks)
                            @php
                                $grid = $wide
                                    ? 'grid-cols-2 sm:grid-cols-3'
                                    : match ($picks->count()) {
                                        1 => 'grid-cols-1',
                                        2 => 'grid-cols-2',
                                        default => 'grid-cols-3',
                                    };
                            @endphp
                            <div class="grid gap-2 {{ $grid }}">
                                @foreach ($picks as $odd)
                                    @include('site.sport._price', ['odd' => $odd, 'fixture' => $fixture])
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </section>
            @empty
                <p class="text-[var(--site-muted)]">{{ __('sport.empty_markets') }}</p>
            @endforelse
        </section>
        <aside class="sticky top-20 hidden self-start lg:flex lg:flex-col lg:gap-3">
            @include('site.sport._coupon')
            @include('site.sport._lookup')
        </aside>
    </div>
    @include('site.sport._sheet')
@endsection
