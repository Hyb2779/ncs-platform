@if (($liveFixtures ?? collect())->isNotEmpty())
    <div class="no-scrollbar flex h-11 items-center gap-3 overflow-x-auto border-b border-[#1D2430] bg-[#0B0E13] px-4 md:px-6">
        <span class="inline-flex shrink-0 items-center rounded bg-[#C9303A] px-2 py-1 text-[11px] font-extrabold tracking-wider text-white">{{ __('site.live_badge') }}</span>
        @foreach ($liveFixtures as $live)
            <a class="flex shrink-0 items-center gap-2 whitespace-nowrap rounded-md bg-[#151A23] px-3 py-1.5 text-[13px]" href="{{ route('site.sport.show', $live) }}">
                <span class="text-xs font-extrabold text-[var(--accent)]">{{ $live->status }}</span>
                <span class="max-w-40 truncate text-[#C9D1DD]" title="{{ sport_name($live->home) }}">{{ sport_name($live->home) }}</span>
                <span class="font-numeric text-base font-bold text-white">{{ $live->score_home ?? '0' }} : {{ $live->score_away ?? '0' }}</span>
                <span class="max-w-40 truncate text-[#C9D1DD]" title="{{ sport_name($live->away) }}">{{ sport_name($live->away) }}</span>
            </a>
        @endforeach
    </div>
@endif
