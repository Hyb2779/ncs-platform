@php
    $state = sport_live_state($selection->fixture, $selection->status);
@endphp
<div class="flex items-center gap-3 py-2.5">
    <div class="min-w-0 flex-1">
        <p class="truncate text-sm font-bold text-[var(--site-text)]">{{ sport_name($selection->fixture->home) }} - {{ sport_name($selection->fixture->away) }}</p>
        <p class="flex flex-wrap items-center gap-x-1.5 text-xs text-[var(--site-muted)]" data-live-selection="{{ $selection->id }}">
            <span>{{ __('sport.markets.'.$selection->market_code) }} · {{ __('sport.outcomes.'.$selection->outcome) }}</span>
            <span aria-hidden="true">·</span>
            <span data-live-pulse @class(['inline-block h-2 w-2 shrink-0 rounded-full bg-[var(--site-live)] animate-pulse', 'hidden' => ! $state['live']])></span>
            <span data-live-text>{{ $state['text'] }}</span>
        </p>
        @if ($selection->placed_minute !== null)
            <p class="text-xs text-[var(--site-muted)]">{{ sport_placed_line($coupon, $selection) }}</p>
        @endif
    </div>
    <div class="flex shrink-0 flex-col items-end gap-1">
        <span class="font-numeric text-base font-bold text-[var(--site-text)]">{{ $selection->odds }}</span>
        @include('sport._badge', ['status' => $selection->status, 'coupon' => $coupon, 'dark' => true])
    </div>
</div>
