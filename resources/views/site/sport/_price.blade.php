@php
    $picked = collect($coupon['rows'] ?? [])->contains(fn ($row) => (int) $row['odd']->id === (int) $odd->id);
    $blocked = sport_offer_closed($fixture, $odd) || ! sport_price_open($fixture, (string) $odd->shown_odd);
    $tone = $picked ? 'bg-[var(--accent)] text-[var(--site-on-accent)]' : 'bg-[var(--site-panel-2)] text-[var(--site-text)]';
    $label = sport_pick_label($odd);
@endphp
@if (! $blocked)
    <form class="min-w-0" method="POST" action="{{ route('site.sport.add', $odd) }}">
        @csrf
        <button class="inline-flex h-11 w-full items-center justify-between gap-2 rounded-lg px-2.5 {{ $tone }}" type="submit" data-outcome="{{ $odd->outcome }}" data-odd="{{ $odd->shown_odd }}" aria-label="{{ sport_name($fixture->home) }} - {{ sport_name($fixture->away) }} {{ $label }} {{ $odd->shown_odd }}">
            <span class="min-w-0 break-words text-start text-xs font-bold opacity-80">{{ $label }}</span>
            <span class="font-numeric text-lg font-bold">
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
    <span class="inline-flex h-11 w-full items-center justify-center rounded-lg bg-[var(--site-panel-2)] text-[var(--site-muted)]">—</span>
@endif
