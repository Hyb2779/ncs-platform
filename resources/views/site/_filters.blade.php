<p class="px-2 pb-2 pt-1 text-xs font-extrabold tracking-wider text-[var(--site-muted)]">{{ mb_strtoupper(__('site.providers')) }}</p>
<nav class="flex flex-col gap-0.5">
    <a class="flex h-10 items-center justify-between rounded-xl px-3 text-sm font-bold {{ ($vendor ?? '') === '' ? 'bg-[var(--site-panel-2)] text-[var(--accent)]' : 'text-[var(--site-text)]' }}" href="{{ request()->fullUrlWithQuery(['vendor' => null]) }}">{{ __('site.all_providers') }}</a>
    @foreach ($vendors ?? [] as $v)
        <a class="flex h-10 items-center justify-between gap-2 rounded-xl px-3 text-sm font-semibold {{ ($vendor ?? '') === $v['slug'] ? 'bg-[var(--site-panel-2)] text-[var(--accent)]' : 'text-[var(--site-text)]' }}" href="{{ request()->fullUrlWithQuery(['vendor' => $v['slug']]) }}">
            <span class="truncate">{{ $v['name'] }}</span>
            <span class="font-numeric text-xs text-[var(--site-muted)]">{{ $v['count'] }}</span>
        </a>
    @endforeach
</nav>
