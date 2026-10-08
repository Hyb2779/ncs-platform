@php
    $href = auth()->check() ? route('site.launch', $w['game_id']) : route('login');
@endphp
<a class="win-chip" href="{{ $href }}" @if (! empty($clone)) aria-hidden="true" tabindex="-1" @endif @guest @if (empty($clone)) onclick="const d = document.getElementById('login-dialog'); if (d) { event.preventDefault(); d.showModal(); }" @endif @endguest>
    @if ($w['image'] !== '')
        <img class="win-chip-art" src="{{ $w['image'] }}" alt="" width="44" height="44" loading="lazy" decoding="async">
    @else
        <span class="win-chip-art win-chip-letter" aria-hidden="true">{{ mb_strtoupper(mb_substr($w['game'], 0, 1)) }}</span>
    @endif
    <span class="win-chip-copy">
        <span class="win-chip-game">{{ $w['game'] }}</span>
        <span class="win-chip-user">{{ $w['user'] }}</span>
        <span class="win-chip-amount">{{ $w['amount'] }}</span>
    </span>
</a>
