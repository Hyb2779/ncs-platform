@php
    $kickoff = display_instant($fixture->starts_at);
    $day = $kickoff->isToday() ? __('sport.today') : ($kickoff->isTomorrow() ? __('sport.tomorrow') : sport_date($kickoff, 'j F'));
    $count = count($columns);
@endphp
<div class="grid items-center gap-x-1 border-b border-[var(--site-line)] px-3 py-1.5" style="grid-template-columns: 4.75rem 3.25rem minmax(12rem,1fr) repeat({{ $count }}, 3.625rem) 3.5rem; min-height: 52px" @if ($liveBoard ?? false) data-live-fixture="{{ $fixture->id }}" @endif>
    <div class="flex flex-col">
        @if ($liveBoard ?? false)
            <span data-live-clock class="text-[11px] font-extrabold text-[var(--accent)]">{{ sport_clock($fixture) }}</span>
            <span data-live-score class="font-numeric text-sm font-bold">{{ $fixture->score_home ?? 0 }}:{{ $fixture->score_away ?? 0 }}</span>
        @else
            @if (in_array($when ?? request('when', 'today'), ['all', 'tomorrow'], true))
                <span class="text-[11px] font-semibold text-[var(--site-muted)]">{{ $day }}</span>
            @endif
            <span class="text-sm font-bold">{{ sport_digits($kickoff->format('H:i')) }}</span>
        @endif
    </div>
    <span class="font-numeric text-xs font-bold text-[var(--accent)]">{{ $fixture->bulletin_code }}</span>
    <a class="flex flex-col gap-0.5 text-sm font-semibold" href="{{ route('site.sport.show', $fixture) }}">
        <span class="break-words">{{ sport_name($fixture->home) }}</span>
        <span class="break-words">{{ sport_name($fixture->away) }}</span>
    </a>
    @foreach ($columns as $column)
        @include('site.sport._odd', ['market' => $column['market'], 'outcome' => $column['outcome'], 'compact' => true])
    @endforeach
    <a class="text-center text-[13px] font-bold text-[var(--site-muted)]" href="{{ route('site.sport.show', $fixture) }}">{{ __('sport.other', ['count' => (int) $fixture->offer_count]) }}</a>
</div>
