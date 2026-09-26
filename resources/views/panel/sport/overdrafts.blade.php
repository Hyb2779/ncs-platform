@extends('layouts.panel')

@section('heading', __('sport.panel.overdraft'))

@section('content')
    @php
        $rows = [];
        foreach ($overdrafts as $warning) {
            $currency = $warning->user?->currency ?? auth()->user()->currency;
            $rows[] = [
                'user' => $warning->user?->username,
                'amount' => new \Illuminate\Support\HtmlString(\Illuminate\Support\Facades\Blade::render(
                    '<x-panel.badge tone="danger">{{ $label }}</x-panel.badge>',
                    ['label' => \App\Support\Money::format((string) $warning->amount, $currency)],
                )),
            ];
        }
    @endphp
    <x-panel.table
        :empty="__('sport.panel.no_warnings')"
        :columns="[
            ['key' => 'user', 'label' => __('sport.panel.user')],
            ['key' => 'amount', 'label' => __('wallet.amount')],
        ]"
        :rows="$rows"
    />
@endsection
