@props(['dates' => true])

@php
    $zone = auth()->user()?->timezone ?: 'UTC';
    $today = \Illuminate\Support\Carbon::now($zone);
    $presets = [
        ['label' => __('panel.filter_today'), 'from' => $today->toDateString(), 'to' => $today->toDateString()],
        ['label' => __('panel.filter_yesterday'), 'from' => $today->copy()->subDay()->toDateString(), 'to' => $today->copy()->subDay()->toDateString()],
        ['label' => __('panel.filter_7'), 'from' => $today->copy()->subDays(6)->toDateString(), 'to' => $today->toDateString()],
        ['label' => __('panel.filter_30'), 'from' => $today->copy()->subDays(29)->toDateString(), 'to' => $today->toDateString()],
        ['label' => __('panel.filter_month'), 'from' => $today->copy()->startOfMonth()->toDateString(), 'to' => $today->toDateString()],
    ];
    $currentFrom = (string) request('from', '');
    $currentTo = (string) request('to', '');
@endphp

<div {{ $attributes->class(['']) }} x-data="{ sheet: false }">
    <div class="hidden flex-wrap items-end gap-2 md:flex">
        @if ($dates)
        @foreach ($presets as $preset)
            <a
                class="inline-flex h-11 items-center rounded-lg border px-3 text-sm {{ $currentFrom === $preset['from'] && $currentTo === $preset['to'] ? 'border-[var(--accent)] bg-[var(--accent)] text-[#1A1305]' : 'border-[#E3E6EB] bg-white' }}"
                href="{{ request()->fullUrlWithQuery(['from' => $preset['from'], 'to' => $preset['to']]) }}"
            >{{ $preset['label'] }}</a>
        @endforeach
        <form class="flex flex-wrap items-end gap-2" method="GET" action="{{ url()->current() }}">
            <label class="grid gap-1 text-xs text-slate-500">
                {{ __('panel.filter_from') }}
                <input class="h-11 rounded-lg border border-[#E3E6EB] px-2 text-sm" type="date" name="from" value="{{ $currentFrom }}">
            </label>
            <label class="grid gap-1 text-xs text-slate-500">
                {{ __('panel.filter_to') }}
                <input class="h-11 rounded-lg border border-[#E3E6EB] px-2 text-sm" type="date" name="to" value="{{ $currentTo }}">
            </label>
            @foreach (request()->except(['from', 'to', 'page']) as $name => $value)
                @if (is_scalar($value))
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endif
            @endforeach
            <button class="inline-flex h-11 items-center rounded-lg border border-[#E3E6EB] bg-white px-3 text-sm" type="submit">{{ __('panel.filter_apply') }}</button>
        </form>
        @endif
        {{ $slot }}
    </div>
    <button class="inline-flex h-11 items-center rounded-lg border border-[#E3E6EB] bg-white px-3 text-sm md:hidden" type="button" @click="sheet = true">{{ __('panel.filter_open') }}</button>
    <div class="fixed inset-0 z-40 md:hidden" x-show="sheet" x-cloak>
        <div class="absolute inset-0 bg-slate-900/40" @click="sheet = false"></div>
        <div class="absolute inset-x-0 bottom-0 grid gap-2 rounded-t-xl bg-white p-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
            <div class="mb-1 flex items-center justify-between">
                <p class="text-sm font-semibold">{{ __('panel.filter_custom') }}</p>
                <button class="inline-flex h-11 items-center px-2 text-sm" type="button" @click="sheet = false">{{ __('panel.filter_close') }}</button>
            </div>
            @if ($dates)
            @foreach ($presets as $preset)
                <a
                    class="inline-flex h-11 items-center rounded-lg border px-3 text-sm {{ $currentFrom === $preset['from'] && $currentTo === $preset['to'] ? 'border-[var(--accent)] bg-[var(--accent)] text-[#1A1305]' : 'border-[#E3E6EB]' }}"
                    href="{{ request()->fullUrlWithQuery(['from' => $preset['from'], 'to' => $preset['to']]) }}"
                >{{ $preset['label'] }}</a>
            @endforeach
            <form class="mt-2 grid gap-2" method="GET" action="{{ url()->current() }}">
                <label class="grid gap-1 text-xs text-slate-500">
                    {{ __('panel.filter_from') }}
                    <input class="h-11 rounded-lg border border-[#E3E6EB] px-2 text-sm" type="date" name="from" value="{{ $currentFrom }}">
                </label>
                <label class="grid gap-1 text-xs text-slate-500">
                    {{ __('panel.filter_to') }}
                    <input class="h-11 rounded-lg border border-[#E3E6EB] px-2 text-sm" type="date" name="to" value="{{ $currentTo }}">
                </label>
                @foreach (request()->except(['from', 'to', 'page']) as $name => $value)
                    @if (is_scalar($value))
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endif
                @endforeach
                <button class="inline-flex h-11 items-center justify-center rounded-lg bg-[var(--accent)] text-sm font-semibold text-[#1A1305]" type="submit">{{ __('panel.filter_apply') }}</button>
            </form>
            @endif
            {{ $slot }}
        </div>
    </div>
</div>
