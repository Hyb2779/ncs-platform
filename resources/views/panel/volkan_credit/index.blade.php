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

@if ($tables === [])
    <div class="rounded-lg border border-[#E3E6EB] bg-white p-6 text-center text-sm text-slate-500">{{ __('panel.volkan_credit_missing') }}</div>
@else
    <div class="grid gap-4">
        @foreach ($tables as $table)
            <section class="overflow-x-auto rounded-lg border border-[#E3E6EB] bg-white">
                <p class="border-b border-[#E3E6EB] px-4 py-3 text-xs font-semibold tracking-wide text-slate-500">{{ $table['currency']->value }}</p>
                <table class="w-full min-w-[36rem] text-sm">
                    <thead>
                        <tr class="border-b border-[#E3E6EB] text-slate-500">
                            <th class="px-4 py-3 text-start font-medium">{{ __('panel.volkan_credit_date') }}</th>
                            <th class="px-4 py-3 text-end font-medium">{{ __('panel.volkan_credit_produced') }}</th>
                            <th class="px-4 py-3 text-end font-medium">{{ __('panel.volkan_credit_distributed') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($table['days'] as $day)
                            <tr class="border-b border-[#E3E6EB]">
                                <td class="px-4 py-3 text-start">{{ \Illuminate\Support\Carbon::parse($day['date'])->format('d.m.Y') }}</td>
                                <td class="px-4 py-3 text-end font-numeric">{{ \App\Support\Money::format($day['produced'], $table['currency']) }}</td>
                                <td class="px-4 py-3 text-end font-numeric">{{ \App\Support\Money::format($day['distributed'], $table['currency']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="font-semibold">
                            <td class="px-4 py-3 text-start">{{ __('panel.volkan_credit_total') }}</td>
                            <td class="px-4 py-3 text-end font-numeric">{{ \App\Support\Money::format($table['produced'], $table['currency']) }}</td>
                            <td class="px-4 py-3 text-end font-numeric">{{ \App\Support\Money::format($table['distributed'], $table['currency']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </section>
        @endforeach
    </div>
@endif
@endsection
