@props(['title' => null, 'accordion' => false])

@if ($accordion)
    <details {{ $attributes->class(['limit-group rounded-lg bg-white']) }} open>
        <summary class="flex h-11 cursor-pointer list-none items-center justify-between px-4 text-sm font-semibold">
            <span>{{ $title }}</span>
            <span class="limit-chevron text-slate-400 lg:hidden" aria-hidden="true"></span>
        </summary>
        <div class="divide-y divide-[#E3E6EB] border-t border-[#E3E6EB]">
            {{ $slot }}
        </div>
    </details>
@else
    <section {{ $attributes->class(['rounded-lg bg-white p-4']) }}>
        @if (filled($title))
            <h2 class="mb-3 text-sm font-semibold">{{ $title }}</h2>
        @endif
        {{ $slot }}
    </section>
@endif
