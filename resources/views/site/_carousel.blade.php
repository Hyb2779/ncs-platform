<div class="lobby-hero" id="lobbyHero">
        @foreach ($slides as $index => $slide)
            @php
                $heroUrl = str_replace(['\\', "'", '(', ')'], ['%5C', '%27', '%28', '%29'], (string) ($slide['image'] ?? ''));
            @endphp
            <a class="lobby-hero-slide{{ $loop->first ? ' is-on' : '' }}" href="{{ $slide['href'] }}" @if ($heroUrl !== '') style="--hero:url('{{ $heroUrl }}')" @endif @guest onclick="const d = document.getElementById('login-dialog'); if (d) { event.preventDefault(); d.showModal(); }" @endguest>
                @if ($slide['image'])
                    <img class="lobby-hero-art" src="{{ $slide['image'] }}" alt="{{ $slide['name'] }}" decoding="async" @if ($slide['eager']) fetchpriority="high" loading="eager" @else fetchpriority="low" loading="lazy" @endif>
                @endif
                <div class="lobby-hero-copy">
                    <div class="kicker-wrap">
                        @if ($slide['winner'] ?? false)
                            <span class="kicker kicker-winner">{{ __('home.day_winner') }}</span>
                        @endif
                        @if ($slide['provider'])
                            <span class="kicker">{{ $slide['provider'] }}</span>
                        @endif
                    </div>
                    <strong>{{ $slide['name'] }}</strong>
                    @if (! empty($slide['yesterday']))
                        <span class="hero-win-line">{{ __('home.yesterday_won', ['amount' => $slide['yesterday']]) }}</span>
                    @endif
                    <span class="lobby-hero-cta">{{ __($slide['cta'] ?? 'home.play_now') }}</span>
                </div>
            </a>
        @endforeach
        @if (count($slides) > 1)
            <div class="lobby-hero-progress">
                @foreach ($slides as $index => $slide)
                    <button type="button" class="{{ $loop->first ? 'is-on' : '' }}" data-hero="{{ $index }}" aria-label="{{ __('home.slide_label', ['n' => $index + 1]) }}"><span class="fill"></span></button>
                @endforeach
            </div>
        @endif
</div>
<script>
(function () {
    var hero = document.getElementById('lobbyHero');
    if (!hero) return;
    var slides = hero.querySelectorAll('.lobby-hero-slide');
    var ticks = hero.querySelectorAll('[data-hero]');
    var i = 0;
    var timer = null;
    var Dwell = 4500;
    function go(n) {
        if (!slides.length) return;
        i = ((n % slides.length) + slides.length) % slides.length;
        slides.forEach(function (s, idx) { s.classList.toggle('is-on', idx === i); });
        ticks.forEach(function (t, idx) {
            t.classList.toggle('is-on', idx === i);
            var fill = t.querySelector('.fill');
            if (fill && idx === i) { fill.style.animation = 'none'; void fill.offsetWidth; fill.style.animation = ''; }
        });
        restart();
    }
    function restart() {
        if (timer) clearInterval(timer);
        if (slides.length < 2) return;
        timer = setInterval(function () { go(i + 1); }, Dwell);
    }
    ticks.forEach(function (t) {
        t.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            go(parseInt(t.getAttribute('data-hero'), 10));
        });
    });
    hero.addEventListener('mouseenter', function () { hero.classList.add('is-paused'); if (timer) clearInterval(timer); });
    hero.addEventListener('mouseleave', function () { hero.classList.remove('is-paused'); go(i); });
    restart();
})();
</script>
