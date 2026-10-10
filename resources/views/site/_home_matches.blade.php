@php
    $liveRows = $matches['live'] ?? [];
    $upcomingRows = $matches['upcoming'] ?? [];
@endphp
<section
    class="home-matches"
    data-home-matches
    data-sport-url="{{ $matches['sport_url'] }}"
    data-live-url="{{ $matches['live_url'] }}"
    data-refresh-url="{{ $matches['refresh_url'] }}"
    data-refresh-ms="{{ (int) ($matches['refresh_ms'] ?? 30000) }}"
    aria-label="{{ __('home.matches_label') }}"
>
    <div class="home-matches-grid">
        <article class="home-match-card" data-match-card="live"@if ($liveRows === []){{ ' hidden' }}@endif>
            <header class="home-match-card-head">
                <a class="home-match-card-title" href="{{ $matches['sport_url'] }}">
                    <span class="home-match-live-dot" aria-hidden="true"></span>
                    <span class="truncate">{{ __('home.matches_live') }}</span>
                </a>
                <a class="home-matches-all" href="{{ $matches['sport_url'] }}">{{ __('home.matches_all') }} <span class="inline-block rtl:rotate-180" aria-hidden="true">&rarr;</span></a>
            </header>
            <div data-match-list="live">
                @foreach ($liveRows as $row)
                    @include('site._home_match_row', ['row' => $row, 'href' => $row['url'] ?? $matches['live_url']])
                @endforeach
            </div>
        </article>
        <article class="home-match-card" data-match-card="upcoming"@if ($upcomingRows === []){{ ' hidden' }}@endif>
            <header class="home-match-card-head">
                <a class="home-match-card-title" href="{{ $matches['sport_url'] }}">
                    <span class="truncate">{{ __('home.matches_upcoming') }}</span>
                </a>
                <a class="home-matches-all" href="{{ $matches['sport_url'] }}">{{ __('home.matches_all') }} <span class="inline-block rtl:rotate-180" aria-hidden="true">&rarr;</span></a>
            </header>
            <div data-match-list="upcoming">
                @foreach ($upcomingRows as $row)
                    @include('site._home_match_row', ['row' => $row, 'href' => $matches['sport_url']])
                @endforeach
            </div>
        </article>
    </div>

    <template data-match-template>
        <a class="home-match-row" href="">
            <span class="home-match-meta">
                <span class="home-match-badge" data-side="badge" hidden></span>
                <span class="home-match-day" data-side="day" hidden></span>
                <span class="home-match-clock" data-side="clock"></span>
            </span>
            <span class="home-match-main">
                <span class="home-match-team" data-side="home"></span>
                <span class="home-match-team" data-side="away"></span>
                <span class="home-match-league" data-side="league"></span>
            </span>
            <span class="home-match-scores" data-side="scores" hidden>
                <span class="home-match-score font-numeric" data-side="score-home"></span>
                <span class="home-match-score font-numeric" data-side="score-away"></span>
            </span>
        </a>
    </template>
</section>
