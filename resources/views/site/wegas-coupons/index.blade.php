@extends('layouts.site')

@section('heading', __('sport.my_coupons'))

@section('content')
    <div class="mb-4 flex gap-4 overflow-x-auto border-b border-[var(--site-line)]">
        @foreach ($tabs as $tab)
            <a class="shrink-0 py-2.5 text-sm {{ $status === $tab ? 'sport-tab-on font-bold text-white' : 'font-semibold text-[var(--site-muted)]' }}" href="{{ route('site.wegas_coupons', ['status' => $tab]) }}">{{ __('sport.coupon.statuses.'.$tab) }}</a>
        @endforeach
    </div>
    <div class="grid gap-3 md:grid-cols-2 md:items-start">
        @forelse ($coupons as $coupon)
            <a class="block rounded-2xl border border-[var(--site-line)] bg-[var(--site-panel)] p-4" href="{{ route('site.wegas_coupons.show', $coupon) }}">
                @include('site.wegas-coupons._head')
            </a>
        @empty
            <p class="text-[var(--site-muted)]">{{ __('sport.coupon.empty') }}</p>
        @endforelse
    </div>
@endsection
