@extends('layouts.panel')

@section('heading', __('sport.panel.status'))

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <x-panel.stat :label="__('sport.panel.requests')" :value="$used" />
        <x-panel.stat :label="__('sport.panel.remaining')" :value="$remaining ?? __('panel.empty_value')" />
        <x-panel.stat :label="__('sport.panel.settle_check')" :value="$settleCheck?->last_synced_at?->timezone(auth()->user()->timezone)->format('d.m.Y H:i') ?? __('panel.empty_value')" />
        <x-panel.stat :label="__('sport.panel.pending_settlements')" :value="$pendingSettlements" />
        <x-panel.stat :label="__('sport.panel.live_sync')" :value="$liveRequests" />
    </div>
    @php
        $stateRows = [];
        foreach ($states as $state) {
            $stateRows[] = [
                'code' => $state->code,
                'when' => $state->last_synced_at?->timezone(auth()->user()->timezone)->format('d.m.Y H:i') ?? __('panel.empty_value'),
                'error' => $state->last_error ?: __('panel.empty_value'),
            ];
        }
        $staleRows = [];
        foreach ($stale as $warning) {
            $staleRows[] = [
                'match' => new \Illuminate\Support\HtmlString('<a class="underline" href="'.e(route('panel.sport.fixtures.show', $warning->fixture)).'">'.e(sport_name($warning->fixture?->home).' – '.sport_name($warning->fixture?->away)).'</a>'),
            ];
        }
        $voidRows = [];
        foreach ($approaching as $selection) {
            $voidRows[] = [
                'match' => new \Illuminate\Support\HtmlString('<a class="underline" href="'.e(route('panel.sport.fixtures.show', $selection->fixture)).'">'.e(sport_name($selection->fixture->home).' – '.sport_name($selection->fixture->away)).'</a>'),
            ];
        }
        $debtRows = [];
        foreach ($overdrafts as $warning) {
            $debtRows[] = [
                'user' => $warning->user?->username,
                'amount' => \App\Support\Money::format((string) $warning->amount, $warning->user?->currency ?? auth()->user()->currency),
            ];
        }
    @endphp
    <x-panel.card class="mb-4" :title="__('sport.panel.status')">
        <x-panel.table
            :columns="[
                ['key' => 'code', 'label' => __('sport.panel.status')],
                ['key' => 'when', 'label' => __('wallet.when')],
                ['key' => 'error', 'label' => __('panel.empty_value'), 'priority' => 'detail'],
            ]"
            :rows="$stateRows"
        />
    </x-panel.card>
    <x-panel.card class="mb-4" :title="__('sport.panel.manual_settle')">
        <x-panel.table :empty="__('sport.panel.no_warnings')" :columns="[['key' => 'match', 'label' => __('sport.panel.fixture_detail')]]" :rows="$staleRows" />
    </x-panel.card>
    <x-panel.card class="mb-4" :title="__('sport.panel.approaching_void')">
        <x-panel.table :empty="__('sport.panel.no_warnings')" :columns="[['key' => 'match', 'label' => __('sport.panel.fixture_detail')]]" :rows="$voidRows" />
    </x-panel.card>
    <x-panel.card :title="__('sport.panel.overdraft')">
        <x-panel.table
            :empty="__('sport.panel.no_warnings')"
            :columns="[
                ['key' => 'user', 'label' => __('sport.panel.user')],
                ['key' => 'amount', 'label' => __('wallet.amount')],
            ]"
            :rows="$debtRows"
        />
    </x-panel.card>
@endsection
