@extends('layouts.panel')

@section('heading', __('sport.panel.risky'))

@section('content')
    @php
        $rows = [];
        foreach ($coupons as $coupon) {
            $rows[] = [
                'no' => $coupon->coupon_no,
                'user' => $coupon->user->username,
                'win' => $coupon->potential_win,
                'detail' => new \Illuminate\Support\HtmlString('<a class="underline" href="'.e(route('panel.coupons.show', $coupon)).'">'.e(__('sport.panel.detail')).'</a>'),
            ];
        }
    @endphp
    <x-panel.table
        :columns="[
            ['key' => 'no', 'label' => __('sport.panel.cols.no')],
            ['key' => 'user', 'label' => __('sport.panel.cols.user')],
            ['key' => 'win', 'label' => __('sport.panel.cols.win')],
            ['key' => 'detail', 'label' => __('sport.panel.detail')],
        ]"
        :rows="$rows"
    />
@endsection
