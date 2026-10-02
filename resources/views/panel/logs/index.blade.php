@extends('layouts.panel')

@section('heading', $heading)

@section('content')
@php
    $columns = $kind === 'logins'
        ? [
            ['key' => 'when', 'label' => __('panel.logs_when')],
            ['key' => 'actor', 'label' => __('panel.logs_actor')],
            ['key' => 'action', 'label' => __('panel.logs_action')],
            ['key' => 'ip', 'label' => __('panel.logs_ip')],
            ['key' => 'device', 'label' => __('panel.logs_device')],
            ['key' => 'detail', 'label' => __('panel.logs_detail'), 'priority' => 'detail'],
        ]
        : [
            ['key' => 'when', 'label' => __('panel.logs_when')],
            ['key' => 'actor', 'label' => __('panel.logs_actor')],
            ['key' => 'action', 'label' => __('panel.logs_action')],
            ['key' => 'target', 'label' => __('panel.logs_target')],
            ['key' => 'detail', 'label' => __('panel.logs_detail')],
            ['key' => 'ip', 'label' => __('panel.logs_ip'), 'priority' => 'detail'],
        ];
    $input = 'rounded-md border border-slate-300 px-3 py-2';
@endphp
<nav class="mb-3 flex gap-2 overflow-x-auto">
    @foreach (['actions' => ['panel.logs.index', __('panel.logs_actions')], 'logins' => ['panel.logs.logins', __('panel.logs_logins')]] as $key => [$route, $label])
        <a class="inline-flex h-10 shrink-0 items-center rounded-lg border px-4 text-sm font-medium {{ $kind === $key ? 'border-[#161A22] bg-[#161A22] text-white' : 'border-[#E3E6EB] bg-white' }}" href="{{ route($route) }}">{{ $label }}</a>
    @endforeach
</nav>
<form class="mb-3 grid gap-2 sm:grid-cols-2" method="GET">
    <label class="grid gap-1 text-sm">
        <span>{{ __('panel.logs_search') }}</span>
        <input class="{{ $input }}" name="q" value="{{ request('q') }}">
    </label>
    @if ($kind === 'actions')
        <label class="grid gap-1 text-sm">
            <span>{{ __('panel.logs_action') }}</span>
            <select class="{{ $input }}" name="action">
                <option value="">{{ __('panel.logs_all_actions') }}</option>
                @foreach ($actions as $value => $label)
                    <option value="{{ $value }}" @selected(request('action') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
    @endif
    <label class="grid gap-1 text-sm">
        <span>{{ __('panel.logs_from') }}</span>
        <input class="{{ $input }}" type="date" name="from" value="{{ request('from') }}">
    </label>
    <label class="grid gap-1 text-sm">
        <span>{{ __('panel.logs_to') }}</span>
        <input class="{{ $input }}" type="date" name="to" value="{{ request('to') }}">
    </label>
    <div class="grid items-end">
        <button class="inline-flex h-11 items-center justify-center rounded-lg bg-[#161A22] px-3 text-sm text-white" type="submit">{{ __('panel.logs_filter') }}</button>
    </div>
</form>
<x-panel.table :empty="__('panel.logs_empty')" :columns="$columns" :rows="$rows" />
@if ($logs->hasPages())
    <div class="mt-3 flex items-center justify-between text-sm">
        @if ($logs->onFirstPage())
            <span class="text-slate-400">{{ __('panel.logs_prev') }}</span>
        @else
            <a class="font-medium" href="{{ $logs->previousPageUrl() }}">{{ __('panel.logs_prev') }}</a>
        @endif
        <span class="font-numeric text-slate-500">{{ $logs->currentPage() }} / {{ $logs->lastPage() }}</span>
        @if ($logs->hasMorePages())
            <a class="font-medium" href="{{ $logs->nextPageUrl() }}">{{ __('panel.logs_next') }}</a>
        @else
            <span class="text-slate-400">{{ __('panel.logs_next') }}</span>
        @endif
    </div>
@endif
@endsection
