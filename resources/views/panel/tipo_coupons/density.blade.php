@extends('layouts.panel')

@section('heading', __('panel.density_title'))

@section('content')
    @php
        $m = fn ($v) => \App\Support\Money::format((string) $v, $currency);
        $btn = 'inline-flex h-10 items-center rounded-lg border border-[#E3E6EB] px-3 text-sm';
        $top = $events->first();
    @endphp
    <div class="mb-3 flex flex-wrap gap-2">
        @foreach (['open' => 'density_mode_open', 'today' => 'density_mode_today', '7' => 'density_mode_7'] as $key => $label)
            <a class="{{ $btn }} {{ $mode === (string) $key ? 'bg-[#161A22] text-white' : '' }}" href="{{ route('panel.coupons.density', ['mode' => $key, 'sort' => $sort]) }}">{{ __('panel.'.$label) }}</a>
        @endforeach
        <span class="w-2"></span>
        @foreach (['coupons' => 'density_sort_coupons', 'stake' => 'density_sort_stake'] as $key => $label)
            <a class="{{ $btn }} {{ $sort === $key ? 'bg-[#161A22] text-white' : '' }}" href="{{ route('panel.coupons.density', ['mode' => $mode, 'sort' => $key]) }}">{{ __('panel.'.$label) }}</a>
        @endforeach
    </div>
    <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-panel.stat :label="__('panel.density_matches')" :value="(string) $events->count()" />
        <x-panel.stat :label="__('panel.density_top')" :value="$top ? $top->home.' - '.$top->away : '—'" />
    </div>
    @forelse ($events as $event)
        @php
            $list = $picks->get($event->event_id, collect());
            $max = max(1, (int) $list->max('coupons'));
            $time = $event->match_time ? display_clock($event->match_time, 'd.m H:i') : null;
        @endphp
        <div class="mb-3 rounded-lg border border-[#E3E6EB] bg-white p-3">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="font-semibold">
                        {{ $event->home }} - {{ $event->away }}
                        @if ($event->live)<span class="ms-1 rounded bg-rose-50 px-1.5 py-0.5 text-[11px] font-bold text-rose-700">{{ __('panel.density_live') }}</span>@endif
                    </p>
                    <p class="text-xs text-slate-500">{{ trim(($event->country ?? '').' · '.($event->competition ?? ''), ' ·') }}@if ($time) · {{ $time }}@endif</p>
                </div>
                <div class="text-end">
                    <p class="font-numeric font-semibold">{{ $m($event->stake) }}</p>
                    <p class="text-xs text-slate-500">{{ __('panel.today_coupons', ['count' => $event->coupons]) }}</p>
                </div>
            </div>
            <div class="mt-2 grid gap-1.5">
                @foreach ($list as $pick)
                    <div class="text-sm">
                        <div class="flex flex-wrap justify-between gap-2">
                            <span>{{ $pick->market }} · <b>{{ $pick->pick }}{{ ($pick->handicap !== null && $pick->handicap !== '' && $pick->handicap !== '0') ? ' ('.$pick->handicap.')' : '' }}</b></span>
                            <span class="font-numeric text-slate-500">{{ __('panel.today_coupons', ['count' => $pick->coupons]) }} · {{ $m($pick->stake) }} · {{ __('sport.panel.cols.win') }} {{ $m($pick->exposure) }}</span>
                        </div>
                        <div class="mt-1 h-1.5 rounded bg-slate-100"><div class="h-1.5 rounded bg-amber-400" style="width: {{ (int) round($pick->coupons / $max * 100) }}%"></div></div>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="rounded-lg border border-[#E3E6EB] bg-white p-4 text-sm text-slate-500">{{ __('panel.density_empty') }}</div>
    @endforelse
    <p class="mt-2 text-xs text-slate-500">{{ __('panel.density_note') }}</p>
@endsection
