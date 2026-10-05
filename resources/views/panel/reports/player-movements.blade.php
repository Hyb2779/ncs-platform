@extends('layouts.panel')

@section('heading', __('panel.player_movements'))

@section('content')
@php
    $input = 'h-11 rounded-md border border-slate-300 px-3 text-sm';
@endphp

<form class="mb-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3" method="GET">
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
        <input class="{{ $input }}" type="date" name="from" value="{{ $from }}" onchange="this.form.period.value='custom'">
    </label>
    <label class="grid gap-1 text-sm">
        <span>{{ __('panel.reports_to') }}</span>
        <input class="{{ $input }}" type="date" name="to" value="{{ $to }}" onchange="this.form.period.value='custom'">
    </label>
    @include('panel.reports._member_field')
    <label class="grid gap-1 text-sm">
        <span>{{ __('panel.player_movements_type') }}</span>
        <select class="{{ $input }}" name="type">
            <option value="all" @selected($type === 'all')>{{ __('panel.player_movements_type_all') }}</option>
            @foreach (['bet', 'win', 'refund'] as $value)
                <option value="{{ $value }}" @selected($type === $value)>{{ __('wallet.types.'.$value) }}</option>
            @endforeach
        </select>
    </label>
    <label class="grid gap-1 text-sm">
        <span>{{ __('panel.player_movements_product') }}</span>
        <select class="{{ $input }}" name="product">
            <option value="all" @selected($product === 'all')>{{ __('panel.player_movements_product_all') }}</option>
            @foreach (['sport', 'casino', 'slot', 'mini'] as $value)
                <option value="{{ $value }}" @selected($product === $value)>{{ __('panel.player_movements_product_'.$value) }}</option>
            @endforeach
        </select>
    </label>
    <div class="grid items-end">
        <button class="inline-flex h-11 items-center justify-center rounded-lg bg-[#161A22] px-3 text-sm text-white" type="submit">{{ __('panel.filter') }}</button>
    </div>
</form>

<p class="mb-3 font-numeric text-sm text-slate-500">{{ __('panel.reports_range', ['from' => $from, 'to' => $to]) }}</p>

@php
    $tableRows = [];
    foreach ($rows as $row) {
        $detail = $row['detail']
            ? new \Illuminate\Support\HtmlString('<a class="underline" href="'.e($row['detail']).'">'.e(__('panel.player_movements_detail')).'</a>')
            : __('panel.empty_value');
        $tableRows[] = [
            'when' => $row['when'],
            'user' => $row['user'],
            'type' => $row['type'],
            'description' => $row['description'],
            'amount' => new \Illuminate\Support\HtmlString('<span class="'.e($row['tone']).'">'.e($row['amount']).'</span>'),
            'detail' => $detail,
        ];
    }
@endphp
<x-panel.table
    :empty="__('panel.player_movements_empty')"
    :columns="[
        ['key' => 'when', 'label' => __('wallet.when')],
        ['key' => 'user', 'label' => __('panel.member_movements_member')],
        ['key' => 'type', 'label' => __('wallet.type')],
        ['key' => 'description', 'label' => __('panel.player_movements_description')],
        ['key' => 'amount', 'label' => __('wallet.amount')],
        ['key' => 'detail', 'label' => __('panel.player_movements_detail')],
    ]"
    :rows="$tableRows"
/>
@include('panel.partials.pager', ['pager' => $rows])
@endsection
