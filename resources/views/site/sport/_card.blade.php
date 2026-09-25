@php
    $zone = auth()->user()->timezone ?? 'UTC';
    $kickoff = $fixture->starts_at->timezone($zone);
    $day = $kickoff->isToday() ? __('sport.today') : ($kickoff->isTomorrow() ? __('sport.tomorrow') : sport_date($kickoff, 'j F'));
    $cells = $cardColumns ?? $columns;
@endphp
<article class="flex flex-col gap-2.5 rounded-xl bg-[#151A23] p-3">
    <div class="flex items-center justify-between gap-3">
        <a class="flex min-w-0 flex-col gap-0.5" href="{{ route('site.sport.show', $fixture) }}">
            <span class="truncate text-sm font-bold" title="{{ sport_name($fixture->home) }}">{{ sport_name($fixture->home) }}</span>
            <span class="truncate text-sm font-bold" title="{{ sport_name($fixture->away) }}">{{ sport_name($fixture->away) }}</span>
        </a>
        <div class="flex flex-col items-end gap-0.5">
            <span class="text-[13px] font-bold">
                @if (in_array($when ?? request('when', 'today'), ['all', 'tomorrow'], true))
                    {{ $day }} ·
                @endif
                {{ $kickoff->format('H:i') }}
            </span>
            <span class="text-[11px] font-bold text-[var(--accent)]">{{ __('sport.code_prefix', ['code' => $fixture->bulletin_code]) }}</span>
        </div>
    </div>
    <div class="grid items-center gap-1.5" style="grid-template-columns: repeat({{ count($cells) }}, minmax(0,1fr)) 3.25rem">
        @foreach ($cells as $column)
            @include('site.sport._odd', ['market' => $column['market'], 'outcome' => $column['outcome'], 'labeled' => true, 'head' => $column['head']])
        @endforeach
        <a class="inline-flex h-11 items-center justify-center rounded-lg bg-[#1E2533] text-xs font-bold text-[#9AA4B5]" href="{{ route('site.sport.show', $fixture) }}">{{ __('sport.other', ['count' => $fixture->odds->pluck('market_id')->unique()->count()]) }}</a>
    </div>
</article>
