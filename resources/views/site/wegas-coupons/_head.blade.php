@php
    $st = $coupon->panelStatus();
    $cur = \App\Enums\Currency::tryFrom((string) $coupon->currency) ?? auth()->user()->currency;
    $badge = match ($st) {
        'won' => 'bg-emerald-500/15 text-emerald-400',
        'lost' => 'bg-rose-500/15 text-rose-400',
        'pending' => 'bg-amber-500/15 text-amber-400',
        default => 'bg-slate-500/15 text-[var(--site-muted)]',
    };
@endphp
<div class="flex items-start justify-between gap-3">
    <div class="min-w-0">
        <p class="font-numeric text-lg font-bold text-[var(--site-text)]">#{{ $coupon->bet_id }}</p>
        <p class="text-xs text-[var(--site-muted)]">
            {{ $coupon->placed_at ? sport_date($coupon->placed_at->timezone(auth()->user()->timezone), 'j F Y H:i') : '' }}
            · {{ in_array($coupon->type, ['combo', 'single'], true) ? __('sport.coupon.'.$coupon->type) : $coupon->type }}
            · {{ $coupon->won_count }}/{{ $coupon->selection_count }}
        </p>
    </div>
    <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-bold {{ $badge }}">{{ __('sport.coupon.statuses.'.$st) }}</span>
</div>
<div class="mt-3 grid grid-cols-3 gap-2 text-sm">
    <div>
        <p class="text-xs text-[var(--site-muted)]">{{ __('sport.panel.cols.stake') }}</p>
        <p class="font-numeric font-bold">{{ \App\Support\Money::format((string) $coupon->stake, $cur) }}</p>
    </div>
    <div>
        <p class="text-xs text-[var(--site-muted)]">{{ __('sport.panel.cols.total') }}</p>
        <p class="font-numeric font-bold">{{ number_format((float) $coupon->total_odds, 2, ',', '.') }}</p>
    </div>
    <div>
        <p class="text-xs text-[var(--site-muted)]">{{ $st === 'won' ? __('panel.tipo_payout') : __('sport.panel.cols.win') }}</p>
        <p class="font-numeric font-bold">{{ \App\Support\Money::format((string) ($st === 'won' ? $coupon->payout : $coupon->potential_win), $cur) }}</p>
    </div>
</div>
