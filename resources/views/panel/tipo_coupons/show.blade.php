@extends('layouts.panel')

@section('heading', __('sport.panel.cols.no').' #'.$coupon->bet_id)

@section('content')
    @php
        $tz = auth()->user()->timezone;
        $currency = \App\Enums\Currency::tryFrom((string) $coupon->currency) ?? auth()->user()->currency;
        $m = fn ($v) => \App\Support\Money::format((string) $v, $currency);
        $selections = is_array($coupon->detail['selections'] ?? null) ? $coupon->detail['selections'] : [];
        $pair = fn ($h, $a) => ($h === null || $a === null) ? null : $h.' - '.$a;
        $badge = fn (string $s) => match ($s) {
            'won' => 'bg-emerald-50 text-emerald-700',
            'lost' => 'bg-rose-50 text-rose-700',
            'pending' => 'bg-amber-50 text-amber-800',
            default => 'bg-slate-100 text-slate-600',
        };
        $st = $coupon->panelStatus();
    @endphp

    <div class="mb-4 rounded-lg border border-[#E3E6EB] bg-white p-4">
        <div class="flex flex-wrap items-center gap-2">
            <p class="font-numeric text-lg font-semibold">#{{ $coupon->bet_id }}</p>
            <span class="rounded-full px-2.5 py-0.5 text-xs font-bold {{ $badge($st) }}">{{ __('sport.coupon.statuses.'.$st) }}</span>
        </div>
        <p class="mt-1 text-sm text-slate-500">
            @if ($coupon->user)<a class="font-medium underline" href="{{ route('panel.users.show', $coupon->user_id) }}">{{ $coupon->user->username }}</a> · @endif
            {{ $coupon->placed_at?->timezone($tz)->format('d.m.Y H:i') }}
            · {{ in_array($coupon->type, ['combo', 'single'], true) ? __('sport.coupon.'.$coupon->type) : $coupon->type }}
            · {{ $coupon->won_count }}/{{ $coupon->selection_count }}
        </p>
        <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
            @foreach ([
                [__('sport.panel.cols.stake'), $m($coupon->stake)],
                [__('sport.panel.cols.total'), number_format((float) $coupon->total_odds, 2, ',', '.')],
                [__('sport.panel.cols.win'), $m($coupon->potential_win)],
                [__('panel.tipo_payout'), $m($coupon->payout)],
            ] as [$label, $value])
                <div class="rounded-lg bg-[#F3F4F6] px-3 py-2">
                    <p class="text-xs text-slate-500">{{ $label }}</p>
                    <p class="font-numeric font-semibold">{{ $value }}</p>
                </div>
            @endforeach
        </div>
    </div>

    @if ($selections === [])
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">{{ __('panel.tipo_detail_unavailable') }}</div>
    @else
        <div class="grid gap-3 lg:grid-cols-2">
            @foreach ($selections as $s)
                @php
                    $sst = \App\Models\TipoCoupon::statusFor($s['status_label'] ?? null);
                    $snap = is_array($s['live_snapshot'] ?? null) ? $s['live_snapshot'] : null;
                    $atBet = $snap ? trim((($snap['minutes'] ?? '') !== '' ? $snap['minutes']."' " : '').($pair($snap['home_score'] ?? null, $snap['away_score'] ?? null) ?? '')) : null;
                    $score = is_array($s['score'] ?? null) ? $pair($s['score']['home'] ?? null, $s['score']['away'] ?? null) : null;
                    $handicap = (string) ($s['handicap'] ?? '');
                    $league = trim(($s['country_name'] ?? '').' · '.($s['competition_name'] ?? ''), ' ·');
                    $time = isset($s['match_time']) && is_numeric($s['match_time']) ? \Carbon\Carbon::createFromTimestamp((int) $s['match_time'])->timezone($tz)->format('d.m H:i') : null;
                @endphp
                <div class="rounded-lg border border-[#E3E6EB] bg-white p-3">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-semibold leading-snug">{{ ($s['home_name'] ?? '').' - '.($s['away_name'] ?? '') }}</p>
                            <p class="truncate text-xs text-slate-500">{{ $league }}@if ($time) · {{ $time }}@endif</p>
                        </div>
                        <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-bold {{ $badge($sst) }}">{{ __('sport.coupon.statuses.'.$sst) }}</span>
                    </div>
                    <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                        <span class="text-slate-500">{{ $s['market_name'] ?? '' }}: <b class="font-semibold">{{ ($s['selection_name'] ?? '').($handicap !== '' && $handicap !== '0' ? ' ('.$handicap.')' : '') }}</b></span>
                        <span class="text-slate-500">{{ __('panel.tipo_odds') }}: <b class="font-numeric font-semibold">{{ isset($s['odds']) ? number_format((float) $s['odds'], 2, ',', '.') : '' }}</b></span>
                    </div>
                    @if ($atBet || $score)
                        <div class="mt-1 flex flex-wrap gap-x-4 text-xs text-slate-500">
                            @if ($atBet)<span>{{ __('panel.tipo_at_bet') }}: <b class="font-semibold">{{ $atBet }}</b></span>@endif
                            @if ($score)<span>{{ __('panel.tipo_score') }}: <b class="font-semibold">{{ $score }}</b></span>@endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
    <a class="mt-4 inline-flex h-11 items-center rounded-lg border px-3 text-sm" href="{{ route('panel.coupons.index') }}">{{ __('panel.tipo_back') }}</a>
@endsection
