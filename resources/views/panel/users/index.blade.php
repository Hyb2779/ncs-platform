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
            'canAdjust' => $viewer->role === \App\Enums\UserRole::Owner || $user->parent_id === $viewer->id || ($isSuper && $isMember),
            'editUrl' => route('panel.users.edit', $user),
            'statusUrl' => route('panel.users.status', $user),
            'passwordUrl' => route('panel.users.password', $user),
            'couponsUrl' => $isMember ? route('panel.coupons.index', ['user' => $user->username]) : null,
            'childrenUrl' => $isMember || $user->role === \App\Enums\UserRole::Bayi ? null : route('panel.users.index', ['tab' => 'dealers', 'parent' => $user->id]),
            'membersUrl' => $isMember ? null : route('panel.users.index', ['tab' => 'members', 'parent' => $user->id]),
            'movementsUrl' => route('panel.transactions', ['user' => $user->id]),
        ];
        $payloads[$user->id] = $payload;
        $json = e(json_encode($payload));
        $actions = '<a href="'.e($payload['editUrl']).'">'.e(__('panel.edit')).'</a>';
        $actions .= '<a class="ms-3" href="'.e($payload['movementsUrl']).'">'.e(__('panel.users_movements')).'</a>';
        if ($payload['canAdjust']) {
            $actions .= '<button class="ms-3 font-semibold text-emerald-700" type="button" @click="openAdjust('.$json.', \'add\')">'.e(__('wallet.add')).'</button>';
            $actions .= '<button class="ms-3 font-semibold text-rose-700" type="button" @click="openAdjust('.$json.', \'remove\')">'.e(__('wallet.remove')).'</button>';
        }
        $actions .= '<button class="ms-3 font-semibold" type="button" @click="openActions('.$json.')">'.e(__('panel.users_ui.more')).'</button>';
        $rows[] = [
            'username' => $isMember
                ? $user->username
                : new \Illuminate\Support\HtmlString('<a href="'.e($payload['childrenUrl'] ?? $payload['membersUrl']).'">'.e($user->username).'</a>'),
            'dealer' => $parentNames[$user->parent_id] ?? __('panel.empty_value'),
            'members' => (string) ($memberCounts[$user->id] ?? 0),
            'turnover' => $turnovers[$user->id] ?? __('panel.empty_value'),
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
        $tab === 'members' && ! $isBayi ? ['key' => 'dealer', 'label' => __('panel.users_col_dealer')] : null,
        $tab === 'dealers' ? ['key' => 'role', 'label' => __('panel.fields.role')] : null,
        ['key' => 'status', 'label' => __('panel.fields.status')],
        ['key' => 'balance', 'label' => __('wallet.balance')],
        $tab === 'dealers' ? ['key' => 'members', 'label' => __('panel.users_col_members')] : null,
        $tab === 'dealers' ? ['key' => 'turnover', 'label' => __('panel.users_col_turnover')] : null,
        ['key' => 'commission', 'label' => __('panel.fields.commission_rate'), 'priority' => 'detail'],
        ['key' => 'login', 'label' => __('panel.fields.last_login')],
        ['key' => 'actions', 'label' => __('panel.fields.actions')],
    ]));
    $balances = collect($summary['balances'])
        ->map(fn ($total, $currency) => \App\Support\Money::format((string) $total, \App\Enums\Currency::from($currency)))
        ->implode(' · ');
@endphp
<div x-data="balanceSheet(@js(['own' => (string) ($viewer->wallets()->where('currency', $viewer->currency)->value('balance') ?? '0'), 'ownBy' => $viewer->wallets()->get()->mapWithKeys(fn ($w) => [$w->currency->value => (string) $w->balance])->all(), 'symbols' => $symbols ?? [], 'unlimited' => $viewer->isRootOwner()]))">
    @if ($showTabs)
        <nav class="mb-3 flex gap-2 overflow-x-auto">
            @foreach (['members' => __('panel.users_tab_members'), 'dealers' => __('panel.users_tab_dealers')] as $key => $label)
                <a class="inline-flex h-10 shrink-0 items-center rounded-lg border px-4 text-sm font-medium {{ $tab === $key ? 'border-[#161A22] bg-[#161A22] text-white' : 'border-[#E3E6EB] bg-white' }}" href="{{ route('panel.users.index', ['tab' => $key]) }}">{{ $label }}</a>
            @endforeach
        </nav>
    @endif
    @if (count($breadcrumb) > 1)
        <nav class="mb-3 flex flex-wrap gap-2 text-sm text-start">
            @foreach ($breadcrumb as $crumb)
                <a class="text-slate-600" href="{{ route('panel.users.index', ['tab' => $tab] + ($crumb->id === auth()->id() ? [] : ['parent' => $crumb->id])) }}">{{ $crumb->username }}</a>
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
            <a class="flex h-12 w-full items-center rounded-lg px-3 text-sm font-medium hover:bg-slate-50" :href="current.membersUrl" x-show="current.membersUrl">{{ __('panel.users_players') }}</a>
            <a class="flex h-12 w-full items-center rounded-lg px-3 text-sm font-medium hover:bg-slate-50" :href="current.movementsUrl" x-show="current.movementsUrl">{{ __('panel.users_movements') }}</a>
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

    @include('panel.users._balance_modal')
</div>
@include('panel.users._balance_script')
@endsection
