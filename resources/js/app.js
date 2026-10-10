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
    Alpine.data('homeHero', () => ({
        i: 0,
        n: 0,
        rtl: false,
        paused: false,
        timer: null,
        originX: null,
        swiped: false,
        dwell: 4500,
        init() {
            this.n = Number(this.$el.dataset.slides || 0);
            this.rtl = document.documentElement.dir === 'rtl';
            this.play();
        },
        play() {
            this.stop();
            if (this.n < 2 || this.paused) {
                return;
            }
            this.timer = window.setInterval(() => this.go(this.i + 1), this.dwell);
        },
        stop() {
            if (this.timer) {
                window.clearInterval(this.timer);
            }
            this.timer = null;
        },
        enter() {
            this.paused = true;
            this.stop();
        },
        leave() {
            this.paused = false;
            this.go(this.i);
        },
        go(index) {
            if (this.n < 1) {
                return;
            }
            this.i = ((index % this.n) + this.n) % this.n;
            this.$nextTick(() => {
                const fill = this.$el.querySelector('.home-hero-progress button.is-on .fill');
                if (!fill) {
                    return;
                }
                fill.style.animation = 'none';
                void fill.offsetWidth;
                fill.style.animation = '';
            });
            this.play();
        },
        down(event) {
            if (event.target.closest('.home-hero-progress')) {
                return;
            }
            if (event.pointerType === 'mouse' && event.button !== 0) {
                return;
            }
            this.originX = event.clientX;
        },
        up(event) {
            if (this.originX === null) {
                return;
            }
            const delta = event.clientX - this.originX;
            this.originX = null;
            if (Math.abs(delta) < 40) {
                return;
            }
            this.swiped = true;
            const forward = this.rtl ? delta > 0 : delta < 0;
            this.go(this.i + (forward ? 1 : -1));
        },
        open(event) {
            if (!this.swiped) {
                return;
            }
            event.preventDefault();
            this.swiped = false;
        },
    }));

    Alpine.data('gameSuggest', (options) => ({
        q: '',
        open: false,
        games: [],
        total: 0,
        active: -1,
        timer: null,
        labels: options || {},
        init() {
            this.q = this.$el.querySelector('input[name="q"]')?.value || '';
        },
        schedule() {
            this.active = -1;
            if (this.timer) {
                window.clearTimeout(this.timer);
            }
            if (this.q.trim().length < 2) {
                this.games = [];
                this.total = 0;
                this.close();

                return;
            }
            this.timer = window.setTimeout(() => this.load(), 300);
        },
        async load() {
            const term = this.q.trim();
            if (term.length < 2) {
                return;
            }
            const url = new URL(this.labels.url, window.location.origin);
            url.searchParams.set('q', term);
            url.searchParams.set('mode', this.labels.mode || 'slot');
            if (this.labels.vendor) {
                url.searchParams.set('vendor', this.labels.vendor);
            }
            try {
                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                if (!response.ok || this.q.trim() !== term) {
                    return;
                }
                const payload = await response.json();
                if (this.q.trim() !== term) {
                    return;
                }
                this.games = Array.isArray(payload.games) ? payload.games : [];
                this.total = Number(payload.total || 0);
                this.open = true;
            } catch (error) {
                // Liste açılmaz; Enter tam sonuç sayfasına gider.
            }
        },
        close() {
            this.open = false;
            this.active = -1;
        },
        move(step) {
            if (!this.open || this.games.length === 0) {
                return;
            }
            const last = this.games.length - 1;
            if (this.active < 0) {
                this.active = step > 0 ? 0 : last;

                return;
            }
            this.active = Math.min(last, Math.max(0, this.active + step));
        },
        submit(event) {
            if (!(this.open && this.active >= 0 && this.games[this.active])) {
                return;
            }
            event.preventDefault();
            this.pick(event, this.games[this.active]);
        },
        pick(event, game) {
            const dialog = document.getElementById('login-dialog');
            if (dialog && String(game.href).includes('/login')) {
                event.preventDefault();
                dialog.showModal();

                return;
            }
            if (event.type === 'keydown') {
                window.location.href = game.href;
            }
        },
        allHref() {
            const url = new URL(window.location.href);
            url.searchParams.set('q', this.q.trim());

            return url.pathname + url.search;
        },
        allText() {
            return String(this.labels.all || '').replace(':count', String(this.total));
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

    if (response.status === 401) {
        const payload = await response.json().catch(() => ({}));
        if (payload.redirect) {
            window.location.assign(payload.redirect);
        }

        return;
    }

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

const homeMatches = document.querySelector('[data-home-matches]');

function splitWhen(clock) {
    const text = String(clock || '').trim();
    const space = text.lastIndexOf(' ');
    if (space <= 0) {
        return { day: text, time: '' };
    }

    return { day: text.slice(0, space), time: text.slice(space + 1) };
}

function splitScore(score) {
    const parts = String(score || '').trim().split(' - ');
    if (parts.length !== 2 || parts[0] === '' || parts[1] === '') {
        return null;
    }

    return { home: parts[0], away: parts[1] };
}

function leagueLabel(row) {
    const league = row.league || '';
    const country = row.country || '';
    if (league && country) {
        return `${league} · ${country}`;
    }

    return league || country;
}

function paintMatchList(list, rows, href, live) {
    if (!list || !homeMatches) {
        return;
    }
    const template = homeMatches.querySelector('[data-match-template]');
    const card = list.closest('[data-match-card]');
    list.replaceChildren();
    rows.slice(0, 5).forEach((row) => {
        const node = template.content.firstElementChild.cloneNode(true);
        node.href = row.url || href;
        const badge = node.querySelector('[data-side="badge"]');
        const day = node.querySelector('[data-side="day"]');
        const clock = node.querySelector('[data-side="clock"]');
        const scores = node.querySelector('[data-side="scores"]');
        if (live && row.badge) {
            badge.hidden = false;
            badge.textContent = row.badge;
            day.hidden = true;
            day.textContent = '';
            clock.textContent = row.clock || '';
        } else {
            badge.hidden = true;
            badge.textContent = '';
            const when = splitWhen(row.clock || '');
            day.hidden = when.day === '';
            day.textContent = when.day;
            clock.textContent = when.time;
        }
        node.querySelector('[data-side="home"]').textContent = row.home || '';
        node.querySelector('[data-side="away"]').textContent = row.away || '';
        node.querySelector('[data-side="league"]').textContent = leagueLabel(row);
        const score = live ? splitScore(row.score) : null;
        node.querySelector('[data-side="score-home"]').textContent = score ? score.home : '';
        node.querySelector('[data-side="score-away"]').textContent = score ? score.away : '';
        scores.hidden = !score;
        list.appendChild(node);
    });
    if (card) {
        card.hidden = rows.length === 0;
    }
}

async function refreshHomeMatches() {
    if (!homeMatches || document.hidden) {
        return;
    }

    try {
        const response = await fetch(homeMatches.dataset.refreshUrl, { headers: { Accept: 'application/json' } });
        if (!response.ok) {
            return;
        }
        const payload = await response.json();
        const live = Array.isArray(payload.live) ? payload.live : [];
        const upcoming = Array.isArray(payload.upcoming) ? payload.upcoming : [];
        if (live.length === 0 && upcoming.length === 0) {
            homeMatches.hidden = true;
            return;
        }
        homeMatches.hidden = false;
        paintMatchList(homeMatches.querySelector('[data-match-list="live"]'), live, homeMatches.dataset.liveUrl, true);
        paintMatchList(homeMatches.querySelector('[data-match-list="upcoming"]'), upcoming, homeMatches.dataset.sportUrl, false);
    } catch (error) {
        // Sessiz: önceki satırlar ekranda kalır.
    }
}

if (homeMatches) {
    const refreshMs = Number(homeMatches.dataset.refreshMs || 30000);
    let matchTimer = null;

    const armMatchRefresh = () => {
        if (matchTimer) {
            window.clearInterval(matchTimer);
            matchTimer = null;
        }
        if (document.hidden) {
            return;
        }
        matchTimer = window.setInterval(refreshHomeMatches, refreshMs);
    };

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            if (matchTimer) {
                window.clearInterval(matchTimer);
                matchTimer = null;
            }
            return;
        }
        refreshHomeMatches();
        armMatchRefresh();
    });

    armMatchRefresh();
}

document.querySelectorAll('[data-load-more]').forEach((button) => {
    button.addEventListener('click', async (event) => {
        event.preventDefault();
        if (button.dataset.busy === '1') {
            return;
        }
        button.dataset.busy = '1';
        try {
            const response = await fetch(button.href, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const type = response.headers.get('content-type') || '';
            if (!response.ok || !type.includes('application/json')) {
                button.dataset.busy = '0';
                return;
            }
            const payload = await response.json();
            document.querySelector('[data-movements-cards]')?.insertAdjacentHTML('beforeend', payload.cards || '');
            document.querySelector('[data-movements-rows]')?.insertAdjacentHTML('beforeend', payload.rows || '');
            if (payload.next) {
                button.href = payload.next;
                button.dataset.busy = '0';
            } else {
                button.remove();
            }
        } catch (error) {
            button.dataset.busy = '0';
        }
    });
});
