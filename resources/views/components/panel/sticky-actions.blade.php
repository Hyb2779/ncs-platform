<div {{ $attributes->class([
    'fixed start-0 end-0 z-20 border-t border-[#E3E6EB] bg-white px-4 pt-3 shadow-[0_-4px_16px_rgba(15,23,42,0.06)]',
    'bottom-[calc(4rem+env(safe-area-inset-bottom))] md:bottom-0 md:start-64',
    'pb-[max(0.75rem,env(safe-area-inset-bottom))]',
]) }}>
    <div class="flex items-center gap-2">
        {{ $slot }}
    </div>
</div>
