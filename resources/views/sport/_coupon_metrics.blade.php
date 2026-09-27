<div class="grid grid-cols-3 gap-2 rounded-xl bg-[var(--site-panel-2)] p-3 text-center">
    <div class="flex flex-col gap-0.5"><span class="text-[11px] text-[var(--site-muted)]">{{ __('sport.coupon.stake') }}</span><span class="font-numeric text-base font-bold text-[var(--site-text)]">{{ \App\Support\Money::format((string) $coupon->stake, auth()->user()->currency) }}</span></div>
    <div class="flex flex-col gap-0.5"><span class="text-[11px] text-[var(--site-muted)]">{{ __('sport.coupon.total_odds') }}</span><span class="font-numeric text-base font-bold text-[var(--site-text)]">{{ $coupon->total_odds }}</span></div>
    <div class="flex flex-col gap-0.5"><span class="text-[11px] text-[var(--site-muted)]">{{ __('sport.coupon.potential_win') }}</span><span class="font-numeric text-base font-bold text-[var(--accent)]">{{ \App\Support\Money::format((string) $coupon->potential_win, auth()->user()->currency) }}</span></div>
</div>
