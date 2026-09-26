@props(['tone' => 'neutral'])

@php
    $tones = [
        'neutral' => 'bg-slate-100 text-slate-700',
        'success' => 'bg-emerald-50 text-emerald-800',
        'warning' => 'bg-amber-50 text-amber-900',
        'danger' => 'bg-red-50 text-red-800',
    ];
@endphp

<span {{ $attributes->class(['inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium', $tones[$tone] ?? $tones['neutral']]) }}>{{ $slot }}</span>
