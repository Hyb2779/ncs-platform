@php
    $odd = $fixture->odds->first(fn ($row) => $row->market->code === $market && $row->outcome === $outcome);
    $labeled = $labeled ?? false;
    $compact = $compact ?? false;
    $picked = $odd && collect($coupon['rows'] ?? [])->contains(fn ($row) => (int) $row['odd']->id === (int) $odd->id);
    $tone = $picked ? 'bg-[var(--accent)] text-[#1A1305]' : 'bg-[#1E2533] text-[#E8ECF3]';
@endphp
@if ($odd && ! $odd->suspended)
    <form class="min-w-0" method="POST" action="{{ route('site.sport.add', $odd) }}">
        @csrf
        <button
            class="inline-flex w-full items-center rounded-lg {{ $compact ? 'h-[38px] justify-center font-numeric text-[17px] font-bold' : 'h-11 justify-between gap-2 px-2.5' }} {{ $tone }}"
            type="submit"
            data-outcome="{{ $outcome }}"
            data-odd="{{ $odd->shown_odd }}"
            aria-label="{{ sport_name($fixture->home) }} - {{ sport_name($fixture->away) }} {{ __('sport.outcomes.'.$outcome) }} {{ $odd->shown_odd }}"
        >
            @if ($labeled)
                <span class="text-xs font-bold opacity-80">{{ $head ?? __('sport.outcomes.'.$outcome) }}</span>
            @endif
            <span class="{{ $labeled || $compact ? 'font-numeric text-lg font-bold' : 'font-numeric' }}">
                @if ($odd->direction === 'up')
                    <span class="text-emerald-400">↑</span>
                @elseif ($odd->direction === 'down')
                    <span class="text-red-400">↓</span>
                @endif
                {{ $odd->shown_odd }}
            </span>
        </button>
    </form>
@else
    <span class="inline-flex {{ $compact ? 'h-[38px]' : 'h-11' }} w-full items-center justify-center rounded-lg bg-[#1E2533] text-[#9AA4B5]" data-outcome="{{ $outcome }}">—</span>
@endif
