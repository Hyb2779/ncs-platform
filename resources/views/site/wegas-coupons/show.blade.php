@extends('layouts.site')

@section('heading', __('sport.my_coupons'))

@section('content')
    @php
        $tz = auth()->user()->timezone;
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
        <div class="mt-3 grid gap-2 md:grid-cols-2">
            @foreach ($selections as $s)
                @php
                    $sst = \App\Models\TipoCoupon::statusFor($s['status_label'] ?? null);
                    $snap = is_array($s['live_snapshot'] ?? null) ? $s['live_snapshot'] : null;
                    $atBet = $snap ? trim((($snap['minutes'] ?? '') !== '' ? $snap['minutes']."' " : '').($pair($snap['home_score'] ?? null, $snap['away_score'] ?? null) ?? '')) : null;
                    $score = is_array($s['score'] ?? null) ? $pair($s['score']['home'] ?? null, $s['score']['away'] ?? null) : null;
                    $handicap = (string) ($s['handicap'] ?? '');
                    $color = match ($sst) { 'won' => 'text-emerald-400', 'lost' => 'text-rose-400', 'pending' => 'text-amber-400', default => 'text-[var(--site-muted)]' };
                @endphp
                <div class="rounded-xl bg-[var(--site-panel)] p-3">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-semibold text-[var(--site-text)]">{{ ($s['home_name'] ?? '').' - '.($s['away_name'] ?? '') }}</p>
                            <p class="text-xs text-[var(--site-muted)]">
                                {{ trim(($s['country_name'] ?? '').' · '.($s['competition_name'] ?? ''), ' ·') }}
                                @if (isset($s['match_time']) && is_numeric($s['match_time']))
                                    · {{ \Carbon\Carbon::createFromTimestamp((int) $s['match_time'])->timezone($tz)->format('d.m H:i') }}
                                @endif
                            </p>
                        </div>
                        <span class="shrink-0 text-xs font-bold {{ $color }}">{{ __('sport.coupon.statuses.'.$sst) }}</span>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-[var(--site-muted)]">
                        <span>{{ $s['market_name'] ?? '' }}: <b class="text-[var(--site-text)]">{{ ($s['selection_name'] ?? '').($handicap !== '' && $handicap !== '0' ? ' ('.$handicap.')' : '') }}</b></span>
                        <span>{{ __('panel.tipo_odds') }}: <b class="font-numeric text-[var(--site-text)]">{{ isset($s['odds']) ? number_format((float) $s['odds'], 2, ',', '.') : '' }}</b></span>
                        @if ($atBet)<span>{{ __('panel.tipo_at_bet') }}: <b class="text-[var(--site-text)]">{{ $atBet }}</b></span>@endif
                        @if ($score)<span>{{ __('panel.tipo_score') }}: <b class="text-[var(--site-text)]">{{ $score }}</b></span>@endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
