<section class="home-carousel overflow-hidden rounded-2xl border border-[var(--site-line)] bg-[var(--site-panel)]" data-home-carousel data-slides="{{ count($slides) }}" x-data="homeCarousel()" @mouseenter="stop()" @mouseleave="play()" @pointerdown="down($event)" @pointerup="up($event)" @pointercancel="up($event)">
    <div class="overflow-hidden">
        <div class="home-carousel-track flex" :style="{ transform: shift() }">
            @foreach ($slides as $slide)
                <div class="w-full shrink-0" @if ($slide['type'] === 'match') data-slide="match" @endif>
                    @if ($slide['type'] === 'match')
                        <div class="flex max-h-[80dvh] flex-col justify-center gap-4 p-5 md:min-h-[24rem] md:p-12">
                            <span class="self-start rounded-lg bg-[var(--site-panel-2)] px-3 py-1.5 text-xs font-extrabold tracking-wider text-[var(--accent)]">{{ __('home.hero_badge') }}</span>
                            <div class="flex justify-between gap-3 text-[13px] font-bold text-[var(--site-muted)]"><span class="truncate">{{ $slide['featured']['league'] }}</span><span class="shrink-0">{{ $slide['featured']['day'] }} {{ $slide['featured']['time'] }}</span></div>
                            <div class="flex flex-wrap gap-x-2 text-2xl font-extrabold text-[var(--site-text)] md:text-4xl"><span>{{ $slide['featured']['home'] }}</span><span aria-hidden="true">-</span><span>{{ $slide['featured']['away'] }}</span></div>
                            @include('site._home_odds', ['m' => $slide['featured'], 'size' => 'lg'])
                            <a class="inline-flex h-11 w-fit items-center rounded-xl bg-[var(--accent)] px-6 text-[15px] font-extrabold text-[var(--site-on-accent)] md:h-12" href="{{ route('site.wegas_sport') }}">{{ __('home.go_bulletin') }}</a>
                        </div>
                    @else
                        <div class="flex max-h-[80dvh] flex-col md:min-h-[24rem] md:flex-row">
                            <div class="order-2 flex flex-1 flex-col justify-end gap-3 p-5 md:order-1 md:justify-center md:p-12">
                                @if ($slide['provider'])
                                    <span class="self-start rounded-lg bg-[var(--site-panel-2)] px-3 py-1.5 text-xs font-extrabold uppercase tracking-wider text-[var(--accent)]">{{ mb_strtoupper((string) $slide['provider']) }}</span>
                                @endif
                                <h2 class="text-2xl font-extrabold leading-tight text-[var(--site-text)] md:text-5xl">{{ $slide['name'] }}</h2>
                                <a class="inline-flex h-11 w-fit items-center rounded-xl bg-[var(--accent)] px-6 text-[15px] font-extrabold text-[var(--site-on-accent)] md:h-12" href="{{ $slide['href'] }}" @guest onclick="const d = document.getElementById('login-dialog'); if (d) { event.preventDefault(); d.showModal(); }" @endguest>{{ __('home.play_now') }}</a>
                            </div>
                            <div class="order-1 flex h-52 items-center justify-center bg-[var(--site-panel-2)] p-4 md:order-2 md:h-auto md:w-[46%] md:p-8">
                                @if ($slide['image'])
                                    <img class="max-h-full max-w-full object-contain" src="{{ $slide['image'] }}" alt="" @if ($slide['eager']) loading="eager" fetchpriority="high" @else loading="lazy" decoding="async" @endif>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
    @if (count($slides) > 1)
        <div class="flex items-center justify-center gap-2 px-4 py-3" role="tablist" aria-label="{{ __('home.slide_nav') }}">
            @foreach ($slides as $index => $slide)
                <button class="h-1.5 rounded-full transition-[width,background-color]" type="button" role="tab" :class="i === {{ $index }} ? 'w-8 bg-[var(--accent)]' : 'w-4 bg-[var(--site-line-strong)]'" :aria-selected="(i === {{ $index }}).toString()" @click="go({{ $index }})"></button>
            @endforeach
        </div>
    @endif
</section>
