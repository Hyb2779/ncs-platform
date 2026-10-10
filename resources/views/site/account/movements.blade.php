@extends('layouts.site')

@section('heading', __('account.menu'))

@section('mainClass', 'mx-auto w-full min-w-0 max-w-3xl px-4 py-4 md:max-w-5xl md:px-6')

@php
    $query = ['tab' => $tab, 'direction' => $direction, 'period' => $period, 'from' => $from, 'to' => $to];
@endphp

@section('content')
    <header class="acct-head">
        <a class="acct-back" href="{{ route('site.account') }}" aria-label="{{ __('account.back') }}">
            <span class="rtl:rotate-180" aria-hidden="true">&larr;</span>
        </a>
        <h1 class="acct-title">{{ __('account.menu') }}</h1>
    </header>

    <nav class="acct-tabs" aria-label="{{ __('account.menu') }}">
        <a href="{{ route('site.account.movements', array_merge($query, ['tab' => 'balance'])) }}" @if($tab === 'balance') aria-current="page" @endif>{{ __('account.balance_tab') }}</a>
        <a href="{{ route('site.account.movements', array_merge($query, ['tab' => 'games'])) }}" @if($tab === 'games') aria-current="page" @endif>{{ __('account.games_tab') }}</a>
    </nav>

    <form class="acct-filter" method="GET" action="{{ route('site.account.movements') }}">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <input type="hidden" name="period" value="custom">
        <div class="acct-dates">
            <label class="acct-field">
                <span>{{ __('account.from') }}</span>
                <input class="acct-control" type="date" name="from" value="{{ $from }}">
            </label>
            <label class="acct-field">
                <span>{{ __('account.to') }}</span>
                <input class="acct-control" type="date" name="to" value="{{ $to }}">
            </label>
        </div>
        @if ($tab === 'balance')
            <select class="acct-control" name="direction" aria-label="{{ __('account.type') }}">
                @foreach (['all' => 'account.direction_all', 'in' => 'account.direction_in', 'out' => 'account.direction_out'] as $key => $label)
                    <option value="{{ $key }}" @selected($direction === $key)>{{ __($label) }}</option>
                @endforeach
            </select>
        @endif
        <button class="acct-control acct-submit" type="submit">{{ __('panel.filter') }}</button>
    </form>

    @if ($summary)
        <section class="mt-4 grid grid-cols-3 gap-2" aria-label="{{ __('account.summary_net') }}">
            <div class="min-w-0 rounded-xl border border-[var(--site-line)] bg-[var(--site-panel)] p-3">
                <p class="truncate text-[11px] text-[var(--site-muted)]">{{ __('account.summary_bet') }}</p>
                <p class="mt-1 break-words font-numeric text-base font-bold md:text-lg">{{ $summary['bet'] }}</p>
            </div>
            <div class="min-w-0 rounded-xl border border-[var(--site-line)] bg-[var(--site-panel)] p-3">
                <p class="truncate text-[11px] text-[var(--site-muted)]">{{ __('account.summary_win') }}</p>
                <p class="mt-1 break-words font-numeric text-base font-bold md:text-lg">{{ $summary['win'] }}</p>
            </div>
            <div class="min-w-0 rounded-xl border border-[var(--site-line)] bg-[var(--site-panel)] p-3">
                <p class="truncate text-[11px] text-[var(--site-muted)]">{{ __('account.summary_net') }}</p>
                <p class="acct-{{ $summary['tone'] }} mt-1 break-words font-numeric text-base font-bold md:text-lg">{{ $summary['net'] }}</p>
            </div>
        </section>
    @endif

    @if ($rows === [])
        <p class="mt-4 rounded-xl border border-[var(--site-line)] bg-[var(--site-panel)] px-4 py-8 text-center text-sm text-[var(--site-muted)]">{{ __('account.empty') }}</p>
    @else
        <div class="mt-4 grid min-w-0 gap-3 md:hidden" data-movements-cards>
            @include('site.account._cards')
        </div>
        <div class="mt-4 hidden min-w-0 overflow-x-clip rounded-xl border border-[var(--site-line)] bg-[var(--site-panel)] md:block">
            <table class="w-full table-fixed text-sm">
                <thead class="text-start text-xs text-[var(--site-muted)]">
                    @if ($tab === 'games')
                        <tr>
                            <th class="px-3 py-2 text-start font-semibold">{{ __('account.game') }}</th>
                            <th class="px-3 py-2 text-start font-semibold">{{ __('account.category') }}</th>
                            <th class="px-3 py-2 text-end font-semibold">{{ __('account.bet') }}</th>
                            <th class="px-3 py-2 text-end font-semibold">{{ __('account.win') }}</th>
                            <th class="px-3 py-2 text-end font-semibold">{{ __('account.net') }}</th>
                        </tr>
                    @else
                        <tr>
                            <th class="w-[28%] px-3 py-2 text-start font-semibold">{{ __('account.when') }}</th>
                            <th class="px-3 py-2 text-start font-semibold">{{ __('account.type') }}</th>
                            <th class="w-[22%] px-3 py-2 text-end font-semibold">{{ __('account.amount') }}</th>
                            <th class="w-[24%] px-3 py-2 text-end font-semibold">{{ __('account.after') }}</th>
                        </tr>
                    @endif
                </thead>
                <tbody data-movements-rows>
                    @include('site.account._rows')
                </tbody>
            </table>
        </div>
        @if ($next)
            <a class="mt-4 inline-flex h-12 w-full items-center justify-center rounded-xl border border-[var(--site-line)] bg-[var(--site-panel)] text-sm font-semibold" data-load-more href="{{ $next }}">{{ __('account.more') }}</a>
        @endif
    @endif
@endsection
