@php
    $shared = array_filter([
        'sport' => $sport ?? request('sport', 'football'),
        'market' => $market ?? request('market', 'result'),
        'q' => request('q'),
        'league' => request('league'),
    ], fn ($value) => $value !== null && $value !== '');
    $tab = $pageTab ?? (request()->routeIs('site.sport.live') ? 'live' : 'upcoming');
@endphp
<div class="sticky top-14 z-10 flex flex-wrap items-center gap-2 bg-[var(--site-bg)] py-2 md:top-[109px]">
    @if (! empty($backUrl))
        <a class="inline-flex h-11 shrink-0 items-center gap-1.5 rounded-[10px] border border-[var(--site-line)] bg-[var(--site-panel)] px-3 text-[13px] font-bold text-white" href="{{ $backUrl }}">
            <svg class="h-4 w-4 rtl:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 6 9 12l6 6"></path></svg>
            {{ __('sport.back') }}
        </a>
    @endif
    <a class="inline-flex h-11 items-center rounded-[10px] px-4 text-[13px] {{ $tab === 'live' ? 'bg-[var(--site-live)] font-bold text-white' : 'border border-[var(--site-line)] bg-[var(--site-panel)] font-semibold text-[var(--site-text-2)]' }}" href="{{ route('site.sport.live', $shared) }}">{{ __('sport.page_live') }}</a>
    <a class="inline-flex h-11 items-center rounded-[10px] px-4 text-[13px] {{ $tab === 'upcoming' ? 'bg-[var(--site-text)] font-bold text-[var(--site-on-accent)]' : 'border border-[var(--site-line)] bg-[var(--site-panel)] font-semibold text-[var(--site-text-2)]' }}" href="{{ route('site.sport', $shared) }}">{{ __('sport.page_upcoming') }}</a>
</div>
