@php
    $tone = match ($status) {
        'won' => 'bg-emerald-100 text-emerald-800',
        'lost' => 'bg-red-100 text-red-700',
        'void' => 'bg-amber-100 text-amber-800',
        default => 'bg-slate-100 text-slate-600',
    };
@endphp
<span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $tone }}">{{ __('sport.selection.'.$status) }}</span>
