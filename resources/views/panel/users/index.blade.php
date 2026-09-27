@extends('layouts.panel')

@section('heading', __('panel.users'))

@section('content')
@php
    $badge = function (string $label, string $tone): \Illuminate\Support\HtmlString {
        return new \Illuminate\Support\HtmlString(\Illuminate\Support\Facades\Blade::render(
            '<x-panel.badge :tone="$tone">{{ $label }}</x-panel.badge>',
            ['tone' => $tone, 'label' => $label],
        ));
    };
    $rows = [];
    foreach ($users as $user) {
        $tone = match ($user->status->value) {
            'active' => 'success',
            'banned' => 'danger',
            default => 'warning',
        };
        $actions = '<a href="'.e(route('panel.users.edit', $user)).'">'.e(__('panel.edit')).'</a>';
        if ($user->parent_id === auth()->id() || (auth()->user()->role === \App\Enums\UserRole::Superadmin && $user->role === \App\Enums\UserRole::Uye)) {
            $wallet = $user->wallets->firstWhere('currency', $user->currency);
            $payload = e(json_encode([
                'id' => $user->id,
                'name' => $user->username,
                'balance' => (string) ($wallet?->balance ?? '0'),
                'symbol' => match ($user->currency->value) { 'USD' => '$', 'EUR' => '€', default => '₺' },
            ]));
            $actions .= '<button class="ms-3 font-semibold text-emerald-700" type="button" @click="openAdjust('.$payload.', \'add\')">'.e(__('wallet.add')).'</button>';
            $actions .= '<button class="ms-3 font-semibold text-rose-700" type="button" @click="openAdjust('.$payload.', \'remove\')">'.e(__('wallet.remove')).'</button>';
        }
        $rows[] = [
            'username' => new \Illuminate\Support\HtmlString('<a href="'.e(route('panel.users.index', ['parent' => $user->id])).'">'.e($user->username).'</a>'),
            'role' => __('panel.roles.'.$user->role->value),
            'status' => $badge(__('panel.statuses.'.$user->status->value), $tone),
            'commission' => $user->formattedCommissionRate(),
            'limit' => $user->formattedChildLimit(),
            'login' => $user->formattedLastLogin(),
            'balance' => $user->wallets->firstWhere('currency', $user->currency)?->formattedBalance(),
            'actions' => new \Illuminate\Support\HtmlString($actions),
        ];
    }
@endphp
<div x-data="balanceSheet()">
    @if (count($breadcrumb) > 1)
        <nav class="mb-4 flex flex-wrap gap-2 text-sm text-start">
            @foreach ($breadcrumb as $crumb)
                <a class="text-slate-600" href="{{ route('panel.users.index', $crumb->id === auth()->id() ? [] : ['parent' => $crumb->id]) }}">{{ $crumb->username }}</a>
                @if (! $loop->last)
                    <span aria-hidden="true">›</span>
                @endif
            @endforeach
        </nav>
    @endif
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <x-panel.filter-bar class="min-w-0 flex-1">
            <form class="flex flex-wrap gap-2" method="GET">
                @if ($parent->id !== auth()->id())
                    <input type="hidden" name="parent" value="{{ $parent->id }}">
                @endif
                @if (request('from'))
                    <input type="hidden" name="from" value="{{ request('from') }}">
                @endif
                @if (request('to'))
                    <input type="hidden" name="to" value="{{ request('to') }}">
                @endif
                <input class="h-11 rounded-md border border-slate-300 px-3 text-sm" name="q" value="{{ request('q') }}" placeholder="{{ __('panel.search_username') }}">
                <select class="h-11 rounded-md border border-slate-300 px-3 text-sm" name="status">
                    <option value="">{{ __('panel.status_all') }}</option>
                    @foreach (['active', 'passive', 'banned'] as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ __('panel.statuses.'.$status) }}</option>
                    @endforeach
                </select>
                <button class="inline-flex h-11 items-center rounded-lg border border-[#E3E6EB] bg-white px-3 text-sm" type="submit">{{ __('panel.filter') }}</button>
            </form>
        </x-panel.filter-bar>
        @if ($canCreate)
            <a class="inline-flex h-11 items-center rounded-lg bg-[#161A22] px-3 text-sm text-white" href="{{ route('panel.users.create', $parent->id === auth()->id() ? [] : ['parent' => $parent->id]) }}">{{ __('panel.create_user') }}</a>
        @endif
    </div>
    <x-panel.table
        :empty="__('panel.empty_users')"
        :columns="[
            ['key' => 'username', 'label' => __('panel.fields.username')],
            ['key' => 'role', 'label' => __('panel.fields.role')],
            ['key' => 'status', 'label' => __('panel.fields.status')],
            ['key' => 'balance', 'label' => __('wallet.balance')],
            ['key' => 'commission', 'label' => __('panel.fields.commission_rate'), 'priority' => 'detail'],
            ['key' => 'limit', 'label' => __('panel.fields.user_limit'), 'priority' => 'detail'],
            ['key' => 'login', 'label' => __('panel.fields.last_login'), 'priority' => 'detail'],
            ['key' => 'actions', 'label' => __('panel.fields.actions')],
        ]"
        :rows="$rows"
    />
    <div class="fixed inset-0 z-40 flex items-end md:items-center md:justify-center md:p-4" x-show="open" x-cloak @keydown.escape.window="open = false">
        <div class="absolute inset-0 bg-slate-900/40" @click="open = false"></div>
        <form class="relative grid w-full gap-3 rounded-t-xl bg-white p-4 pb-[max(1rem,env(safe-area-inset-bottom))] text-start md:max-w-md md:rounded-lg md:pb-4" method="POST" :action="'{{ url('/panel/users') }}/' + target + '/balance'" @submit="if (! amount() || tooMuch()) $event.preventDefault()">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}" :value="key">
            <input type="hidden" name="direction" :value="direction">
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
            <div class="flex items-center justify-between text-sm">
                <span class="text-slate-500">{{ __('wallet.current_balance') }}</span>
                <span class="font-numeric font-semibold" x-text="fmt(balance)"></span>
            </div>
            <label class="grid gap-1 text-sm">{{ __('wallet.amount') }}
                <input class="h-12 w-full rounded-md border border-[#E3E6EB] px-3 font-numeric text-lg" x-ref="amount" x-model="raw" inputmode="decimal" autocomplete="off" required>
            </label>
            <div class="flex items-center justify-between text-sm" x-show="amount()">
                <span class="text-slate-500">{{ __('wallet.after_balance') }}</span>
                <span class="font-numeric font-semibold" :class="tooMuch() ? 'text-rose-600' : ''" x-text="fmt(after())"></span>
            </div>
            <p class="text-sm text-rose-600" x-show="tooMuch()">{{ __('wallet.exceeds_balance') }}</p>
            <label class="grid gap-1 text-sm">{{ __('wallet.note') }}
                <input class="h-11 w-full rounded-md border border-[#E3E6EB] px-3" name="note" maxlength="2000">
            </label>
            <div class="grid grid-cols-2 gap-2 md:flex md:justify-end">
                <button class="inline-flex h-11 items-center justify-center rounded-lg border border-[#E3E6EB] bg-white px-4 text-sm" type="button" @click="open = false">{{ __('wallet.cancel') }}</button>
                <button class="inline-flex h-11 items-center justify-center rounded-lg bg-[#161A22] px-4 text-sm text-white disabled:opacity-40" type="submit" :disabled="! amount() || tooMuch()">{{ __('wallet.submit') }}</button>
            </div>
        </form>
    </div>
</div>
<script>
    window.balanceSheet = () => ({
        open: false, target: '', name: '', balance: 0, symbol: '', direction: 'add', raw: '', key: makeUuid(),
        openAdjust(user, direction) {
            Object.assign(this, { target: user.id, name: user.name, balance: Number(user.balance), symbol: user.symbol, direction, raw: '', key: makeUuid(), open: true });
            this.$nextTick(() => this.$refs.amount.focus());
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
        fmt(n) {
            const lang = document.documentElement.lang;
            const locale = lang === 'de' ? 'de-DE' : (lang === 'tr' ? 'tr-TR' : 'en-US');
            const whole = Math.round(n * 100) % 100 === 0;
            return new Intl.NumberFormat(locale, { minimumFractionDigits: whole ? 0 : 2, maximumFractionDigits: 2 }).format(n) + ' ' + this.symbol;
        },
    });
</script>
@endsection
