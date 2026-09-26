@php
    $currency = auth()->user()->currency;
    $refund = in_array($coupon->status, ['cancelled', 'refunded', 'void'], true) ? (string) $coupon->stake : null;
    $closed = in_array($coupon->status, ['cancelled', 'refunded'], true);
@endphp
<dl class="grid gap-2 text-sm">
    <div class="flex items-baseline justify-between gap-3">
        <dt>{{ __('sport.coupon.stake') }}</dt>
        <dd class="font-numeric">{{ \App\Support\Money::format((string) $coupon->stake, $currency) }}</dd>
    </div>
    <div class="flex items-baseline justify-between gap-3">
        <dt>{{ __('sport.coupon.total_odds') }}</dt>
        <dd class="font-numeric">{{ $coupon->total_odds }}</dd>
    </div>
    <div class="flex items-baseline justify-between gap-3">
        <dt>{{ __('sport.coupon.potential_win') }}</dt>
        <dd class="font-numeric">{{ \App\Support\Money::format((string) $coupon->potential_win, $currency) }}</dd>
    </div>
    <div class="flex items-baseline justify-between gap-3">
        <dt>{{ __('sport.coupon.refund_amount') }}</dt>
        <dd class="font-numeric">{{ $refund === null ? __('panel.empty_value') : \App\Support\Money::format($refund, $currency) }}</dd>
    </div>
    @if ($closed)
        <div class="flex items-baseline justify-between gap-3">
            <dt>{{ __('sport.coupon.cancel_reason') }}</dt>
            <dd class="text-end">{{ $coupon->cancel_reason ?: __('panel.empty_value') }}</dd>
        </div>
        <div class="flex items-baseline justify-between gap-3">
            <dt>{{ __('sport.coupon.cancelled_by') }}</dt>
            <dd>{{ account_label($coupon->canceller, auth()->user()) }}</dd>
        </div>
        <div class="flex items-baseline justify-between gap-3">
            <dt>{{ __('sport.coupon.cancelled_at') }}</dt>
            <dd class="font-numeric">{{ $coupon->settled_at ? sport_date($coupon->settled_at->timezone(auth()->user()->timezone), 'j F Y H:i') : __('panel.empty_value') }}</dd>
        </div>
    @endif
</dl>
