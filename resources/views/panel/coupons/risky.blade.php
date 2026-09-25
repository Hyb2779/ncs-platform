@extends('layouts.panel')

@section('heading', __('sport.panel.risky'))

@section('content')
    <div class="overflow-x-auto rounded-lg bg-white">
        <table class="w-full text-sm">
            @foreach ($coupons as $coupon)
                <tr class="border-b">
                    <td class="px-3 py-2 font-numeric">{{ $coupon->coupon_no }}</td>
                    <td class="px-3 py-2">{{ $coupon->user->username }}</td>
                    <td class="px-3 py-2 font-numeric">{{ $coupon->potential_win }}</td>
                    <td class="px-3 py-2"><a class="underline" href="{{ route('panel.coupons.show', $coupon) }}">{{ __('sport.panel.detail') }}</a></td>
                </tr>
            @endforeach
        </table>
    </div>
@endsection
