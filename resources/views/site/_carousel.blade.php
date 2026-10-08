<section class="home-hero" data-home-hero data-slides="{{ count($slides) }}" x-data="homeHero()" :class="{ 'is-paused': paused }" @mouseenter="enter()" @mouseleave="leave()" @pointerdown="down($event)" @pointerup="up($event)" @pointercancel="up($event)">
    @foreach ($slides as $index => $slide)
        <a class="home-hero-slide {{ $loop->first ? 'is-on' : '' }}" :class="i === {{ $index }} ? 'is-on' : 'is-off'" href="{{ $slide['href'] }}" @click="open($event)" @guest onclick="const d = document.getElementById('login-dialog'); if (d && !event.defaultPrevented) { event.preventDefault(); d.showModal(); }" @endguest>
            @if ($slide['image'])
                <img class="home-hero-blur" src="{{ $slide['image'] }}" alt="" draggable="false" aria-hidden="true" @if ($slide['eager']) fetchpriority="low" @else loading="lazy" decoding="async" @endif>
            @endif
            @if ($slide['image'])
                <img class="home-hero-art" src="{{ $slide['image'] }}" @if (! empty($slide['srcset'])) srcset="{{ $slide['srcset'] }}" sizes="(min-width: 768px) 148px, 68px" @endif alt="" draggable="false" @if ($slide['eager']) fetchpriority="high" @else loading="lazy" decoding="async" @endif>
            @endif
            <span class="home-hero-copy">
                <span class="home-hero-badges">
                    @if ($slide['winner'] ?? false)
                        <span class="home-hero-badge is-winner">{{ __('home.day_winner') }}</span>
                    @endif
                    @if ($slide['provider'])
                        <span class="home-hero-badge">{{ $slide['provider'] }}</span>
                    @endif
                </span>
                <strong class="home-hero-name">{{ $slide['name'] }}</strong>
                @if (! empty($slide['yesterday']))
                    <span class="home-hero-win">{{ __('home.yesterday_won', ['amount' => $slide['yesterday']]) }}</span>
                @endif
                <span class="home-hero-cta">{{ __($slide['cta'] ?? 'home.play_now') }}</span>
            </span>
        </a>
    @endforeach
    @if (count($slides) > 1)
        <div class="home-hero-progress" role="tablist" aria-label="{{ __('home.slide_nav') }}">
            @foreach ($slides as $index => $slide)
                <button type="button" role="tab" class="{{ $loop->first ? 'is-on' : '' }}" :class="i === {{ $index }} ? 'is-on' : 'is-off'" :aria-selected="(i === {{ $index }}).toString()" aria-label="{{ __('home.slide_label', ['n' => $index + 1]) }}" @click.stop="go({{ $index }})"><span class="fill"></span></button>
            @endforeach
        </div>
    @endif
</section>
