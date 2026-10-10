@if (($liveFixtures ?? collect())->isNotEmpty())
    <div class="flex items-center overflow-hidden border-b border-[var(--site-line)] bg-[var(--site-bg-deep)]" data-live-rail-wrap>
        <span class="ms-4 inline-flex shrink-0 items-center rounded bg-[var(--site-live)] px-2 py-1 text-[11px] font-extrabold tracking-wider text-white md:ms-6">{{ __('site.live_badge') }}</span>
        <div class="min-w-0 flex-1 overflow-hidden py-1" data-live-rail-view>
            <div class="flex w-max items-center" data-live-rail>
                @foreach ([false, true] as $copy)
                    <div class="flex items-center gap-3 pe-3" @if ($copy) aria-hidden="true" @endif>
                        @foreach ($liveFixtures as $live)
                            <a data-live-fixture="{{ $live->id }}" class="flex shrink-0 items-center gap-2 rounded-md bg-[var(--site-panel)] px-3 py-1.5 text-[13px]" href="{{ route('site.sport.show', $live) }}" @if($copy) tabindex="-1" @endif>
                                <span data-live-clock class="font-numeric text-xs font-extrabold text-[var(--accent)]">{{ sport_clock($live) }}</span>
                                <span class="flex flex-col text-[var(--site-text-2)]">
                                    <span class="break-words">{{ sport_name($live->home) }}</span>
                                    <span class="break-words">{{ sport_name($live->away) }}</span>
                                </span>
                                <span data-live-score class="font-numeric text-base font-bold text-white">{{ $live->score_home ?? '0' }} : {{ $live->score_away ?? '0' }}</span>
                            </a>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    </div>
    <script>
        (function () {
            var wrap = document.querySelector('[data-live-rail-wrap]');
            var view = document.querySelector('[data-live-rail-view]');
            var rail = document.querySelector('[data-live-rail]');
            if (!wrap || !view || !rail || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
            var paused = false;
            var hold;
            var last = performance.now();
            var offset = 0;
            function pause() {
                paused = true;
                clearTimeout(hold);
            }
            function resume() {
                clearTimeout(hold);
                hold = setTimeout(function () { paused = false; last = performance.now(); }, 900);
            }
            wrap.addEventListener('pointerdown', pause);
            wrap.addEventListener('pointerup', resume);
            wrap.addEventListener('pointercancel', resume);
            wrap.addEventListener('pointerleave', resume);
            function step(now) {
                var half = rail.scrollWidth / 2;
                if (!paused && half > view.clientWidth) {
                    var dt = Math.min(now - last, 50);
                    offset += (half / 45000) * dt;
                    if (offset >= half) offset -= half;
                    var rtl = getComputedStyle(rail).direction === 'rtl';
                    rail.style.transform = 'translate3d(' + (rtl ? offset : -offset) + 'px,0,0)';
                }
                last = now;
                requestAnimationFrame(step);
            }
            requestAnimationFrame(step);
        })();
    </script>
@endif
