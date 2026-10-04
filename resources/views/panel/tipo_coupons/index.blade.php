@extends('layouts.panel')

@section('heading', __('sport.panel.coupons'))

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
        @foreach (['placed', 'won', 'lost', 'pending', 'balance', 'cancelled'] as $card)
            <x-panel.stat :label="__('sport.panel.cards.'.$card)" :value="$cards[$card]" />
        @endforeach
    </div>
    <x-panel.filter-bar class="mb-4">
        <form class="flex flex-wrap gap-2" method="GET">
            @if (request('from'))
                <input type="hidden" name="from" value="{{ request('from') }}">
            @endif
            @if (request('to'))
                <input type="hidden" name="to" value="{{ request('to') }}">
            @endif
            <input class="h-11 rounded-md border px-3" name="q" value="{{ request('q') }}" placeholder="{{ __('sport.panel.cols.no') }}" inputmode="numeric">
            <input class="h-11 rounded-md border px-3" name="user" value="{{ request('user') }}" placeholder="{{ __('sport.panel.user') }}">
            <select class="h-11 rounded-md border px-2" name="type">
                <option value="">{{ __('sport.panel.type') }}</option>
                @foreach (['combo', 'single'] as $type)
                    <option value="{{ $type }}" @selected(request('type') === $type)>{{ __('sport.coupon.'.$type) }}</option>
                @endforeach
            </select>
            <select class="h-11 rounded-md border px-2" name="status">
                <option value="">{{ __('sport.panel.status_filter') }}</option>
                @foreach (['pending', 'won', 'lost', 'void', 'refunded', 'cancelled'] as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ __('sport.coupon.statuses.'.$status) }}</option>
                @endforeach
            </select>
            <button class="inline-flex h-11 items-center rounded-lg border px-3" type="submit">{{ __('sport.panel.search') }}</button>
        </form>
    </x-panel.filter-bar>
    @php
        $tz = auth()->user()->timezone;
        $rows = [];
        foreach ($coupons as $coupon) {
            $rows[] = [
                'no' => $coupon->bet_id,
                'user' => $coupon->user?->username,
                'stake' => $coupon->stake,
                'status' => new \Illuminate\Support\HtmlString('<p>'.e(__('sport.coupon.statuses.'.$coupon->panelStatus())).'</p><p class="text-xs text-slate-500">'.$coupon->won_count.' / '.$coupon->selection_count.'</p>'),
                'detail' => new \Illuminate\Support\HtmlString('<a class="underline" href="'.e(route('panel.coupons.tipo', $coupon)).'">'.e(__('sport.panel.detail')).'</a>'),
                'time' => $coupon->placed_at?->timezone($tz)->format('d.m.Y H:i'),
                'type' => in_array($coupon->type, ['combo', 'single'], true) ? __('sport.coupon.'.$coupon->type) : $coupon->type,
                'count' => $coupon->selection_count,
                'total' => $coupon->total_odds,
                'win' => $coupon->potential_win,
                'payout' => $coupon->payout,
            ];
        }
    @endphp
    <x-panel.table
        :columns="[
            ['key' => 'no', 'label' => __('sport.panel.cols.no')],
            ['key' => 'user', 'label' => __('sport.panel.cols.user')],
            ['key' => 'stake', 'label' => __('sport.panel.cols.stake')],
            ['key' => 'status', 'label' => __('sport.panel.cols.status')],
            ['key' => 'detail', 'label' => __('sport.panel.detail')],
            ['key' => 'time', 'label' => __('sport.panel.cols.time'), 'priority' => 'detail'],
            ['key' => 'type', 'label' => __('sport.panel.cols.type'), 'priority' => 'detail'],
            ['key' => 'count', 'label' => __('sport.panel.cols.count'), 'priority' => 'detail'],
            ['key' => 'total', 'label' => __('sport.panel.cols.total'), 'priority' => 'detail'],
            ['key' => 'win', 'label' => __('sport.panel.cols.win'), 'priority' => 'detail'],
            ['key' => 'payout', 'label' => __('panel.tipo_payout'), 'priority' => 'detail'],
        ]"
        :rows="$rows"
    />
@endsection
