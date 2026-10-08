@extends('layouts.panel')

@section('heading', __('panel.volkan_credit'))

@section('content')
@php
    $input = 'h-11 w-full rounded-md border border-slate-300 px-3 text-sm';
    $periods = [
        'today' => 'reports_period_today',
        'this_week' => 'reports_period_this_week',
        'this_month' => 'reports_period_this_month',
    ];
@endphp

<form class="mb-4 flex flex-wrap items-end gap-2" method="GET">
    <input type="hidden" name="period" value="{{ $period }}">
    <div class="flex flex-wrap gap-2">
        @foreach ($periods as $key => $label)
            <a class="inline-flex h-11 items-center rounded-full px-4 text-sm font-semibold {{ $period === $key ? 'bg-[#161A22] text-white' : 'border border-slate-300 bg-white text-slate-700' }}" href="{{ route('panel.volkan-credit.index', ['period' => $key]) }}">{{ __('panel.'.$label) }}</a>
        @endforeach
    </div>
    <label class="grid min-w-[10rem] flex-1 gap-1 text-sm">
        <span>{{ __('panel.reports_from') }}</span>
        <input class="{{ $input }}" type="date" name="from" value="{{ $from }}" onchange="this.form.period.value='custom'">
    </label>
    <label class="grid min-w-[10rem] flex-1 gap-1 text-sm">
        <span>{{ __('panel.reports_to') }}</span>
        <input class="{{ $input }}" type="date" name="to" value="{{ $to }}" onchange="this.form.period.value='custom'">
    </label>
    <button class="inline-flex h-11 items-center justify-center rounded-lg bg-[#161A22] px-4 text-sm text-white" type="submit">{{ __('panel.reports_apply') }}</button>
</form>

<p class="mb-4 font-numeric text-sm text-slate-500">{{ __('panel.reports_range', ['from' => $from, 'to' => $to]) }}</p>

@if ($rows === [])
    <div class="rounded-lg border border-[#E3E6EB] bg-white p-6 text-center text-sm text-slate-500">{{ __('panel.volkan_credit_missing') }}</div>
@else
    <div class="grid gap-3 sm:grid-cols-2">
        @foreach ($rows as $row)
            <section class="rounded-lg border border-[#E3E6EB] bg-white p-4">
                <p class="text-xs font-semibold tracking-wide text-slate-500">{{ $row['currency']->value }}</p>
                <dl class="mt-3 grid gap-4">
                    <div>
                        <dt class="text-sm text-slate-500">{{ __('panel.volkan_credit_produced') }}</dt>
                        <dd class="mt-1 font-numeric text-2xl font-semibold">{{ \App\Support\Money::format($row['produced'], $row['currency']) }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-slate-500">{{ __('panel.volkan_credit_distributed') }}</dt>
                        <dd class="mt-1 font-numeric text-2xl font-semibold">{{ \App\Support\Money::format($row['distributed'], $row['currency']) }}</dd>
                    </div>
                </dl>
            </section>
        @endforeach
    </div>
@endif
@endsection
