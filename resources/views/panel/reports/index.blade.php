@extends('layouts.panel')

@section('heading', __('panel.reports_title'))

@section('content')
@php
    use Illuminate\Support\HtmlString;
    $input = 'rounded-md border border-slate-300 px-3 py-2';
    $money = fn ($v) => number_format((float) $v, 2, ',', '.');
    $ggr = fn ($v) => new HtmlString('<span class="font-numeric '.((float) $v < 0 ? 'text-red-600' : '').'">'.e($money($v)).'</span>');
    $num = fn ($v) => new HtmlString('<span class="font-numeric">'.e($money($v)).'</span>');
    $base = ['period' => $period, 'from' => $from, 'to' => $to, 'user' => $focus->id === auth()->id() ? null : $focus->id];
    $link = fn (array $extra) => route('panel.reports.index', array_filter(array_merge($base, $extra), fn ($v) => $v !== null));
    $role = fn ($u) => __('panel.reports_role_'.($u->role instanceof \BackedEnum ? $u->role->value : $u->role));
@endphp

<nav class="mb-3 flex gap-2 overflow-x-auto">
    @foreach (['summary' => __('panel.reports_tab_summary'), 'providers' => __('panel.reports_tab_providers')] as $key => $label)
        <a class="inline-flex h-10 shrink-0 items-center rounded-lg border px-4 text-sm font-medium {{ $tab === $key ? 'border-[#161A22] bg-[#161A22] text-white' : 'border-[#E3E6EB] bg-white' }}" href="{{ $link(['tab' => $key === 'summary' ? null : $key]) }}">{{ $label }}</a>
    @endforeach
</nav>

<form class="mb-3 grid gap-2 sm:grid-cols-4" method="GET">
    @if ($tab === 'providers')<input type="hidden" name="tab" value="providers">@endif
    @if ($base['user'])<input type="hidden" name="user" value="{{ $base['user'] }}">@endif
    <label class="grid gap-1 text-sm">
        <span>{{ __('panel.reports_period') }}</span>
        <select class="{{ $input }}" name="period">
            @foreach ($periods as $p)
                <option value="{{ $p }}" @selected($period === $p)>{{ __('panel.reports_period_'.$p) }}</option>
            @endforeach
        </select>
    </label>
    <label class="grid gap-1 text-sm">
        <span>{{ __('panel.reports_from') }}</span>
        <input class="{{ $input }}" type="date" name="from" value="{{ $from }}">
    </label>
    <label class="grid gap-1 text-sm">
        <span>{{ __('panel.reports_to') }}</span>
        <input class="{{ $input }}" type="date" name="to" value="{{ $to }}">
    </label>
    <div class="grid items-end">
        <button class="inline-flex h-11 items-center justify-center rounded-lg bg-[#161A22] px-3 text-sm text-white" type="submit">{{ __('panel.reports_apply') }}</button>
    </div>
</form>
<p class="mb-3 text-xs text-slate-500">{{ __('panel.reports_custom_hint') }}</p>

<div class="mb-3 flex flex-wrap items-center gap-1 text-sm">
    @foreach ($trail as $i => $u)
        @if ($i > 0)<span class="text-slate-400">/</span>@endif
        @if ($u->id === $focus->id)
            <span class="font-semibold">{{ $u->username }}</span>
        @else
            <a class="font-medium underline" href="{{ $link(['user' => $i === 0 ? null : $u->id]) }}">{{ $u->username }}</a>
        @endif
    @endforeach
    <span class="ms-2 text-slate-500 font-numeric">{{ __('panel.reports_range', ['from' => $from, 'to' => $to]) }}</span>
</div>

@if ($tab === 'summary')
    @php
        $productRows = [];
        foreach ($totals as $currency => $byProduct) {
            foreach (['sport', 'slot', 'live_casino', 'all'] as $product) {
                $m = $byProduct[$product] ?? null;
                if ($m === null && $product !== 'all') { continue; }
                $productRows[] = [
                    'product' => new HtmlString($product === 'all' ? '<strong>'.e(__('panel.reports_product_all')).'</strong>' : e(__('panel.reports_product_'.$product))),
                    'currency' => $currency,
                    'turnover' => $num($m['turnover'] ?? 0),
                    'payout' => $num($m['payout'] ?? 0),
                    'ggr' => $ggr($m['ggr'] ?? 0),
                    'bets' => $m['bet_count'] ?? 0,
                    'players' => $m['players'] ?? 0,
                ];
            }
        }
        $childRows = [];
        foreach ($children as $child) {
            $u = $child['user'];
            $name = $u->role->value === 'uye'
                ? e($u->username)
                : '<a class="font-medium underline" href="'.e($link(['user' => $u->id])).'">'.e($u->username).'</a>';
            $currencies = $child['stats'] ?: ['' => []];
            foreach ($currencies as $currency => $s) {
                $childRows[] = [
                    'account' => new HtmlString($name),
                    'role' => $role($u),
                    'currency' => $currency !== '' ? $currency : '—',
                    'turnover' => $num($s['all']['turnover'] ?? 0),
                    'payout' => $num($s['all']['payout'] ?? 0),
                    'ggr' => $ggr($s['all']['ggr'] ?? 0),
                    'sport' => $ggr($s['sport']['ggr'] ?? 0),
                    'casino' => $ggr($s['casino']['ggr'] ?? 0),
                    'players' => $s['all']['players'] ?? 0,
                ];
            }
        }
    @endphp
    <h2 class="mb-2 text-sm font-semibold">{{ __('panel.reports_summary_heading') }}</h2>
    <x-panel.table :empty="__('panel.reports_empty')" :rows="$productRows" :columns="[
        ['key' => 'product', 'label' => __('panel.reports_product')],
        ['key' => 'currency', 'label' => __('panel.reports_currency')],
        ['key' => 'turnover', 'label' => __('panel.reports_turnover')],
        ['key' => 'payout', 'label' => __('panel.reports_payout')],
        ['key' => 'ggr', 'label' => __('panel.reports_ggr')],
        ['key' => 'bets', 'label' => __('panel.reports_bets'), 'priority' => 'detail'],
        ['key' => 'players', 'label' => __('panel.reports_players'), 'priority' => 'detail'],
    ]" />
    <p class="mt-1 mb-4 text-xs text-slate-500">{{ __('panel.reports_mini_note') }}</p>

    <h2 class="mb-2 text-sm font-semibold">{{ __('panel.reports_breakdown', ['name' => $focus->username]) }}</h2>
    <x-panel.table :empty="__('panel.reports_empty')" :rows="$childRows" :columns="[
        ['key' => 'account', 'label' => __('panel.reports_account')],
        ['key' => 'role', 'label' => __('panel.reports_role')],
        ['key' => 'currency', 'label' => __('panel.reports_currency')],
        ['key' => 'turnover', 'label' => __('panel.reports_turnover')],
        ['key' => 'payout', 'label' => __('panel.reports_payout')],
        ['key' => 'ggr', 'label' => __('panel.reports_ggr')],
        ['key' => 'sport', 'label' => __('panel.reports_sport_ggr'), 'priority' => 'detail'],
        ['key' => 'casino', 'label' => __('panel.reports_casino_ggr'), 'priority' => 'detail'],
        ['key' => 'players', 'label' => __('panel.reports_players'), 'priority' => 'detail'],
    ]" />
@else
    @php
        $providerRows = array_map(fn ($r) => [
            'provider' => $r['provider_name'],
            'vendor' => $r['vendor'] ? (\App\Support\Vendors::name($r['vendor']) ?? $r['vendor']) : '—',
            'category' => __('panel.reports_category_'.$r['category']),
            'currency' => $r['currency'],
            'turnover' => $num($r['turnover']),
            'payout' => $num($r['payout']),
            'ggr' => $ggr($r['ggr']),
            'bets' => $r['bet_count'],
            'players' => $r['players'],
        ], $providers);
    @endphp
    <x-panel.table :empty="__('panel.reports_empty')" :rows="$providerRows" :columns="[
        ['key' => 'provider', 'label' => __('panel.reports_provider')],
        ['key' => 'vendor', 'label' => __('panel.reports_vendor')],
        ['key' => 'category', 'label' => __('panel.reports_category')],
        ['key' => 'currency', 'label' => __('panel.reports_currency')],
        ['key' => 'turnover', 'label' => __('panel.reports_turnover')],
        ['key' => 'payout', 'label' => __('panel.reports_payout')],
        ['key' => 'ggr', 'label' => __('panel.reports_ggr')],
        ['key' => 'bets', 'label' => __('panel.reports_bets'), 'priority' => 'detail'],
        ['key' => 'players', 'label' => __('panel.reports_players'), 'priority' => 'detail'],
    ]" />
@endif
@endsection
