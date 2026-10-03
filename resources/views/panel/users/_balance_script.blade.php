<script>
    window.copyText = (text) => {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        const area = document.createElement('textarea');
        area.value = text;
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        document.execCommand('copy');
        area.remove();
    };

    window.balanceSheet = (me = {}) => ({
        own: Number(me.own ?? 0), unlimited: Boolean(me.unlimited),
        ownBy: me.ownBy ?? {}, symbols: me.symbols ?? {}, currency: '', balances: null,
        open: false, actions: false, password: false, loading: false, current: {},
        target: '', name: '', balance: 0, symbol: '', direction: 'add', raw: '', key: makeUuid(),
        pickCurrency(c) {
            this.currency = c;
            if (this.balances) this.balance = Number(this.balances[c] ?? 0);
            this.symbol = this.symbols[c] ?? c;
            this.own = Number(this.ownBy[c] ?? 0);
        },
        openActions(user) {
            this.current = user;
            this.actions = true;
        },
        openAdjust(user, direction) {
            const cur = user.currency || '';
            Object.assign(this, { actions: false, target: user.id, name: user.name, balance: Number(user.balance), symbol: user.symbol, currency: cur, balances: user.balances || null, own: Number(this.ownBy[cur] ?? this.own), direction, raw: '', key: makeUuid(), open: true });
            this.$nextTick(() => this.$refs.amount.focus());
        },
        async loadMore(button) {
            if (this.loading) {
                return;
            }
            this.loading = true;
            try {
                const response = await fetch(button.dataset.nextPage, { headers: { Accept: 'text/html' } });
                const page = new DOMParser().parseFromString(await response.text(), 'text/html');
                const list = document.querySelector('[data-user-rows]');
                page.querySelectorAll('[data-user-rows] > *').forEach((row) => list.appendChild(document.importNode(row, true)));
                const next = page.querySelector('[data-next-page]');
                if (next) {
                    button.dataset.nextPage = next.dataset.nextPage;
                } else {
                    button.remove();
                }
            } finally {
                this.loading = false;
            }
        },
        amount() {
            let v = String(this.raw).replace(/\s/g, '');
            if (v.includes(',')) {
                v = v.replace(/\./g, '').replace(',', '.');
            } else if (/^\d{1,3}(\.\d{3})+$/.test(v)) {
                v = v.replace(/\./g, '');
            }
            const n = Number(v);
            return Number.isFinite(n) && n > 0 ? Math.round(n * 100) / 100 : 0;
        },
        after() { return this.direction === 'add' ? this.balance + this.amount() : this.balance - this.amount(); },
        tooMuch() { return this.direction === 'remove' && this.amount() > this.balance; },
        ownTooMuch() { return this.direction === 'add' && ! this.unlimited && this.amount() > this.own; },
        blocked() { return ! this.amount() || this.tooMuch() || this.ownTooMuch(); },
        fmtWith(n, symbol) {
            const lang = document.documentElement.lang;
            const locale = lang === 'de' ? 'de-DE' : (lang === 'tr' ? 'tr-TR' : 'en-US');
            const whole = Math.round(n * 100) % 100 === 0;
            return new Intl.NumberFormat(locale, { minimumFractionDigits: whole ? 0 : 2, maximumFractionDigits: 2 }).format(n) + ' ' + symbol;
        },
        fmt(n) { return this.fmtWith(n, this.symbol); },
    });
</script>
