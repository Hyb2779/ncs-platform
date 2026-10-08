import Alpine from 'alpinejs';
import translations from 'virtual:i18n';
import { mountPanelCharts } from './panel-chart';

window.Alpine = Alpine;
window.translations = translations;

document.addEventListener('alpine:init', () => {
    Alpine.data('resultLeague', (options, selected, allLabel) => ({
        options,
        selected: selected ? String(selected) : '',
        allLabel,
        q: '',
        open: false,
        fold(value) {
            return String(value).replace(/[İIı]/g, 'i').replace(/[Şş]/g, 's').replace(/[Ğğ]/g, 'g').replace(/[Üü]/g, 'u').replace(/[Öö]/g, 'o').replace(/[Çç]/g, 'c').toLowerCase();
        },
        get shown() {
            const key = this.fold(this.q);
            if (key === '') {
                return this.options;
            }

            return this.options.filter((item) => this.fold(item.label).includes(key));
        },
        current() {
            const hit = this.options.find((item) => String(item.id) === this.selected);

            return hit ? hit.label : this.allLabel;
        },
        choose(id) {
            this.selected = id === null ? '' : String(id);
            this.open = false;
            this.q = '';
            this.$nextTick(() => this.$root.closest('form')?.requestSubmit());
        },
    }));
    Alpine.data('homeCarousel', () => ({
        i: 0,
        n: 0,
        rtl: false,
        wide: false,
        cols: 1,
        vw: 0,
        anim: false,
        moving: false,
        over: false,
        timer: null,
        originX: null,
        init() {
            this.n = Number(this.$el.dataset.slides || 0);
            this.rtl = document.documentElement.dir === 'rtl';
            this.layout();
            this.$nextTick(() => { this.anim = true; });
            this.play();
            window.addEventListener('resize', () => this.onResize());
        },
        get deck() {
            return this.wide && this.n >= 3;
        },
        play() {
            this.stop();
            if (!this.wide && this.n < 2) {
                return;
            }
            if (this.wide && this.n < 3) {
                return;
            }
            this.timer = window.setInterval(() => this.next(), 5000);
        },
        stop() {
            if (this.timer) {
                window.clearInterval(this.timer);
            }
            this.timer = null;
        },
        enter() {
            this.over = true;
            this.stop();
        },
        leave() {
            this.over = false;
            this.play();
        },
        next() {
            if (this.wide && this.n < 3) {
                return;
            }
            if (!this.wide) {
                this.i = (this.i + 1) % this.n;
                return;
            }
            if (this.moving) {
                return;
            }
            this.moving = true;
            this.i += 1;
            window.setTimeout(() => { this.moving = false; }, 520);
        },
        prev() {
            if (this.wide && this.n < 3) {
                return;
            }
            if (!this.wide) {
                this.i = (this.i - 1 + this.n) % this.n;
                return;
            }
            if (this.moving) {
                return;
            }
            this.moving = true;
            this.i -= 1;
            window.setTimeout(() => { this.moving = false; }, 520);
        },
        step(dir) {
            if (dir < 0) {
                this.prev();
            } else {
                this.next();
            }
        },
        go(index) {
            this.i = index;
            this.play();
        },
        down(event) {
            if (event.pointerType === 'mouse' && event.button !== 0) {
                return;
            }
            this.originX = event.clientX;
            this.stop();
        },
        up(event) {
            if (this.originX === null) {
                if (!(this.wide && this.over)) {
                    this.play();
                }
                return;
            }
            const delta = event.clientX - this.originX;
            this.originX = null;
            if (Math.abs(delta) > 40) {
                const forward = this.rtl ? delta > 0 : delta < 0;
                if (forward) {
                    this.next();
                } else {
                    this.prev();
                }
            }
            if (!(this.wide && this.over)) {
                this.play();
            }
        },
        shift() {
            if (!this.wide) {
                const sign = this.rtl ? 1 : -1;

                return `translateX(${sign * this.i * 100}%)`;
            }
            if (!this.deck) {
                return 'translateX(0)';
            }
            const gap = 16;
            const width = this.vw || this.$el.clientWidth;
            const card = (width - gap * (this.cols - 1)) / this.cols;
            const sign = this.rtl ? 1 : -1;

            return `translateX(${sign * this.i * (card + gap)}px)`;
        },
        layout() {
            const track = this.$el.querySelector('.home-carousel-track');
            track.querySelectorAll('[data-clone]').forEach((node) => node.remove());
            this.wide = window.innerWidth >= 768;
            this.cols = !this.wide ? 1 : (window.innerWidth >= 1024 ? 3 : 2);
            this.vw = this.$el.querySelector('.home-carousel-view').clientWidth;
            this.moving = false;
            if (!this.deck) {
                this.i = 0;
                return;
            }
            const slides = [...track.querySelectorAll('.home-slide')];
            const before = document.createDocumentFragment();
            const after = document.createDocumentFragment();
            for (let k = 0; k < this.cols; k++) {
                const tail = slides[slides.length - this.cols + k].cloneNode(true);
                const head = slides[k].cloneNode(true);
                [tail, head].forEach((node) => {
                    node.setAttribute('data-clone', '1');
                    node.setAttribute('aria-hidden', 'true');
                    node.inert = true;
                });
                before.appendChild(tail);
                after.appendChild(head);
            }
            track.prepend(before);
            track.append(after);
            this.anim = false;
            this.i = this.cols;
        },
        onResize() {
            const wide = window.innerWidth >= 768;
            const cols = !wide ? 1 : (window.innerWidth >= 1024 ? 3 : 2);
            this.vw = this.$el.querySelector('.home-carousel-view').clientWidth;
            if (wide === this.wide && cols === this.cols) {
                return;
            }
            this.layout();
            this.$nextTick(() => { this.anim = true; });
            this.play();
        },
        landed(event) {
            if (event.target !== event.currentTarget || event.propertyName !== 'transform' || !this.deck) {
                return;
            }
            if (this.i >= this.n + this.cols) {
                this.anim = false;
                this.i -= this.n;
            } else if (this.i < this.cols) {
                this.anim = false;
                this.i += this.n;
            } else {
                this.moving = false;
                return;
            }
            this.$nextTick(() => {
                requestAnimationFrame(() => {
                    this.anim = true;
                    this.moving = false;
                });
            });
        },
    }));
});

window.makeUuid = function makeUuid() {
    try {
        if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
            return crypto.randomUUID();
        }
    } catch (error) {
        // HTTP pages reject randomUUID(); fall through to the RFC4122 v4 fallback.
    }

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
        const r = Math.random() * 16 | 0;
        const v = c === 'x' ? r : (r & 0x3 | 0x8);

        return v.toString(16);
    });
};

Alpine.start();
document.addEventListener('DOMContentLoaded', mountPanelCharts);

const balanceNode = document.querySelector('[data-balance]');
const balanceUrl = document.body?.dataset.balanceUrl;

async function refreshBalance() {
    if (!balanceNode || !balanceUrl) {
        return;
    }

    const response = await fetch(balanceUrl, { headers: { Accept: 'application/json' } });

    if (!response.ok) {
        return;
    }

    const payload = await response.json();
    balanceNode.textContent = payload.balance;
}

if (balanceUrl) {
    setInterval(refreshBalance, 30000);
    window.addEventListener('focus', refreshBalance);
}

const liveNodes = () => document.querySelectorAll('[data-live-fixture]');

async function refreshLiveFixtures() {
    if (document.hidden) {
        return;
    }

    const ids = [...new Set([...liveNodes()].map((node) => node.dataset.liveFixture))];
    if (ids.length === 0) {
        return;
    }

    try {
        const response = await fetch(`/sport/live/data?ids=${ids.join(',')}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) {
            return;
        }

        const { fixtures } = await response.json();
        liveNodes().forEach((node) => {
            const data = fixtures[node.dataset.liveFixture];
            if (!data) {
                return;
            }
            const clock = node.querySelector('[data-live-clock]');
            const score = node.querySelector('[data-live-score]');
            if (clock) clock.textContent = data.clock;
            if (score) score.textContent = data.score;
            node.classList.toggle('opacity-60', !data.live);
        });
    } catch (error) {
        console.warn('live refresh failed', error);
    }
}

if (liveNodes().length > 0) {
    setInterval(refreshLiveFixtures, 60000);
    document.addEventListener('visibilitychange', refreshLiveFixtures);
}

document.querySelectorAll('[data-home-cat] img').forEach((img) => {
    const markPlain = () => img.closest('[data-home-cat]')?.classList.add('is-plain');
    img.addEventListener('error', markPlain);
    if (img.complete && img.naturalWidth === 0) {
        markPlain();
    }
});
