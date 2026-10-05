@extends('layouts.panel')

@section('heading', __('panel.reports_title'))

@section('content')
@php
    $input = 'h-11 rounded-md border border-slate-300 px-3';
    $mm = fn ($v) => \App\Support\Money::format((string) $v, $currency);
    $base = ['period' => $period, 'from' => $from, 'to' => $to, 'user' => $focus->id === auth()->id() ? null : $focus->id];
    $link = fn (array $extra) => route('panel.reports.index', array_filter(array_merge($base, $extra), fn ($v) => $v !== null));
    $tone = fn ($v) => (float) $v < 0 ? 'text-rose-600' : ((float) $v > 0 ? 'text-emerald-700' : '');
    $role = fn ($u) => __('panel.reports_role_'.($u->role instanceof \BackedEnum ? $u->role->value : $u->role));
    $cols = [
        ['given', __('panel.rep_given'), null],
        ['withdrawn', __('panel.rep_withdrawn'), null],
        ['staked', __('panel.rep_staked'), 'staked_n'],
        ['won', __('panel.rep_won'), 'won_n'],
        ['pending', __('panel.rep_pending'), 'pending_n'],
    ];
@endphp

<form class="mb-3 flex flex-wrap items-end gap-2" method="GET">
    @if ($base['user'])<input type="hidden" name="user" value="{{ $base['user'] }}">@endif
    <input type="hidden" name="period" value="{{ $period }}">
    <a class="inline-flex h-11 items-center rounded-full px-4 text-sm font-semibold {{ $period === 'this_week' ? 'bg-[#161A22] text-white' : 'border border-slate-300 bg-white text-slate-700' }}" href="{{ route('panel.reports.index', array_filter(['period' => 'this_week', 'user' => $base['user']])) }}">{{ __('panel.reports_this_week') }}</a>
    <label class="grid gap-1 text-sm">
        <span>{{ __('panel.reports_from') }}</span>
        <input class="{{ $input }}" type="date" name="from" value="{{ $from }}" onchange="this.form.period.value='custom'">
    </label>
    <label class="grid gap-1 text-sm">
        <span>{{ __('panel.reports_to') }}</span>
        <input class="{{ $input }}" type="date" name="to" value="{{ $to }}" onchange="this.form.period.value='custom'">
    </label>
    <button class="inline-flex h-11 items-center justify-center rounded-lg bg-[#161A22] px-3 text-sm text-white" type="submit">{{ __('panel.reports_apply') }}</button>
</form>

<div class="mb-3 flex flex-wrap items-center gap-1 text-sm">
    @foreach ($trail as $i => $u)
        @if ($i > 0)<span class="text-slate-400">/</span>@endif
        @if ($u->id === $focus->id)
            <span class="font-semibold">{{ $u->username }}</span>
        @else
            <a class="font-medium underline" href="{{ $link(['user' => $i === 0 ? null : $u->id]) }}">{{ $u->username }}</a>
        @endif
    @endforeach
    <span class="ms-2 font-numeric text-slate-500">{{ __('panel.reports_range', ['from' => $from, 'to' => $to]) }}</span>
</div>

@if ($rows === [])
    <div class="rounded-lg border border-[#E3E6EB] bg-white p-6 text-center text-sm text-slate-500">{{ __('panel.reports_empty') }}</div>
@else
    <div class="hidden overflow-x-auto rounded-lg border border-[#E3E6EB] bg-white md:block">
        <table class="w-full text-sm">
            <thead class="bg-[#F3F4F6] text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-3 py-2 text-start">{{ __('panel.rep_account') }}</th>
                    @foreach ($cols as [$k, $label, $n])
                        <th class="whitespace-nowrap px-3 py-2 text-end">{{ $label }}</th>
                    @endforeach
                    <th class="px-3 py-2 text-end">{{ __('panel.rep_general') }}</th>
                    @if ($showCommission)<th class="px-3 py-2 text-end">{{ __('panel.rep_commission') }}</th>@endif
                    <th class="px-3 py-2 text-end">{{ __('panel.rep_net') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $r)
                    <tr class="border-t border-[#E3E6EB]">
                        <td class="whitespace-nowrap px-3 py-2">
                            <span class="font-semibold">{{ $r['user']->username }}</span>
                            @if ($r['user']->role->value !== 'uye')
                                <a class="ms-1 font-semibold text-sky-700 underline" href="{{ $link(['user' => $r['user']->id]) }}">[+]</a>
                            @endif
                            <p class="text-xs text-slate-500">{{ $role($r['user']) }}</p>
                        </td>
                        @foreach ($cols as [$k, $label, $n])
                            <td class="whitespace-nowrap px-3 py-2 text-end font-numeric">
                                {{ $mm($r[$k]) }}@if ($n)<span class="text-xs text-slate-500"> ({{ $r[$n] }})</span>@endif
                            </td>
                        @endforeach
                        <td class="whitespace-nowrap px-3 py-2 text-end font-numeric font-semibold {{ $tone($r['general']) }}">{{ $mm($r['general']) }}</td>
                        @if ($showCommission)
                            <td class="rep-commission whitespace-nowrap px-3 py-2 text-end font-numeric">
                                @if ($r['show_commission'])
                                    @if ($r['rate'])<span class="me-1 rounded bg-[#161A22] px-1.5 py-0.5 text-xs text-white">%{{ $r['rate'] }}</span>{{ $mm($r['commission']) }}@else - @endif
                                @endif
                            </td>
                        @endif
                        <td class="whitespace-nowrap px-3 py-2 text-end font-numeric font-bold {{ $tone($r['net']) }}">{{ $mm($r['net']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-[#F3F4F6] font-semibold">
                <tr class="border-t border-[#E3E6EB]">
                    <td class="px-3 py-2">{{ __('panel.rep_totals') }}</td>
                    @foreach ($cols as [$k, $label, $n])
                        <td class="whitespace-nowrap px-3 py-2 text-end font-numeric">{{ $mm($totals[$k]) }}@if ($n)<span class="text-xs text-slate-500"> ({{ $totals[$n] }})</span>@endif</td>
                    @endforeach
                    <td class="whitespace-nowrap px-3 py-2 text-end font-numeric {{ $tone($totals['general']) }}">{{ $mm($totals['general']) }}</td>
                    @if ($showCommission)<td class="rep-commission whitespace-nowrap px-3 py-2 text-end font-numeric">{{ $mm($totals['commission']) }}</td>@endif
                    <td class="whitespace-nowrap px-3 py-2 text-end font-numeric {{ $tone($totals['net']) }}">{{ $mm($totals['net']) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="grid gap-3 md:hidden">
        @foreach (array_merge($rows, [['user' => null] + $totals + ['rate' => null]]) as $r)
            @php $isTotal = $r['user'] === null; @endphp
            <div class="rounded-lg border bg-white p-3 {{ $isTotal ? 'border-[#161A22]' : 'border-[#E3E6EB]' }}">
                <div class="mb-2 flex items-center justify-between gap-2">
                    <p class="font-semibold">
                        @if ($isTotal) {{ __('panel.rep_totals') }} @else {{ $r['user']->username }} <span class="text-xs font-normal text-slate-500">{{ $role($r['user']) }}</span> @endif
                    </p>
                    @if (! $isTotal && $r['user']->role->value !== 'uye')
                        <a class="text-sm font-semibold text-sky-700 underline" href="{{ $link(['user' => $r['user']->id]) }}">[+]</a>
                    @endif
                </div>
                <div class="grid grid-cols-2 gap-x-3 gap-y-1.5 text-sm">
                    @foreach ($cols as [$k, $label, $n])
                        <div>
                            <p class="text-xs text-slate-500">{{ $label }}</p>
                            <p class="font-numeric">{{ $mm($r[$k]) }}@if ($n)<span class="text-xs text-slate-500"> ({{ $r[$n] }})</span>@endif</p>
                        </div>
                    @endforeach
                    <div>
                        <p class="text-xs text-slate-500">{{ __('panel.rep_general') }}</p>
                        <p class="font-numeric font-semibold {{ $tone($r['general']) }}">{{ $mm($r['general']) }}</p>
                    </div>
                    @if ($isTotal ? $showCommission : $r['show_commission'])
                        <div class="rep-commission">
                            <p class="text-xs text-slate-500">{{ __('panel.rep_commission') }}@if ($r['rate']) %{{ $r['rate'] }}@endif</p>
                            <p class="font-numeric">{{ $mm($r['commission']) }}</p>
                        </div>
                    @endif
                    <div>
                        <p class="text-xs text-slate-500">{{ __('panel.rep_net') }}</p>
                        <p class="font-numeric font-bold {{ $tone($r['net']) }}">{{ $mm($r['net']) }}</p>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endif
<p class="mt-3 text-xs text-slate-500">{{ __('panel.rep_hint') }}</p>
@endsection
