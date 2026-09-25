@php
    $odd = $fixture->odds->first(fn ($row) => $row->market->code === $market && $row->outcome === $outcome);
@endphp
@if ($odd && ! $odd->suspended)
    <form method="POST" action="{{ route('site.sport.add', $odd) }}">
        @csrf
        <button class="inline-flex h-11 min-w-14 items-center justify-center gap-1 rounded-md bg-[#1B2230] px-2 font-numeric text-sm" type="submit">
            @if ($odd->direction === 'up')
                <span class="text-emerald-400">↑</span>
            @elseif ($odd->direction === 'down')
                <span class="text-red-400">↓</span>
            @endif
            {{ $odd->shown_odd }}
        </button>
    </form>
@else
    <span class="inline-flex h-11 min-w-14 items-center justify-center text-[#9AA4B5]">—</span>
@endif
