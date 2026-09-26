@extends('layouts.site')

@section('heading', $coupon->coupon_no)

@section('content')
    <a class="text-sm text-[#9AA4B5]" href="{{ route('site.coupons') }}">{{ __('sport.my_coupons') }}</a>
    <div class="mt-4 grid gap-2 rounded-xl bg-[#151A23] p-4">
        <p class="font-numeric text-2xl font-bold">{{ $coupon->coupon_no }}</p>
        <p class="text-sm text-[#9AA4B5]">{{ sport_date($coupon->placed_at->timezone(auth()->user()->timezone), 'j F Y H:i') }} · {{ __('sport.coupon.'.$coupon->type) }} · {{ __('sport.coupon.statuses.'.$coupon->status) }}</p>
        @include('sport._coupon_facts')
    </div>
    <div class="mt-4 grid gap-3">
        @foreach ($coupon->selections as $selection)
            <article class="rounded-xl bg-[#151A23] p-4">
                <p class="break-words font-semibold">{{ sport_name($selection->fixture->home) }}</p>
                <p class="break-words font-semibold">{{ sport_name($selection->fixture->away) }}</p>
                <p class="mt-1 text-sm text-[#9AA4B5]">{{ __('sport.markets.'.$selection->market_code) }} · {{ __('sport.outcomes.'.$selection->outcome) }} · {{ $selection->odds }}</p>
                <div class="mt-2">@include('sport._badge', ['status' => $selection->status, 'coupon' => $coupon])</div>
                <div class="mt-2">@include('sport._selection_meta', ['selection' => $selection, 'coupon' => $coupon])</div>
            </article>
        @endforeach
    </div>
    @if ($coupon->status === 'pending')
        <form class="mt-4 grid max-w-md gap-2" method="POST" action="{{ route('site.coupons.cancel', $coupon) }}">
            @csrf
            <input class="h-11 rounded-md border border-[#232B39] bg-[#151A23] px-3" name="reason" placeholder="{{ __('sport.coupon.cancel_reason') }}" required>
            <button class="inline-flex h-11 items-center justify-center rounded-lg border border-[#232B39]" type="submit">{{ __('sport.coupon.cancel') }}</button>
        </form>
    @endif
@endsection
