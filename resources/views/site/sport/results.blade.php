@extends('layouts.site')

@section('heading', __('site.results'))

@section('mainClass', 'mx-auto w-full max-w-4xl px-4 py-4 md:px-6')

@section('content')
    @php $zone = auth()->user()->timezone ?? 'UTC'; @endphp
    <section class="flex min-w-0 flex-col gap-3">
        <h1 class="text-xl font-extrabold text-[var(--site-text)] md:text-2xl">{{ __('site.results') }}</h1>
        @forelse ($days as $leagues)
            <h2 class="mt-2 text-sm font-bold text-white">{{ sport_date($leagues->first()->first()->starts_at->timezone($zone), 'j F Y') }}</h2>
            @foreach ($leagues as $group)
                @php $league = $group->first()->league; @endphp
                <section class="overflow-hidden rounded-xl bg-[var(--site-panel)]">
                    <div class="bg-[var(--site-panel-2)] px-3 py-2">
                        <span class="text-[11px] font-semibold text-[var(--site-muted)]">{{ sport_name($league->country) }}</span>
                        <p class="text-sm font-bold text-white">{{ sport_name($league) }}</p>
                    </div>
                    @foreach ($group as $fixture)
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-[var(--site-line)] px-3 py-2.5 text-sm last:border-b-0">
                            <span class="text-xs font-bold text-[var(--site-muted)]">{{ $fixture->starts_at->timezone($zone)->format('H:i') }} · {{ sport_status($fixture->status) }}</span>
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
            <p class="text-[var(--site-muted)]">{{ __('sport.empty_results') }}</p>
        @endforelse
    </section>
@endsection
