@extends('layouts.site')

@section('heading', $coupon->coupon_no)

@section('content')
    <a class="text-sm font-semibold text-[var(--site-muted)]" href="{{ route('site.coupons') }}"><span class="rtl:hidden">&larr;</span><span class="hidden rtl:inline">&rarr;</span> {{ __('sport.my_coupons') }}</a>
    <div class="mt-3 grid gap-4 lg:grid-cols-[22rem_minmax(0,1fr)] lg:items-start">
        <section class="flex flex-col gap-3 rounded-2xl border border-[var(--site-line)] bg-[var(--site-panel)] p-4 lg:sticky lg:top-24">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-numeric text-2xl font-bold text-[var(--site-text)]">{{ $coupon->coupon_no }}</p>
                    <p class="text-xs text-[var(--site-muted)]">{{ sport_date($coupon->placed_at->timezone(auth()->user()->timezone), 'j F Y H:i') }} · {{ __('sport.coupon.'.$coupon->type) }}</p>
                </div>
                @include('sport._coupon_status')
            </div>
            @include('sport._coupon_metrics')
            @include('sport._coupon_facts', ['compact' => true])
            @if ($coupon->status === 'pending')
                <div class="grid gap-3 border-t border-[var(--site-line)] pt-3">
                    @if ($errors->has('coupon'))
                        <p class="text-sm font-semibold text-rose-300">{{ $errors->first('coupon') }}</p>
                    @endif
                    @if ($cashout !== null)
                        <form id="coupon-cashout" method="POST" action="{{ route('site.coupons.cashout', $coupon) }}">
                            @csrf
                            <input type="hidden" name="amount" value="{{ $cashout }}">
                            <button class="inline-flex h-11 w-full items-center justify-center rounded-xl bg-[var(--accent)] text-sm font-bold text-white" type="submit">{{ __('sport.coupon.cashout', ['amount' => \App\Http\Controllers\Site\CouponController::money($cashout)]) }}</button>
                        </form>
                    @endif
                <form class="grid gap-2" method="POST" action="{{ route('site.coupons.cancel', $coupon) }}">
                    @csrf
                    <input class="h-11 rounded-xl border border-[var(--site-line)] bg-[var(--site-bg)] px-3 text-sm text-[var(--site-text)]" name="reason" placeholder="{{ __('sport.coupon.cancel_reason') }}" required>
                    <button class="inline-flex h-11 items-center justify-center rounded-xl border border-[var(--site-line)] text-sm font-bold text-[var(--site-text)]" type="submit">{{ __('sport.coupon.cancel') }}</button>
                </form>
                </div>
            @endif
        </section>
        <section class="divide-y divide-[var(--site-line)] rounded-2xl border border-[var(--site-line)] bg-[var(--site-panel)] px-4">
            @foreach ($coupon->selections as $selection)
                @include('sport._selection_row')
            @endforeach
        </section>
    </div>
@endsection
