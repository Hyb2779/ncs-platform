@extends('layouts.panel')

@section('heading', __('sport.panel.cols.no').' #'.$coupon->bet_id)

@section('content')
    @php
        $tz = auth()->user()->timezone;
        $currency = \App\Enums\Currency::tryFrom((string) $coupon->currency) ?? auth()->user()->currency;
        $selections = is_array($coupon->detail['selections'] ?? null) ? $coupon->detail['selections'] : [];
        $pair = fn ($h, $a) => ($h === null || $a === null) ? null : $h.' - '.$a;
    @endphp
    <div class="mb-4 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <x-panel.stat :label="__('sport.panel.cols.user')" :value="(string) $coupon->user?->username" />
        <x-panel.stat :label="__('sport.panel.cols.status')" :value="__('sport.coupon.statuses.'.$coupon->panelStatus()).' · '.$coupon->won_count.'/'.$coupon->selection_count" />
        <x-panel.stat :label="__('sport.panel.cols.stake')" :value="\App\Support\Money::format((string) $coupon->stake, $currency)" />
        <x-panel.stat :label="__('sport.panel.cols.total')" :value="number_format((float) $coupon->total_odds, 2, ',', '.')" />
        <x-panel.stat :label="__('sport.panel.cols.win')" :value="\App\Support\Money::format((string) $coupon->potential_win, $currency)" />
        <x-panel.stat :label="__('panel.tipo_payout')" :value="\App\Support\Money::format((string) $coupon->payout, $currency)" />
    </div>
    <p class="mb-3 text-sm text-slate-500">
        {{ $coupon->placed_at?->timezone($tz)->format('d.m.Y H:i') }}
        · {{ in_array($coupon->type, ['combo', 'single'], true) ? __('sport.coupon.'.$coupon->type) : $coupon->type }}
    </p>
    @if ($selections === [])
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">{{ __('panel.tipo_detail_unavailable') }}</div>
    @else
        @php
            $rows = [];
            foreach ($selections as $s) {
                $snap = is_array($s['live_snapshot'] ?? null) ? $s['live_snapshot'] : null;
                $atBet = $snap ? trim((($snap['minutes'] ?? '') !== '' ? $snap['minutes']."' " : '').($pair($snap['home_score'] ?? null, $snap['away_score'] ?? null) ?? '')) : null;
                $score = is_array($s['score'] ?? null) ? $pair($s['score']['home'] ?? null, $s['score']['away'] ?? null) : null;
                $handicap = (string) ($s['handicap'] ?? '');
                $rows[] = [
                    'match' => new \Illuminate\Support\HtmlString(
                        '<p class="font-medium">'.e(($s['home_name'] ?? '').' - '.($s['away_name'] ?? '')).'</p>'
                        .'<p class="text-xs text-slate-500">'.e(trim(($s['country_name'] ?? '').' · '.($s['competition_name'] ?? ''), ' ·')).'</p>'
                        .(isset($s['match_time']) && is_numeric($s['match_time']) ? '<p class="text-xs text-slate-500">'.e(\Carbon\Carbon::createFromTimestamp((int) $s['match_time'])->timezone($tz)->format('d.m.Y H:i')).'</p>' : '')
                    ),
                    'market' => (string) ($s['market_name'] ?? ''),
                    'pick' => (string) ($s['selection_name'] ?? '').($handicap !== '' && $handicap !== '0' ? ' ('.$handicap.')' : ''),
                    'odds' => isset($s['odds']) ? number_format((float) $s['odds'], 2, ',', '.') : '',
                    'status' => __('sport.coupon.statuses.'.\App\Models\TipoCoupon::statusFor($s['status_label'] ?? null)),
                    'at_bet' => $atBet ?: '—',
                    'score' => $score ?? '—',
                ];
            }
        @endphp
        <x-panel.table
            :columns="[
                ['key' => 'match', 'label' => __('panel.tipo_match')],
                ['key' => 'pick', 'label' => __('panel.tipo_pick')],
                ['key' => 'odds', 'label' => __('panel.tipo_odds')],
                ['key' => 'status', 'label' => __('sport.panel.cols.status')],
                ['key' => 'market', 'label' => __('panel.tipo_market'), 'priority' => 'detail'],
                ['key' => 'at_bet', 'label' => __('panel.tipo_at_bet'), 'priority' => 'detail'],
                ['key' => 'score', 'label' => __('panel.tipo_score'), 'priority' => 'detail'],
            ]"
            :rows="$rows"
        />
    @endif
    <a class="mt-4 inline-flex h-11 items-center rounded-lg border px-3 text-sm" href="{{ route('panel.coupons.index') }}">{{ __('panel.tipo_back') }}</a>
@endsection
