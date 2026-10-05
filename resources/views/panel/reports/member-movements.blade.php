@extends('layouts.panel')

@section('heading', __('panel.member_movements'))

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
        <span>{{ __('panel.member_movements_direction') }}</span>
        <select class="{{ $input }}" name="direction">
            <option value="all" @selected($direction === 'all')>{{ __('panel.member_movements_dir_all') }}</option>
            <option value="load" @selected($direction === 'load')>{{ __('panel.member_movements_load') }}</option>
            <option value="withdraw" @selected($direction === 'withdraw')>{{ __('panel.member_movements_withdraw') }}</option>
        </select>
    </label>
    <div class="grid items-end">
        <button class="inline-flex h-11 items-center justify-center rounded-lg bg-[#161A22] px-3 text-sm text-white" type="submit">{{ __('panel.filter') }}</button>
    </div>
</form>

<p class="mb-3 font-numeric text-sm text-slate-500">{{ __('panel.reports_range', ['from' => $from, 'to' => $to]) }}</p>

<div class="mb-4 grid gap-4">
    @foreach ($totals as $total)
        <div>
            @if (count($totals) > 1)
                <p class="mb-2 text-xs font-semibold text-slate-500">{{ $total['code'] }}</p>
            @endif
            <div class="grid gap-3 sm:grid-cols-3">
                <article class="rounded-lg bg-white p-4">
                    <p class="text-xs text-slate-500">{{ __('panel.member_movements_loaded') }}</p>
                    <p class="mt-1 font-numeric text-2xl text-emerald-700">{{ $total['loaded'] }}</p>
                </article>
                <article class="rounded-lg bg-white p-4">
                    <p class="text-xs text-slate-500">{{ __('panel.member_movements_withdrawn') }}</p>
                    <p class="mt-1 font-numeric text-2xl text-red-700">{{ $total['withdrawn'] }}</p>
                </article>
                <article class="rounded-lg bg-white p-4">
                    <p class="text-xs text-slate-500">{{ __('panel.member_movements_net') }}</p>
                    <p class="mt-1 font-numeric text-2xl {{ $total['net_tone'] }}">{{ $total['net'] }}</p>
                </article>
            </div>
        </div>
    @endforeach
</div>

@php
    $tableRows = [];
    foreach ($rows as $row) {
        $tableRows[] = [
            'user' => $row['user'],
            'amount' => new \Illuminate\Support\HtmlString('<span class="'.e($row['tone']).'">'.e($row['amount']).'</span>'),
            'type' => $row['type'],
            'after' => $row['after'],
            'when' => $row['when'],
            'by' => $row['by'],
        ];
    }
@endphp
<x-panel.table
    :empty="__('panel.member_movements_empty')"
    :columns="[
        ['key' => 'user', 'label' => __('panel.member_movements_member')],
        ['key' => 'amount', 'label' => __('wallet.amount')],
        ['key' => 'type', 'label' => __('wallet.type')],
        ['key' => 'when', 'label' => __('wallet.when')],
        ['key' => 'after', 'label' => __('panel.member_movements_after')],
        ['key' => 'by', 'label' => __('panel.member_movements_by')],
    ]"
    :rows="$tableRows"
/>
@include('panel.partials.pager', ['pager' => $rows])
@endsection
