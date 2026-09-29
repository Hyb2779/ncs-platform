@extends('layouts.panel')

@section('heading', __('panel.users'))

@section('content')
@php
    $viewer = auth()->user();
    $isSuper = $viewer->role === \App\Enums\UserRole::Superadmin;
    $isBayi = $viewer->role === \App\Enums\UserRole::Bayi;
    $symbols = ['USD' => '$', 'EUR' => '€', 'TRY' => '₺'];
    $dots = ['active' => 'bg-emerald-500', 'passive' => 'bg-amber-400', 'banned' => 'bg-rose-500'];
    $tones = ['active' => 'success', 'passive' => 'warning', 'banned' => 'danger'];
    $badge = function (string $label, string $tone): \Illuminate\Support\HtmlString {
        return new \Illuminate\Support\HtmlString(\Illuminate\Support\Facades\Blade::render(
            '<x-panel.badge :tone="$tone">{{ $label }}</x-panel.badge>',
            ['tone' => $tone, 'label' => $label],
        ));
    };
    $base = \Illuminate\Support\Arr::except(request()->query(), ['page', 'status', 'funded', 'idle']);
    $chips = [
        [__('panel.users_ui.chip_all'), []],
        [__('panel.users_ui.chip_active'), ['status' => 'active']],
        [__('panel.users_ui.chip_passive'), ['status' => 'passive']],
        [__('panel.users_ui.chip_funded'), ['funded' => '1']],
        [__('panel.users_ui.chip_idle'), ['idle' => '1']],
    ];
    $chipOn = function (array $set): bool {
        foreach (['status', 'funded', 'idle'] as $key) {
            if ((string) request($key) !== (string) ($set[$key] ?? '')) {
                return false;
            }
        }
        return true;
    };
    $payloads = [];
    $rows = [];
    foreach ($users as $user) {
        $wallet = $user->wallets->firstWhere('currency', $user->currency);
        $isMember = $user->role === \App\Enums\UserRole::Uye;
        $payload = [
            'id' => $user->id,
            'name' => $user->username,
            'balance' => (string) ($wallet?->balance ?? '0'),
            'symbol' => $symbols[$user->currency->value] ?? '',
            'currency' => $user->currency->value,
            'balances' => $user->isMultiCurrency() ? $user->wallets->mapWithKeys(fn ($w) => [$w->currency->value => (string) $w->balance])->all() : null,
            'status' => $user->status->value,
            'canAdjust' => $user->parent_id === $viewer->id || ($isSuper && $isMember),
            'editUrl' => route('panel.users.edit', $user),
            'statusUrl' => route('panel.users.status', $user),
            'passwordUrl' => route('panel.users.password', $user),
            'couponsUrl' => $isMember ? route('panel.coupons.index', ['user' => $user->username]) : null,
            'childrenUrl' => $isMember ? null : route('panel.users.index', ['parent' => $user->id]),
        ];
        $payloads[$user->id] = $payload;
        $json = e(json_encode($payload));
        $actions = '<a href="'.e($payload['editUrl']).'">'.e(__('panel.edit')).'</a>';
        if ($payload['canAdjust']) {
            $actions .= '<button class="ms-3 font-semibold text-emerald-700" type="button" @click="openAdjust('.$json.', \'add\')">'.e(__('wallet.add')).'</button>';
            $actions .= '<button class="ms-3 font-semibold text-rose-700" type="button" @click="openAdjust('.$json.', \'remove\')">'.e(__('wallet.remove')).'</button>';
        }
        $actions .= '<button class="ms-3 font-semibold" type="button" @click="openActions('.$json.')">'.e(__('panel.users_ui.more')).'</button>';
        $rows[] = [
            'username' => $isMember
                ? $user->username
                : new \Illuminate\Support\HtmlString('<a href="'.e($payload['childrenUrl']).'">'.e($user->username).'</a>'),
            'role' => __('panel.roles.'.$user->role->value),
            'status' => $badge(__('panel.statuses.'.$user->status->value), $tones[$user->status->value] ?? 'warning'),
            'commission' => $user->formattedCommissionRate(),
            'limit' => $user->formattedChildLimit(),
            'login' => $user->formattedLastLogin(),
            'balance' => $wallet?->formattedBalance(),
            'actions' => new \Illuminate\Support\HtmlString($actions),
        ];
    }
    $columns = array_values(array_filter([
        ['key' => 'username', 'label' => __('panel.fields.username')],
        $isBayi ? null : ['key' => 'role', 'label' => __('panel.fields.role')],
        ['key' => 'status', 'label' => __('panel.fields.status')],
        ['key' => 'balance', 'label' => __('wallet.balance')],
        ['key' => 'commission', 'label' => __('panel.fields.commission_rate'), 'priority' => 'detail'],
        ['key' => 'login', 'label' => __('panel.fields.last_login')],
        ['key' => 'actions', 'label' => __('panel.fields.actions')],
    ]));
    $balances = collect($summary['balances'])
        ->map(fn ($total, $currency) => \App\Support\Money::format((string) $total, \App\Enums\Currency::from($currency)))
        ->implode(' · ');
@endphp
<div x-data="balanceSheet(@js(['own' => (string) ($viewer->wallets()->where('currency', $viewer->currency)->value('balance') ?? '0'), 'ownBy' => $viewer->wallets()->get()->mapWithKeys(fn ($w) => [$w->currency->value => (string) $w->balance])->all(), 'symbols' => $symbols ?? [], 'unlimited' => $viewer->role === \App\Enums\UserRole::Owner]))">
    @if (count($breadcrumb) > 1)
        <nav class="mb-3 flex flex-wrap gap-2 text-sm text-start">
            @foreach ($breadcrumb as $crumb)
                <a class="text-slate-600" href="{{ route('panel.users.index', $crumb->id === auth()->id() ? [] : ['parent' => $crumb->id]) }}">{{ $crumb->username }}</a>
                @if (! $loop->last)
                    <span aria-hidden="true">›</span>
                @endif
            @endforeach
        </nav>
    @endif

    @if (session('reset_password'))
        <div class="mb-3 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm" x-data="{ copied: false }">
            <p class="font-semibold">{{ __('panel.users_ui.password_result') }}</p>
            <p class="mt-0.5 text-slate-600">{{ session('reset_password')['username'] }}</p>
            <div class="mt-2 flex items-center gap-2">
                <code class="min-w-0 flex-1 select-all break-all rounded-md bg-white px-3 py-2 font-numeric text-base" x-ref="pw">{{ session('reset_password')['password'] }}</code>
                <button class="inline-flex h-11 shrink-0 items-center rounded-lg border border-[#E3E6EB] bg-white px-3" type="button" @click="copyText($refs.pw.textContent.trim()); copied = true">
                    <span x-show="! copied">{{ __('panel.users_ui.copy') }}</span>
                    <span x-show="copied" x-cloak>{{ __('panel.users_ui.copied') }}</span>
                </button>
            </div>
        </div>
    @endif

    <div class="mb-3 grid grid-cols-3 gap-2">
        <div class="rounded-xl bg-white p-3">
            <p class="text-xs text-slate-500">{{ __('panel.users_ui.summary_total') }}</p>
            <p class="font-numeric text-lg font-bold">{{ $summary['total'] }}</p>
        </div>
        <div class="rounded-xl bg-white p-3">
            <p class="text-xs text-slate-500">{{ __('panel.users_ui.summary_active') }}</p>
            <p class="font-numeric text-lg font-bold">{{ $summary['active'] }}</p>
        </div>
        <div class="min-w-0 rounded-xl bg-white p-3">
            <p class="text-xs text-slate-500">{{ __('panel.users_ui.summary_balance') }}</p>
            <p class="truncate font-numeric text-lg font-bold">{{ $balances !== '' ? $balances : '—' }}</p>
        </div>
    </div>

    <form class="mb-2 flex gap-2" method="GET">
        @foreach (\Illuminate\Support\Arr::except(request()->query(), ['q', 'sort', 'page']) as $key => $value)
            @if (is_scalar($value))
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endif
        @endforeach
        <input class="h-11 min-w-0 flex-1 rounded-md border border-slate-300 bg-white px-3 text-sm" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('panel.search_username') }}" enterkeyhint="search">
        <select class="h-11 w-auto rounded-md border border-slate-300 bg-white px-2 text-sm" name="sort" onchange="this.form.submit()">
            @foreach (['username', 'balance', 'login'] as $option)
                <option value="{{ $option }}" @selected($sort === $option)>{{ __('panel.users_ui.sort_'.$option) }}</option>
            @endforeach
        </select>
        @if ($canCreate)
            <a class="inline-flex h-11 shrink-0 items-center rounded-lg bg-[#161A22] px-3 text-sm text-white" href="{{ route('panel.users.create', $parent->id === auth()->id() ? [] : ['parent' => $parent->id]) }}" aria-label="{{ __('panel.create_user') }}">
                <span class="md:hidden">+ {{ __('panel.users_ui.create_short') }}</span>
                <span class="hidden md:inline">{{ __('panel.create_user') }}</span>
            </a>
        @endif
    </form>

    <div class="no-scrollbar mb-3 flex gap-2 overflow-x-auto">
        @foreach ($chips as [$label, $set])
            <a class="inline-flex h-9 shrink-0 items-center rounded-full border px-3 text-sm {{ $chipOn($set) ? 'border-[#161A22] bg-[#161A22] text-white' : 'border-[#E3E6EB] bg-white text-slate-600' }}" href="{{ route('panel.users.index', array_merge($base, $set)) }}">{{ $label }}</a>
        @endforeach
    </div>

    <div class="overflow-hidden rounded-xl bg-white md:hidden">
        <div data-user-rows>
            @forelse ($users as $user)
                @php
                    $wallet = $user->wallets->firstWhere('currency', $user->currency);
                    $isMember = $user->role === \App\Enums\UserRole::Uye;
                @endphp
                <button class="flex w-full items-center gap-3 border-b border-[#EEF0F3] px-3 py-2.5 text-start" type="button" @click="openActions(@js($payloads[$user->id]))">
                    <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $dots[$user->status->value] ?? 'bg-slate-300' }}"></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold">{{ $user->username }}</span>
                        <span class="block truncate text-xs text-slate-500">{{ $user->formattedLastLogin() }}@if (! $isMember) · {{ __('panel.users_ui.children_count', ['count' => $user->children_count]) }}@endif</span>
                    </span>
                    <span class="shrink-0 font-numeric text-sm font-semibold">{{ $wallet?->formattedBalance() ?? '—' }}</span>
                    <span class="shrink-0 text-slate-400" aria-hidden="true">⋮</span>
                </button>
            @empty
                <p class="p-4 text-sm text-slate-500">{{ __('panel.empty_users') }}</p>
            @endforelse
        </div>
    </div>
    @if ($users->hasMorePages())
        <button class="mt-3 flex h-11 w-full items-center justify-center rounded-lg border border-[#E3E6EB] bg-white text-sm disabled:opacity-50 md:hidden" type="button" data-next-page="{{ $users->nextPageUrl() }}" @click="loadMore($el)" :disabled="loading">{{ __('panel.users_ui.load_more') }}</button>
    @endif

    <div class="hidden md:block">
        <x-panel.table :empty="__('panel.empty_users')" :columns="$columns" :rows="$rows" />
        <div class="mt-3">{{ $users->links() }}</div>
    </div>

    <div class="fixed inset-0 z-40 flex items-end md:items-center md:justify-center md:p-4" x-show="actions" x-cloak @keydown.escape.window="actions = false">
        <div class="absolute inset-0 bg-slate-900/40" @click="actions = false"></div>
        <div class="relative grid w-full gap-1 rounded-t-xl bg-white p-4 pb-[max(1rem,env(safe-area-inset-bottom))] text-start md:max-w-sm md:rounded-lg md:pb-4">
            <div class="mb-2 flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="truncate text-base font-semibold" x-text="current.name"></p>
                    <p class="font-numeric text-sm text-slate-500" x-text="current.symbol !== undefined ? fmtWith(Number(current.balance), current.symbol) : ''"></p>
                </div>
                <button class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-2xl text-slate-500" type="button" @click="actions = false" aria-label="{{ __('wallet.cancel') }}">&times;</button>
            </div>
            <div class="mb-1 grid grid-cols-2 gap-2" x-show="current.canAdjust">
                <button class="h-12 rounded-lg bg-emerald-50 text-sm font-semibold text-emerald-700" type="button" @click="openAdjust(current, 'add')">{{ __('wallet.add') }}</button>
                <button class="h-12 rounded-lg bg-rose-50 text-sm font-semibold text-rose-700" type="button" @click="openAdjust(current, 'remove')">{{ __('wallet.remove') }}</button>
            </div>
            <form method="POST" :action="current.statusUrl" x-show="current.status !== 'banned'">
                @csrf
                <button class="flex h-12 w-full items-center rounded-lg px-3 text-start text-sm font-medium hover:bg-slate-50" type="submit" x-text="current.status === 'active' ? @js(__('panel.users_ui.status_off')) : @js(__('panel.users_ui.status_on'))"></button>
            </form>
            <button class="flex h-12 w-full items-center rounded-lg px-3 text-start text-sm font-medium hover:bg-slate-50" type="button" @click="actions = false; password = true">{{ __('panel.users_ui.password') }}</button>
            <a class="flex h-12 w-full items-center rounded-lg px-3 text-sm font-medium hover:bg-slate-50" :href="current.editUrl">{{ __('panel.edit') }}</a>
            <a class="flex h-12 w-full items-center rounded-lg px-3 text-sm font-medium hover:bg-slate-50" :href="current.couponsUrl" x-show="current.couponsUrl">{{ __('panel.users_ui.coupons') }}</a>
            <a class="flex h-12 w-full items-center rounded-lg px-3 text-sm font-medium hover:bg-slate-50" :href="current.childrenUrl" x-show="current.childrenUrl">{{ __('panel.users_ui.children') }}</a>
        </div>
    </div>

    <div class="fixed inset-0 z-40 flex items-end md:items-center md:justify-center md:p-4" x-show="password" x-cloak @keydown.escape.window="password = false">
        <div class="absolute inset-0 bg-slate-900/40" @click="password = false"></div>
        <form class="relative grid w-full gap-3 rounded-t-xl bg-white p-4 pb-[max(1rem,env(safe-area-inset-bottom))] text-start md:max-w-md md:rounded-lg md:pb-4" method="POST" :action="current.passwordUrl">
            @csrf
            <div class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="text-base font-semibold">{{ __('panel.users_ui.password') }}</h2>
                    <p class="truncate text-sm text-slate-500" x-text="current.name"></p>
                </div>
                <button class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-2xl text-slate-500" type="button" @click="password = false" aria-label="{{ __('wallet.cancel') }}">&times;</button>
            </div>
            <label class="grid gap-1 text-sm">{{ __('panel.users_ui.password_new') }}
                <input class="h-11 w-full rounded-md border border-[#E3E6EB] px-3" type="text" name="password" minlength="8" maxlength="255" autocomplete="new-password" autocapitalize="off" spellcheck="false">
            </label>
            <p class="text-xs text-slate-500">{{ __('panel.users_ui.password_hint') }}</p>
            <div class="grid grid-cols-2 gap-2 md:flex md:justify-end">
                <button class="inline-flex h-11 items-center justify-center rounded-lg border border-[#E3E6EB] bg-white px-4 text-sm" type="button" @click="password = false">{{ __('wallet.cancel') }}</button>
                <button class="inline-flex h-11 items-center justify-center rounded-lg bg-[#161A22] px-4 text-sm text-white" type="submit">{{ __('panel.users_ui.password') }}</button>
            </div>
        </form>
    </div>

    <div class="fixed inset-0 z-40 flex items-end md:items-center md:justify-center md:p-4" x-show="open" x-cloak @keydown.escape.window="open = false">
        <div class="absolute inset-0 bg-slate-900/40" @click="open = false"></div>
        <form class="relative grid w-full gap-3 rounded-t-xl bg-white p-4 pb-[max(1rem,env(safe-area-inset-bottom))] text-start md:max-w-md md:rounded-lg md:pb-4" method="POST" :action="'{{ url('/panel/users') }}/' + target + '/balance'" @submit="if (blocked()) $event.preventDefault()">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}" :value="key">
            <input type="hidden" name="direction" :value="direction">
            <input type="hidden" name="currency" :value="balances ? currency : ''">
            <input type="hidden" name="amount" :value="amount() ? amount().toFixed(2) : ''">
            <div class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="text-base font-semibold">{{ __('wallet.adjust') }}</h2>
                    <p class="truncate text-sm text-slate-500" x-text="name"></p>
                </div>
                <button class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-2xl text-slate-500" type="button" @click="open = false" aria-label="{{ __('wallet.cancel') }}">&times;</button>
            </div>
            <div class="grid grid-cols-2 gap-1 rounded-lg bg-slate-100 p-1">
                <button class="h-10 rounded-md text-sm font-semibold" type="button" :class="direction === 'add' ? 'bg-white text-emerald-700 shadow' : 'text-slate-500'" @click="direction = 'add'">{{ __('wallet.add') }}</button>
                <button class="h-10 rounded-md text-sm font-semibold" type="button" :class="direction === 'remove' ? 'bg-white text-rose-700 shadow' : 'text-slate-500'" @click="direction = 'remove'">{{ __('wallet.remove') }}</button>
            </div>
            <div class="grid grid-cols-3 gap-1 rounded-lg bg-slate-100 p-1" x-show="balances">
                <template x-for="c in ['TRY', 'USD', 'EUR']" :key="c">
                    <button class="h-10 rounded-md text-sm font-semibold" type="button" :class="currency === c ? 'bg-white text-slate-900 shadow' : 'text-slate-500'" @click="pickCurrency(c)" x-text="c"></button>
                </template>
            </div>
            <div class="flex items-center justify-between text-sm">
                <span class="text-slate-500">{{ __('wallet.current_balance') }}</span>
                <span class="font-numeric font-semibold" x-text="fmt(balance)"></span>
            </div>
            <div class="flex items-center justify-between text-sm" x-show="direction === 'add' && ! unlimited">
                <span class="text-slate-500">{{ __('wallet.your_balance') }}</span>
                <span class="font-numeric font-semibold" :class="ownTooMuch() ? 'text-rose-600' : ''" x-text="fmt(own)"></span>
            </div>
            <label class="grid gap-1 text-sm">{{ __('wallet.amount') }}
                <input class="h-12 w-full rounded-md border border-[#E3E6EB] px-3 font-numeric text-lg" x-ref="amount" x-model="raw" inputmode="decimal" autocomplete="off" required>
            </label>
            <div class="flex items-center justify-between text-sm" x-show="amount()">
                <span class="text-slate-500">{{ __('wallet.after_balance') }}</span>
                <span class="font-numeric font-semibold" :class="tooMuch() ? 'text-rose-600' : ''" x-text="fmt(after())"></span>
            </div>
            <p class="text-sm text-rose-600" x-show="tooMuch()">{{ __('wallet.exceeds_balance') }}</p>
            <p class="text-sm text-rose-600" x-show="ownTooMuch()">{{ __('wallet.exceeds_own_balance') }}</p>
            <label class="grid gap-1 text-sm">{{ __('wallet.note') }}
                <input class="h-11 w-full rounded-md border border-[#E3E6EB] px-3" name="note" maxlength="2000">
            </label>
            <div class="grid grid-cols-2 gap-2 md:flex md:justify-end">
                <button class="inline-flex h-11 items-center justify-center rounded-lg border border-[#E3E6EB] bg-white px-4 text-sm" type="button" @click="open = false">{{ __('wallet.cancel') }}</button>
                <button class="inline-flex h-11 items-center justify-center rounded-lg bg-[#161A22] px-4 text-sm text-white disabled:opacity-40" type="submit" :disabled="blocked()">{{ __('wallet.submit') }}</button>
            </div>
        </form>
    </div>
</div>
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
@endsection
