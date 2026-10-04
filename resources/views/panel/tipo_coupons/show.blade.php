@extends('layouts.panel')

@section('heading', __('sport.panel.cols.no').' #'.$coupon->bet_id)

@section('content')
    @php
        $tz = auth()->user()->timezone;
        $currency = \App\Enums\Currency::tryFrom((string) $coupon->currency) ?? auth()->user()->currency;
        $m = fn ($v) => \App\Support\Money::format((string) $v, $currency);
        $selections = is_array($coupon->detail['selections'] ?? null) ? $coupon->detail['selections'] : [];
        $pair = fn ($h, $a) => ($h === null || $a === null) ? null : $h.' - '.$a;
        $solid = fn (string $s) => match ($s) {
            'won' => 'bg-emerald-600 text-white',
            'lost' => 'bg-rose-600 text-white',
            'pending' => 'bg-amber-500 text-white',
            default => 'bg-slate-400 text-white',
        };
        $now = now()->timestamp;
        $rows = [];
        foreach ($selections as $s) {
            $sst = \App\Models\TipoCoupon::statusFor($s['status_label'] ?? null);
            $snap = is_array($s['live_snapshot'] ?? null) ? $s['live_snapshot'] : null;
            $htRaw = null;
            foreach (['score_ht', 'ht_score', 'half_time_score'] as $k) {
                if (is_array($s[$k] ?? null)) { $htRaw = $s[$k]; break; }
            }
            $mt = isset($s['match_time']) && is_numeric($s['match_time']) ? (int) $s['match_time'] : null;
            $handicap = (string) ($s['handicap'] ?? '');
            $rows[] = [
                'match' => ($s['home_name'] ?? '').' - '.($s['away_name'] ?? ''),
                'league' => trim(($s['country_name'] ?? '').' · '.($s['competition_name'] ?? ''), ' ·'),
                'time' => $mt ? \Carbon\Carbon::createFromTimestamp($mt)->timezone($tz)->format('d.m H:i') : null,
                'market' => (string) ($s['market_name'] ?? ''),
                'pick' => (string) ($s['selection_name'] ?? '').($handicap !== '' && $handicap !== '0' ? ' ('.$handicap.')' : ''),
                'odds' => isset($s['odds']) ? number_format((float) $s['odds'], 2, ',', '.') : '',
                'ht' => $htRaw ? $pair($htRaw['home'] ?? null, $htRaw['away'] ?? null) : null,
                'ft' => is_array($s['score'] ?? null) ? $pair($s['score']['home'] ?? null, $s['score']['away'] ?? null) : null,
                'at_bet' => $snap ? trim((($snap['minutes'] ?? '') !== '' ? $snap['minutes']."' " : '').($pair($snap['home_score'] ?? null, $snap['away_score'] ?? null) ?? '')) : null,
                'status' => $sst,
                'state' => $sst !== 'pending' ? 'done' : (($mt !== null && $now >= $mt) ? 'live' : 'upcoming'),
            ];
        }
        $st = $coupon->panelStatus();
        $net = $coupon->isSettled() ? (float) $coupon->payout - (float) $coupon->stake : null;
        $type = in_array($coupon->type, ['combo', 'single'], true) ? __('sport.coupon.'.$coupon->type) : $coupon->type;
        $info = [
            [__('panel.tipo_owner'), $coupon->user?->username ?? '-'],
            [__('panel.tipo_played_at'), $coupon->placed_at?->timezone($tz)->format('d.m.Y H:i') ?? '-'],
            [__('sport.panel.cols.type'), $type.' ('.$coupon->won_count.'/'.$coupon->selection_count.')'],
            [__('sport.panel.cols.stake'), $m($coupon->stake)],
            [__('sport.panel.cols.total'), number_format((float) $coupon->total_odds, 2, ',', '.')],
            [__('sport.panel.cols.win'), $m($coupon->potential_win)],
            [__('panel.tipo_payout'), $m($coupon->payout)],
            [__('panel.tipo_net'), $net === null ? '-' : $m($net)],
        ];
    @endphp

    <h2 class="mb-2 text-sm font-bold">{{ __('panel.tipo_bets') }}</h2>
    @if ($rows === [])
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">{{ __('panel.tipo_detail_unavailable') }}</div>
    @else
        <div class="mb-5 hidden overflow-x-auto rounded-lg border border-[#E3E6EB] bg-white md:block">
            <table class="w-full text-sm">
                <thead class="bg-[#F3F4F6] text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-3 py-2 text-start">{{ __('panel.tipo_match') }}</th>
                        <th class="px-3 py-2 text-start">{{ __('panel.tipo_market') }}</th>
                        <th class="px-3 py-2 text-start">{{ __('panel.tipo_pick_col') }}</th>
                        <th class="px-3 py-2 text-end">{{ __('panel.tipo_odds') }}</th>
                        <th class="px-3 py-2 text-start">{{ __('panel.tipo_detail_col') }}</th>
                        <th class="px-3 py-2 text-start">{{ __('sport.panel.cols.status') }}</th>
                        <th class="px-3 py-2 text-start">{{ __('panel.tipo_match_state') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $r)
                        <tr class="border-t border-[#E3E6EB] align-top">
                            <td class="px-3 py-2">
                                <p class="font-semibold">{{ $r['match'] }}</p>
                                <p class="text-xs text-slate-500">{{ $r['league'] }}@if ($r['time']) · {{ $r['time'] }}@endif</p>
                            </td>
                            <td class="px-3 py-2 text-slate-600">{{ $r['market'] }}</td>
                            <td class="px-3 py-2 font-semibold">{{ $r['pick'] }}</td>
                            <td class="px-3 py-2 text-end font-numeric font-semibold">{{ $r['odds'] }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-xs">
                                {{ __('panel.tipo_ht') }} <b>{{ $r['ht'] ?? '-' }}</b> · {{ __('panel.tipo_ft') }} <b>{{ $r['ft'] ?? '-' }}</b>
                                @if ($r['at_bet'])<p class="text-slate-500">{{ __('panel.tipo_at_bet') }}: {{ $r['at_bet'] }}</p>@endif
                            </td>
                            <td class="px-3 py-2"><span class="rounded px-2 py-0.5 text-xs font-bold {{ $solid($r['status']) }}">{{ __('sport.coupon.statuses.'.$r['status']) }}</span></td>
                            <td class="px-3 py-2 text-xs">{{ __('panel.tipo_state_'.$r['state']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mb-5 overflow-hidden rounded-lg border border-[#E3E6EB] bg-white md:hidden">
            @foreach ($rows as $i => $r)
                <div class="px-3 py-2.5 {{ $i > 0 ? 'border-t border-[#E3E6EB]' : '' }}">
                    <div class="flex items-start justify-between gap-2">
                        <p class="min-w-0 text-sm font-semibold">{{ $r['match'] }}</p>
                        <span class="shrink-0 rounded px-2 py-0.5 text-xs font-bold {{ $solid($r['status']) }}">{{ __('sport.coupon.statuses.'.$r['status']) }}</span>
                    </div>
                    <p class="text-xs text-slate-500">{{ $r['league'] }}@if ($r['time']) · {{ $r['time'] }}@endif · {{ __('panel.tipo_state_'.$r['state']) }}</p>
                    <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-sm">
                        <span class="text-slate-500">{{ $r['market'] }}: <b class="font-semibold">{{ $r['pick'] }}</b></span>
                        <span class="font-numeric font-semibold">{{ $r['odds'] }}</span>
                        <span class="text-xs">{{ __('panel.tipo_ht') }} <b>{{ $r['ht'] ?? '-' }}</b> · {{ __('panel.tipo_ft') }} <b>{{ $r['ft'] ?? '-' }}</b></span>
                    </div>
                    @if ($r['at_bet'])<p class="text-xs text-slate-500">{{ __('panel.tipo_at_bet') }}: {{ $r['at_bet'] }}</p>@endif
                </div>
            @endforeach
        </div>
    @endif

    <h2 class="mb-2 text-sm font-bold">{{ __('panel.tipo_coupon_info') }}</h2>
    <div class="hidden overflow-x-auto rounded-lg border border-[#E3E6EB] bg-white md:block">
        <table class="w-full text-sm">
            <thead class="bg-[#F3F4F6] text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    @foreach ($info as [$label, $value])
                        <th class="whitespace-nowrap px-3 py-2 text-start">{{ $label }}</th>
                    @endforeach
                    <th class="px-3 py-2 text-start">{{ __('sport.panel.cols.status') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr class="border-t border-[#E3E6EB]">
                    @foreach ($info as $idx => [$label, $value])
                        <td class="whitespace-nowrap px-3 py-2 {{ $idx >= 3 ? 'font-numeric font-semibold' : '' }}">
                            @if ($idx === 0 && $coupon->user)
                                <a class="underline" href="{{ route('panel.users.show', $coupon->user_id) }}">{{ $value }}</a>
                            @else
                                {{ $value }}
                            @endif
                        </td>
                    @endforeach
                    <td class="px-3 py-2"><span class="rounded px-2 py-0.5 text-xs font-bold {{ $solid($st) }}">{{ __('sport.coupon.statuses.'.$st) }}</span></td>
                </tr>
            </tbody>
        </table>
    </div>
    <div class="grid grid-cols-2 gap-2 md:hidden">
        @foreach ($info as $idx => [$label, $value])
            <div class="rounded-lg border border-[#E3E6EB] bg-white px-3 py-2">
                <p class="text-xs text-slate-500">{{ $label }}</p>
                <p class="truncate text-sm font-semibold">{{ $value }}</p>
            </div>
        @endforeach
        <div class="rounded-lg border border-[#E3E6EB] bg-white px-3 py-2">
            <p class="text-xs text-slate-500">{{ __('sport.panel.cols.status') }}</p>
            <span class="rounded px-2 py-0.5 text-xs font-bold {{ $solid($st) }}">{{ __('sport.coupon.statuses.'.$st) }}</span>
        </div>
    </div>

    <a class="mt-4 inline-flex h-11 items-center rounded-lg border px-3 text-sm" href="{{ route('panel.coupons.index') }}">{{ __('panel.tipo_back') }}</a>
@endsection
