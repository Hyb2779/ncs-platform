@php
    $vendorName = \App\Support\Vendors::name($game->vendor) ?? $game->provider?->name;
    $href = auth()->check() ? route('site.launch', $game) : route('login');
@endphp
<a class="group flex flex-col gap-2 rounded-2xl" href="{{ $href }}" @guest onclick="const d = document.getElementById('login-dialog'); if (d) { event.preventDefault(); d.showModal(); }" @endguest>
    <span class="relative block aspect-square overflow-hidden rounded-2xl bg-[var(--site-panel-2)]">
        @if ($game->image_url)
            <img class="h-full w-full object-cover transition duration-200 group-hover:scale-105" src="{{ $game->image_url }}" alt="" loading="lazy" decoding="async">
        @endif
        @if ($game->is_live)
            <span class="absolute start-2 top-2 rounded-md bg-[var(--site-live)] px-2 py-0.5 text-[11px] font-extrabold text-white">{{ __('site.live_badge') }}</span>
        @endif
        <span class="absolute inset-0 hidden items-center justify-center bg-black/55 group-hover:flex group-focus-visible:flex">
            <span class="inline-flex h-11 items-center rounded-xl bg-[var(--accent)] px-5 text-sm font-extrabold text-[var(--site-on-accent)]">{{ __('site.play') }}</span>
        </span>
    </span>
    <span class="flex min-w-0 flex-col px-0.5">
        <span class="truncate text-sm font-bold text-[var(--site-text)]">{{ $game->name }}</span>
        <span class="truncate text-xs text-[var(--site-muted)]">{{ $vendorName }}</span>
    </span>
</a>
