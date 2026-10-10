@php
    $sports = array_values(array_diff(['football', 'basketball', 'tennis', 'volleyball'], sport_closed(auth()->user())));
    $current = $sport ?? request('sport', 'football');
    $counts = $sportCounts ?? collect();
    $board = request()->routeIs('site.sport.live') ? 'site.sport.live' : 'site.sport';
    $link = fn (string $code) => route($board, array_filter([
        'sport' => $code,
        'when' => $when ?? request('when', 'today'),
        'market' => request('market', 'result'),
        'q' => request('q'),
        'league' => request('league'),
    ], fn ($value) => $value !== null && $value !== ''));
@endphp
@if (($variant ?? 'side') === 'chips')
    <div class="no-scrollbar flex gap-2 overflow-x-auto lg:hidden">
        @foreach ($sports as $code)
            <a class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-full px-3.5 text-[13px] {{ $current === $code ? 'bg-[var(--site-text)] font-bold text-[var(--site-on-accent)]' : 'border border-[var(--site-line-strong)] font-semibold text-[var(--site-text-2)]' }}" href="{{ $link($code) }}">
                <span>{{ __('sport.sports.'.$code) }}</span>
                <span class="font-numeric">{{ (int) ($counts[$code] ?? 0) }}</span>
            </a>
        @endforeach
    </div>
@else
    <div class="rounded-xl bg-[var(--site-panel)] p-2">
        <p class="px-3 pb-2 pt-2.5 text-[11px] font-extrabold tracking-wider text-[var(--site-muted)]">{{ __('sport.sports_heading') }}</p>
        @foreach ($sports as $code)
            <a class="flex items-center justify-between rounded-lg px-3 py-2.5 text-sm font-semibold {{ $current === $code ? 'bg-[var(--site-panel-2)] text-white' : 'text-[var(--site-text-2)]' }}" href="{{ $link($code) }}">
                <span>{{ __('sport.sports.'.$code) }}</span>
                <span class="font-numeric text-xs font-bold text-[var(--site-muted)]">{{ (int) ($counts[$code] ?? 0) }}</span>
            </a>
        @endforeach
    </div>
    <div class="rounded-xl bg-[var(--site-panel)] p-2">
        <p class="px-3 pb-2 pt-2.5 text-[11px] font-extrabold tracking-wider text-[var(--site-muted)]">{{ __('sport.popular_leagues') }}</p>
        @foreach ($leagues as $league)
            <a class="flex items-center justify-between px-3 py-2 text-[13px] {{ (string) request('league') === (string) $league->id ? 'font-bold text-white' : 'text-[var(--site-text-2)]' }}" href="{{ route($board, array_filter(['league' => $league->id, 'sport' => $current, 'when' => $board === 'site.sport' ? ($when ?? request('when', 'today')) : null, 'market' => request('market', 'result'), 'q' => request('q')], fn ($value) => $value !== null && $value !== '')) }}">
                <span class="truncate" title="{{ sport_name($league) }}">{{ sport_name($league) }}</span>
                <span class="ms-2 font-numeric text-xs text-[var(--site-muted)]">{{ $league->bulletin_count }}</span>
            </a>
        @endforeach
    </div>
@endif
