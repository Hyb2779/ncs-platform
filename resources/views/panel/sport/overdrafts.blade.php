@extends('layouts.panel')

@section('heading', __('sport.panel.overdraft'))

@section('content')
    <div class="mb-4 rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm leading-relaxed text-slate-700">
        <p class="mb-1 font-semibold text-slate-900">{{ __('wallet.overdraft_help_title') }}</p>
        <p>{{ __('wallet.overdraft_help_1') }}</p>
        <p class="mt-2">{{ __('wallet.overdraft_help_2') }}</p>
        <p class="mt-2 text-slate-500">{{ __('wallet.overdraft_help_3') }}</p>
    </div>
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
