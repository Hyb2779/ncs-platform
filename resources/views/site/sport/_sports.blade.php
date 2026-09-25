@php
    $others = ['basketball', 'tennis', 'volleyball', 'ice_hockey', 'handball'];
@endphp
@if (($variant ?? 'side') === 'chips')
    <div class="no-scrollbar flex gap-2 overflow-x-auto lg:hidden">
        <a class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-full bg-[#E8ECF3] px-3.5 text-[13px] font-bold text-[#0E1117]" href="{{ route('site.sport', ['when' => $when ?? request('when', 'today'), 'market' => request('market', 'result')]) }}">
            <span>{{ __('sport.sports.football') }}</span>
            <span>{{ $footballCount }}</span>
        </a>
        @foreach ($others as $sport)
            <span class="inline-flex h-9 shrink-0 items-center rounded-full border border-[#2A3342] px-3.5 text-[13px] font-semibold text-[#C9D1DD]">{{ __('sport.sports.'.$sport) }}</span>
        @endforeach
    </div>
@else
    <div class="rounded-xl bg-[#151A23] p-2">
        <p class="px-3 pb-2 pt-2.5 text-[11px] font-extrabold tracking-wider text-[#9AA4B5]">{{ __('sport.sports_heading') }}</p>
        <a class="flex items-center justify-between rounded-lg bg-[#1E2533] px-3 py-2.5 text-sm font-semibold text-white" href="{{ route('site.sport', ['when' => $when ?? request('when', 'today'), 'market' => request('market', 'result')]) }}">
            <span>{{ __('sport.sports.football') }}</span>
            <span class="font-numeric text-xs font-bold text-[#9AA4B5]">{{ $footballCount }}</span>
        </a>
        @foreach ($others as $sport)
            <span class="flex items-center justify-between px-3 py-2.5 text-sm font-semibold text-[#C9D1DD]">
                <span>{{ __('sport.sports.'.$sport) }}</span>
                <span class="text-xs font-bold text-[#9AA4B5]">{{ __('site.coming_soon') }}</span>
            </span>
        @endforeach
    </div>
    <div class="rounded-xl bg-[#151A23] p-2">
        <p class="px-3 pb-2 pt-2.5 text-[11px] font-extrabold tracking-wider text-[#9AA4B5]">{{ __('sport.popular_leagues') }}</p>
        @foreach ($leagues as $league)
            <a class="flex items-center justify-between px-3 py-2 text-[13px] {{ (string) request('league') === (string) $league->id ? 'font-bold text-white' : 'text-[#C9D1DD]' }}" href="{{ route('site.sport', ['league' => $league->id, 'when' => $when ?? request('when', 'today'), 'market' => request('market', 'result'), 'q' => request('q')]) }}">
                <span class="truncate" title="{{ $league->name }}">{{ $league->name }}</span>
                <span class="ms-2 font-numeric text-xs text-[#9AA4B5]">{{ $league->bulletin_count }}</span>
            </a>
        @endforeach
    </div>
@endif
