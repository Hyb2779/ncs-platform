@extends('layouts.panel')

@section('heading', __('sport.panel.coupons'))

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
        @foreach (['placed', 'won', 'lost', 'pending', 'balance', 'cancelled'] as $card)
            <x-panel.stat :label="__('sport.panel.cards.'.$card)" :value="$cards[$card]" />
        @endforeach
    </div>
    <x-panel.filter-bar class="mb-4">
        <form class="flex flex-wrap gap-2" method="GET">
            @if (request('from'))
                <input type="hidden" name="from" value="{{ request('from') }}">
            @endif
            @if (request('to'))
                <input type="hidden" name="to" value="{{ request('to') }}">
            @endif
            <input class="h-11 rounded-md border px-3" name="q" value="{{ request('q') }}" placeholder="{{ __('sport.panel.cols.no') }}" inputmode="numeric">
            <input class="h-11 rounded-md border px-3" name="user" value="{{ request('user') }}" placeholder="{{ __('sport.panel.user') }}">
            <select class="h-11 rounded-md border px-2" name="type">
                <option value="">{{ __('sport.panel.type') }}</option>
                @foreach (['combo', 'single'] as $type)
                    <option value="{{ $type }}" @selected(request('type') === $type)>{{ __('sport.coupon.'.$type) }}</option>
                @endforeach
            </select>
            <select class="h-11 rounded-md border px-2" name="status">
                <option value="">{{ __('sport.panel.status_filter') }}</option>
                @foreach (['pending', 'won', 'lost', 'void', 'refunded', 'cancelled'] as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ __('sport.coupon.statuses.'.$status) }}</option>
                @endforeach
            </select>
            <button class="inline-flex h-11 items-center rounded-lg border px-3" type="submit">{{ __('sport.panel.search') }}</button>
        </form>
    </x-panel.filter-bar>
    @php
        $tz = auth()->user()->timezone;
        $badge = fn (string $st) => match ($st) {
            'won' => 'bg-emerald-50 text-emerald-700',
            'lost' => 'bg-rose-50 text-rose-700',
            'pending' => 'bg-amber-50 text-amber-800',
            default => 'bg-slate-100 text-slate-600',
        };
    @endphp
    <div class="overflow-hidden rounded-lg border border-[#E3E6EB] bg-white">
        @forelse ($coupons as $i => $coupon)
            @php
                $st = $coupon->panelStatus();
                $cur = \App\Enums\Currency::tryFrom((string) $coupon->currency) ?? auth()->user()->currency;
                $m = fn ($v) => \App\Support\Money::format((string) $v, $cur);
            @endphp
            <a href="{{ route('panel.coupons.tipo', $coupon) }}" class="grid gap-1 px-3 py-2.5 hover:bg-[#F3F4F6] md:grid-cols-[minmax(0,1fr)_auto] md:items-center md:gap-4 {{ $i > 0 ? 'border-t border-[#E3E6EB]' : '' }}">
                <div class="min-w-0">
                    <p class="flex min-w-0 items-center gap-2 text-sm">
                        <span class="font-numeric font-semibold">#{{ $coupon->bet_id }}</span>
                        <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-bold {{ $badge($st) }}">{{ __('sport.coupon.statuses.'.$st) }}</span>
                        <span class="truncate text-slate-500">{{ $coupon->user?->username }}</span>
                    </p>
                    <p class="text-xs text-slate-500">
                        {{ $coupon->placed_at?->timezone($tz)->format('d.m.Y H:i') }}
                        · {{ in_array($coupon->type, ['combo', 'single'], true) ? __('sport.coupon.'.$coupon->type) : $coupon->type }}
                        · {{ $coupon->won_count }}/{{ $coupon->selection_count }}
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-0.5 text-sm md:justify-end">
                    <span><span class="text-slate-500">{{ __('sport.panel.cols.stake') }}</span> <b class="font-numeric font-semibold">{{ $m($coupon->stake) }}</b></span>
                    <span><span class="text-slate-500">{{ __('sport.panel.cols.total') }}</span> <b class="font-numeric font-semibold">{{ number_format((float) $coupon->total_odds, 2, ',', '.') }}</b></span>
                    @if ($st === 'won')
                        <span><span class="text-slate-500">{{ __('panel.tipo_payout') }}</span> <b class="font-numeric font-semibold text-emerald-700">{{ $m($coupon->payout) }}</b></span>
                    @else
                        <span><span class="text-slate-500">{{ __('sport.panel.cols.win') }}</span> <b class="font-numeric font-semibold">{{ $m($coupon->potential_win) }}</b></span>
                    @endif
                </div>
            </a>
        @empty
            <div class="px-3 py-6 text-center text-sm text-slate-500">{{ __('panel.member_no_coupons') }}</div>
        @endforelse
    </div>
@endsection
