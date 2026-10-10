@php
    $pillTone = match ($coupon->status) {
        'won' => 'bg-emerald-500/15 text-emerald-300',
        'lost' => 'bg-red-500/15 text-red-300',
        'void', 'refunded' => 'bg-amber-500/15 text-amber-300',
        'cancelled' => 'bg-slate-500/20 text-slate-300',
        'cashed_out' => 'bg-sky-500/15 text-sky-300',
        default => 'border border-[var(--site-line)] text-[var(--accent)]',
    };
@endphp
<span class="inline-flex shrink-0 items-center rounded-full px-2.5 py-1 text-xs font-bold {{ $pillTone }}">{{ __('sport.coupon.statuses.'.$coupon->status) }}</span>
