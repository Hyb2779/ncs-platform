@extends('layouts.panel')

@section('heading', $coupon->coupon_no)

@section('content')
    <p class="mb-4 text-sm text-slate-600">{{ $coupon->user->username }} · {{ $coupon->ip }} · {{ __('sport.coupon.statuses.'.$coupon->status) }}</p>
    <x-panel.card class="mb-4 max-w-lg">
        @include('sport._coupon_facts')
    </x-panel.card>
    @php
        $rows = [];
        foreach ($coupon->selections as $selection) {
            $rows[] = [
                'match' => sport_name($selection->fixture->home).' – '.sport_name($selection->fixture->away),
                'market' => __('sport.markets.'.$selection->market_code).' · '.__('sport.outcomes.'.$selection->outcome),
                'odds' => $selection->odds,
                'status' => new \Illuminate\Support\HtmlString(view('sport._badge', ['status' => $selection->status, 'coupon' => $coupon])->render().view('sport._selection_meta', ['selection' => $selection, 'coupon' => $coupon])->render()),
            ];
        }
    @endphp
    <x-panel.table
        :columns="[
            ['key' => 'match', 'label' => __('sport.panel.fixture_detail')],
            ['key' => 'market', 'label' => __('sport.panel.cols.type')],
            ['key' => 'odds', 'label' => __('sport.panel.cols.total')],
            ['key' => 'status', 'label' => __('sport.panel.cols.status')],
        ]"
        :rows="$rows"
    />
    @if ($coupon->status === 'pending')
        <form id="coupon-cancel" class="mt-4 grid max-w-md gap-2 pb-24" method="POST" action="{{ route('panel.coupons.cancel', $coupon) }}">
            @csrf
            <input class="h-11 rounded-md border px-3" name="reason" placeholder="{{ __('sport.coupon.cancel_reason') }}" required>
        </form>
        <x-panel.sticky-actions>
            <button class="inline-flex h-11 items-center rounded-lg border px-3 text-sm" type="submit" form="coupon-cancel">{{ __('sport.coupon.cancel') }}</button>
        </x-panel.sticky-actions>
    @endif
@endsection
