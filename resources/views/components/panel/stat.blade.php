@props(['label', 'value', 'change' => null])

@php
    $number = $change === null || $change === '' ? null : (float) $change;
    $direction = $number === null ? null : ($number > 0 ? 'up' : ($number < 0 ? 'down' : 'flat'));
@endphp

<article {{ $attributes->class(['rounded-lg bg-white p-4']) }}>
    <p class="text-xs text-slate-500">{{ $label }}</p>
    <p class="mt-1 font-numeric text-2xl">{{ $value }}</p>
    @if ($direction !== null)
        <p @class([
            'mt-1 text-xs',
            'text-emerald-700' => $direction === 'up',
            'text-red-700' => $direction === 'down',
            'text-slate-500' => $direction === 'flat',
        ])>
            <span aria-hidden="true">{{ $direction === 'up' ? '↑' : ($direction === 'down' ? '↓' : '→') }}</span>
            <span class="sr-only">{{ __($direction === 'down' ? 'panel.stat_down' : 'panel.stat_up') }}</span>
            <span class="font-numeric">{{ number_format(abs($number), 1, '.', ',') }}%</span>
        </p>
    @endif
</article>
