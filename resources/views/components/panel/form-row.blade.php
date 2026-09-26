@props(['label', 'hint' => null, 'error' => null])

<div {{ $attributes->class(['flex min-h-11 items-center gap-2 border-s-2 px-3 py-1.5']) }}>
    <div class="min-w-0 flex-1">
        <p class="break-words text-sm leading-5">{{ $label }}</p>
        @if (filled($error))
            <p class="text-xs leading-4 text-red-700">{{ $error }}</p>
        @elseif (filled($hint))
            <p class="text-xs leading-4 text-slate-500">{{ $hint }}</p>
        @endif
    </div>
    {{ $slot }}
</div>
