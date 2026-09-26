@php
    $state = sport_live_state($selection->fixture, $selection->status);
@endphp
<p class="text-sm opacity-80">{{ sport_placed_line($coupon, $selection) }}</p>
<p class="flex items-center gap-2 text-sm" data-live-selection="{{ $selection->id }}">
    <span data-live-pulse @class(['inline-block h-2 w-2 shrink-0 rounded-full bg-red-500 animate-pulse', 'hidden' => ! $state['live']])></span>
    <span data-live-text>{{ $state['text'] }}</span>
</p>
