@extends('layouts.panel')

@section('heading', __('site.panel_rounds'))

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <x-panel.stat :label="__('site.total_bet')" :value="$bet" />
        <x-panel.stat :label="__('site.total_win')" :value="$win" />
        <x-panel.stat :label="__('site.net')" :value="$net" />
    </div>
    <x-panel.filter-bar class="mb-4" />
    @php
        $tableRows = [];
        foreach ($rows as $row) {
            $tableRows[] = [
                'when' => $row->created_at->timezone(auth()->user()->timezone)->format('d.m.Y H:i'),
                'user' => $row->user->username,
                'game' => $row->game?->name,
                'amount' => $row->amount,
                'status' => $row->status,
                'before' => $row->balance_before,
                'after' => $row->balance_after,
            ];
        }
    @endphp
    <x-panel.table
        :columns="[
            ['key' => 'when', 'label' => __('wallet.when')],
            ['key' => 'user', 'label' => __('sport.panel.user')],
            ['key' => 'game', 'label' => __('site.panel_games')],
            ['key' => 'amount', 'label' => __('wallet.amount')],
            ['key' => 'status', 'label' => __('panel.fields.status'), 'priority' => 'detail'],
            ['key' => 'before', 'label' => __('wallet.balance_before'), 'priority' => 'detail'],
            ['key' => 'after', 'label' => __('wallet.balance_after'), 'priority' => 'detail'],
        ]"
        :rows="$tableRows"
    />
@endsection
