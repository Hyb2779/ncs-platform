<section class="home-carousel" data-home-carousel data-slides="{{ count($slides) }}" x-data="homeCarousel()" :class="{ 'is-static': wide && n < 3 }" @mouseenter="enter()" @mouseleave="leave()" @pointerdown="down($event)" @pointerup="up($event)" @pointercancel="up($event)">
    <button class="home-slide-arrow home-slide-prev" type="button" :hidden="!deck" @click.stop="step(-1)" aria-label="{{ __('home.slide_prev') }}">
        <span class="rtl:rotate-180" aria-hidden="true">&larr;</span>
    </button>
    <button class="home-slide-arrow home-slide-next" type="button" :hidden="!deck" @click.stop="step(1)" aria-label="{{ __('home.slide_next') }}">
        <span class="rtl:rotate-180" aria-hidden="true">&rarr;</span>
    </button>
    <div class="home-carousel-view">
    <div class="home-carousel-track" :class="{ 'is-still': !anim }" :style="{ transform: shift() }" @transitionend="landed($event)">
        @foreach ($slides as $slide)
            <div class="home-slide">
                <div class="home-slide-media">
                    @if ($slide['image'])
                        <img class="home-slide-blur" src="{{ $slide['image'] }}" @if (! empty($slide['srcset'])) srcset="{{ $slide['srcset'] }}" sizes="(min-width: 1024px) 33vw, (min-width: 768px) 50vw, 100vw" @endif alt="" draggable="false" aria-hidden="true" @if ($slide['eager']) fetchpriority="low" @else loading="lazy" decoding="async" @endif>
                        <img class="home-slide-img" src="{{ $slide['image'] }}" @if (! empty($slide['srcset'])) srcset="{{ $slide['srcset'] }}" sizes="(min-width: 1024px) 33vw, (min-width: 768px) 50vw, 100vw" @endif alt="" draggable="false" @if ($slide['eager']) fetchpriority="high" @else loading="lazy" decoding="async" @endif>
                    @endif
                </div>
                <div class="home-slide-copy">
                    @if ($slide['provider'])
                        <p class="home-slide-provider">{{ mb_strtoupper((string) $slide['provider']) }}</p>
                    @endif
                    <h2 class="home-slide-title">{{ $slide['name'] }}</h2>
                    <a class="home-slide-play" href="{{ $slide['href'] }}" @guest onclick="const d = document.getElementById('login-dialog'); if (d) { event.preventDefault(); d.showModal(); }" @endguest>{{ __($slide['cta'] ?? 'home.play_now') }}</a>
                </div>
            </div>
        @endforeach
    </div>
    </div>
    @if (count($slides) > 1)
        <div class="home-slide-dots" role="tablist" aria-label="{{ __('home.slide_nav') }}">
            @foreach ($slides as $index => $slide)
                <button type="button" role="tab" :class="i === {{ $index }} ? 'is-on' : ''" :aria-selected="(i === {{ $index }}).toString()" @click="go({{ $index }})"></button>
            @endforeach
        </div>
    @endif
</section>
