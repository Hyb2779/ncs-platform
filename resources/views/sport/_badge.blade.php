@php
    $shown = $status;
    if (isset($coupon) && in_array($coupon->status, ['cancelled', 'refunded'], true) && $status === 'pending') {
        $shown = 'cancelled';
    }
    $tone = match ($shown) {
        'won' => 'bg-emerald-100 text-emerald-800',
        'lost' => 'bg-red-100 text-red-700',
        'void' => 'bg-amber-100 text-amber-800',
        'cancelled' => 'bg-slate-200 text-slate-700',
        default => 'bg-slate-100 text-slate-600',
    };
@endphp
<span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $tone }}">{{ __('sport.selection.'.$shown) }}</span>
