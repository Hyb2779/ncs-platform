@php
    $live = ($row['badge'] ?? '') !== '';
    $clock = (string) ($row['clock'] ?? '');
    $day = '';
    $time = $clock;
    if (! $live) {
        $space = strrpos($clock, ' ');
        if ($space !== false) {
            $day = substr($clock, 0, $space);
            $time = substr($clock, $space + 1);
        }
    }
    $parts = $live ? explode(' - ', (string) ($row['score'] ?? ''), 2) : [];
    $scoreHome = count($parts) === 2 ? $parts[0] : '';
    $scoreAway = count($parts) === 2 ? $parts[1] : '';
    $league = (string) ($row['league'] ?? '');
    $country = (string) ($row['country'] ?? '');
    if ($league !== '' && $country !== '') {
        $league .= ' · '.$country;
    }
@endphp
<a class="home-match-row" href="{{ $href }}">
    <span class="home-match-meta">
        @if ($live)
            <span class="home-match-badge" data-side="badge">{{ $row['badge'] }}</span>
        @else
            <span class="home-match-badge" data-side="badge" hidden></span>
        @endif
        <span class="home-match-day" data-side="day"@if ($day === ''){{ ' hidden' }}@endif>{{ $day }}</span>
        <span class="home-match-clock" data-side="clock">{{ $time }}</span>
    </span>
    <span class="home-match-main">
        <span class="home-match-team" data-side="home">{{ $row['home'] }}</span>
        <span class="home-match-team" data-side="away">{{ $row['away'] }}</span>
        <span class="home-match-league" data-side="league">{{ $league }}</span>
    </span>
    <span class="home-match-scores" data-side="scores" @if ($scoreHome === '') hidden @endif>
        <span class="home-match-score font-numeric" data-side="score-home">{{ $scoreHome }}</span>
        <span class="home-match-score font-numeric" data-side="score-away">{{ $scoreAway }}</span>
    </span>
</a>
