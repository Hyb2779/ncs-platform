@extends('layouts.panel')

@section('heading', __('panel.volkan_credit'))

@section('content')
@php
    $input = 'h-10 w-full rounded-md border border-slate-300 px-3 text-sm';
    $periods = [
        'today' => 'reports_period_today',
        'this_week' => 'reports_period_this_week',
        'this_month' => 'reports_period_this_month',
    ];
@endphp

<form class="mb-3 flex flex-wrap items-end gap-2" method="GET">
    <input type="hidden" name="period" value="{{ $period }}">
    <div class="flex flex-wrap gap-2">
        @foreach ($periods as $key => $label)
            <a class="inline-flex h-10 items-center rounded-full px-3 text-sm font-semibold {{ $period === $key ? 'bg-[#161A22] text-white' : 'border border-slate-300 bg-white text-slate-700' }}" href="{{ route('panel.volkan-credit.index', ['period' => $key]) }}">{{ __('panel.'.$label) }}</a>
        @endforeach
    </div>
    <label class="grid w-36 gap-1 text-xs text-slate-500">
        <span>{{ __('panel.reports_from') }}</span>
        <input class="{{ $input }}" type="date" name="from" value="{{ $from }}" onchange="this.form.period.value='custom'">
    </label>
    <label class="grid w-36 gap-1 text-xs text-slate-500">
        <span>{{ __('panel.reports_to') }}</span>
        <input class="{{ $input }}" type="date" name="to" value="{{ $to }}" onchange="this.form.period.value='custom'">
    </label>
    <button class="inline-flex h-10 items-center justify-center rounded-lg bg-[#161A22] px-4 text-sm text-white" type="submit">{{ __('panel.reports_apply') }}</button>
</form>

@if ($groups === [])
    <div class="rounded-lg border border-[#E3E6EB] bg-white p-6 text-center text-sm text-slate-500">{{ __('panel.volkan_credit_missing') }}</div>
@else
    <div class="grid gap-3">
        @foreach ($groups as $group)
            <section class="overflow-x-auto rounded-lg border border-[#E3E6EB] bg-white">
                @if (count($groups) > 1)
                    <p class="border-b border-[#E3E6EB] px-4 py-2 text-xs font-semibold tracking-wide text-slate-500">{{ $group['currency']->value }}</p>
                @endif
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-[#E3E6EB] text-slate-500">
                            <th class="px-4 py-2 text-start font-medium">{{ __('panel.fields.username') }}</th>
                            <th class="px-4 py-2 text-end font-medium">{{ __('panel.volkan_credit_distributed') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($group['rows'] as $row)
                            <tr class="border-b border-[#E3E6EB]">
                                <td class="px-4 py-2.5 align-top">
                                    <p class="font-medium">{{ $row['username'] }}</p>
                                    @foreach ($row['days'] as $day)
                                        <p class="mt-1 flex items-baseline justify-between gap-4 text-xs text-slate-500">
                                            <span>{{ \Illuminate\Support\Carbon::parse($day['date'])->format('d.m.Y') }}</span>
                                            <span class="font-numeric">{{ \App\Support\Money::format($day['distributed'], $group['currency']) }}</span>
                                        </p>
                                    @endforeach
                                </td>
                                <td class="px-4 py-2.5 text-end align-top font-numeric font-semibold">{{ \App\Support\Money::format($row['total'], $group['currency']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="font-semibold">
                            <td class="px-4 py-2.5 text-start">{{ __('panel.volkan_credit_total') }}</td>
                            <td class="px-4 py-2.5 text-end font-numeric">{{ \App\Support\Money::format($group['total'], $group['currency']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </section>
        @endforeach
    </div>
@endif
@endsection
