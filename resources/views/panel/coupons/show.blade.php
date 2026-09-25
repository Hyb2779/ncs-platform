@extends('layouts.panel')

@section('heading', $coupon->coupon_no)

@section('content')
    <p class="mb-4 text-sm text-slate-600">{{ $coupon->user->username }} · {{ $coupon->ip }} · {{ __('sport.coupon.statuses.'.$coupon->status) }}</p>
    <div class="grid gap-2">
        @foreach ($coupon->selections as $selection)
            <article class="rounded-lg bg-white p-3 text-sm">
                <p>{{ sport_name($selection->fixture->home) }} – {{ sport_name($selection->fixture->away) }}</p>
                <p>{{ __('sport.markets.'.$selection->market_code) }} · {{ __('sport.outcomes.'.$selection->outcome) }} · {{ $selection->odds }} · {{ __('sport.selection.'.$selection->status) }}</p>
            </article>
        @endforeach
    </div>
    @if ($coupon->status === 'pending')
        <form class="mt-4 grid max-w-md gap-2" method="POST" action="{{ route('panel.coupons.cancel', $coupon) }}">
            @csrf
            <input class="h-11 rounded-md border px-3" name="reason" placeholder="{{ __('sport.coupon.cancel_reason') }}" required>
            <button class="inline-flex h-11 items-center justify-center rounded-lg border" type="submit">{{ __('sport.coupon.cancel') }}</button>
        </form>
    @endif
@endsection
