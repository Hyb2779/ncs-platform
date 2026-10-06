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
        timer: null,
        originX: null,
        init() {
            this.n = Number(this.$el.dataset.slides || 0);
            this.rtl = document.documentElement.dir === 'rtl';
            this.play();
        },
        play() {
            this.stop();
            if (this.n < 2) {
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
        next() {
            this.i = (this.i + 1) % this.n;
        },
        prev() {
            this.i = (this.i - 1 + this.n) % this.n;
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
                this.play();
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
            this.play();
        },
        shift() {
            const sign = this.rtl ? 1 : -1;

            return `translateX(${sign * this.i * 100}%)`;
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
