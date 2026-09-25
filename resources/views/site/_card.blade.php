<article class="w-40 shrink-0 rounded-lg bg-[#151A23] p-3">
    @if ($game->image_url)
        <img class="mb-2 h-24 w-full rounded-md object-cover" src="{{ $game->image_url }}" alt="">
    @else
        <div class="mb-2 h-24 rounded-md bg-[#1B2230]"></div>
    @endif
    <p class="text-sm">{{ $game->name }}</p>
    <p class="text-xs text-[#9AA4B5]">{{ $game->provider->name }}</p>
    @if ($game->is_live)
        <p class="text-xs text-[var(--accent)]">{{ __('site.live_badge') }}</p>
    @endif
    @auth
        <a class="mt-2 inline-flex h-11 items-center text-sm" href="{{ route('site.launch', $game) }}">{{ __('site.play') }}</a>
    @endauth
</article>
