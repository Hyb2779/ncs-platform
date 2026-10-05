@extends('layouts.site')

@section('heading', __('sport.my_coupons'))

@section('content')
    @php
        $selections = is_array($coupon->detail['selections'] ?? null) ? $coupon->detail['selections'] : [];
        $pair = fn ($h, $a) => ($h === null || $a === null) ? null : $h.' - '.$a;
    @endphp
    <a class="text-sm font-semibold text-[var(--site-muted)]" href="{{ route('site.wegas_coupons') }}"><span class="rtl:hidden">&larr;</span><span class="hidden rtl:inline">&rarr;</span> {{ __('sport.my_coupons') }}</a>
    <div class="mt-3 rounded-2xl border border-[var(--site-line)] bg-[var(--site-panel)] p-4">
        @include('site.wegas-coupons._head')
    </div>
    @if ($selections === [])
        <p class="mt-3 text-sm text-[var(--site-muted)]">{{ __('panel.tipo_detail_unavailable') }}</p>
    @else
        <div class="mt-3 overflow-hidden rounded-2xl border border-[var(--site-line)] bg-[var(--site-panel)]">
            @foreach ($selections as $i => $s)
                @php
                    $sst = \App\Models\TipoCoupon::statusFor($s['status_label'] ?? null);
                    $snap = is_array($s['live_snapshot'] ?? null) ? $s['live_snapshot'] : null;
                    $atBet = $snap ? trim((($snap['minutes'] ?? '') !== '' ? $snap['minutes']."' " : '').($pair($snap['home_score'] ?? null, $snap['away_score'] ?? null) ?? '')) : null;
                    $score = is_array($s['score'] ?? null) ? $pair($s['score']['home'] ?? null, $s['score']['away'] ?? null) : null;
                    $handicap = (string) ($s['handicap'] ?? '');
                    $color = match ($sst) { 'won' => 'text-emerald-400', 'lost' => 'text-rose-400', 'pending' => 'text-amber-400', default => 'text-[var(--site-muted)]' };
                    $league = trim(($s['country_name'] ?? '').' · '.($s['competition_name'] ?? ''), ' ·');
                    $time = isset($s['match_time']) && is_numeric($s['match_time']) ? display_clock((int) $s['match_time'], 'd.m H:i') : null;
                @endphp
                <div class="grid gap-1 px-4 py-3 md:grid-cols-[minmax(0,1fr)_auto] md:items-center md:gap-4 {{ $i > 0 ? 'border-t border-[var(--site-line)]' : '' }}">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-[var(--site-text)]">{{ ($s['home_name'] ?? '').' - '.($s['away_name'] ?? '') }}</p>
                        <p class="text-xs text-[var(--site-muted)]">
                            {{ $league }}@if ($time) · {{ $time }}@endif
                            @if ($atBet) · {{ __('panel.tipo_at_bet') }}: {{ $atBet }}@endif
                            @if ($score) · {{ __('panel.tipo_score') }}: <b class="text-[var(--site-text)]">{{ $score }}</b>@endif
                        </p>
                    </div>
                    <div class="flex items-center gap-3 text-sm md:justify-end">
                        <span class="min-w-0 truncate text-[var(--site-muted)]">{{ $s['market_name'] ?? '' }}: <b class="text-[var(--site-text)]">{{ ($s['selection_name'] ?? '').($handicap !== '' && $handicap !== '0' ? ' ('.$handicap.')' : '') }}</b></span>
                        <span class="font-numeric font-bold text-[var(--site-text)]">{{ isset($s['odds']) ? number_format((float) $s['odds'], 2, ',', '.') : '' }}</span>
                        <span class="shrink-0 text-xs font-bold {{ $color }}">{{ __('sport.coupon.statuses.'.$sst) }}</span>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
