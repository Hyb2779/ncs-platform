@php
    $kickoff = display_instant($fixture->starts_at);
    $day = $kickoff->isToday() ? __('sport.today') : ($kickoff->isTomorrow() ? __('sport.tomorrow') : sport_date($kickoff, 'j F'));
    $cells = $cardColumns ?? $columns;
@endphp
<article class="flex flex-col gap-2.5 rounded-xl bg-[var(--site-panel)] p-3" @if ($liveBoard ?? false) data-live-fixture="{{ $fixture->id }}" @endif>
    <div class="flex items-center justify-between gap-3">
        <a class="flex min-w-0 flex-col gap-0.5" href="{{ route('site.sport.show', $fixture) }}">
            <span class="break-words text-sm font-bold">{{ sport_name($fixture->home) }}</span>
            <span class="break-words text-sm font-bold">{{ sport_name($fixture->away) }}</span>
        </a>
        <div class="flex flex-col items-end gap-0.5">
            <span class="text-[13px] font-bold">
                @if ($liveBoard ?? false)
                    <span data-live-clock class="text-[var(--accent)]">{{ sport_clock($fixture) }}</span>
                    <span data-live-score class="font-numeric"> {{ $fixture->score_home ?? 0 }}:{{ $fixture->score_away ?? 0 }}</span>
                @else
                    @if (in_array($when ?? request('when', 'today'), ['all', 'tomorrow'], true))
                        {{ $day }} ·
                    @endif
                    {{ sport_digits($kickoff->format('H:i')) }}
                @endif
            </span>
            <span class="text-[11px] font-bold text-[var(--accent)]">{{ __('sport.code_prefix', ['code' => $fixture->bulletin_code]) }}</span>
        </div>
    </div>
    <div class="grid items-center gap-1.5" style="grid-template-columns: repeat({{ count($cells) }}, minmax(0,1fr)) 3.25rem">
        @foreach ($cells as $column)
            @include('site.sport._odd', ['market' => $column['market'], 'outcome' => $column['outcome'], 'labeled' => true, 'head' => $column['head']])
        @endforeach
        <a class="inline-flex h-11 items-center justify-center rounded-lg bg-[var(--site-panel-2)] text-xs font-bold text-[var(--site-muted)]" href="{{ route('site.sport.show', $fixture) }}">{{ __('sport.other', ['count' => (int) $fixture->offer_count]) }}</a>
    </div>
</article>
