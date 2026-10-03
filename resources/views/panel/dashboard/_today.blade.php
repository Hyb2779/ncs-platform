@php
    $t = app(\App\Services\Stats\TodaySummary::class)->for(auth()->user(), $todayCurrency ?? null);
    $tCur = \App\Enums\Currency::from($t['currency']);
    $tm = fn ($v) => \App\Support\Money::format((string) $v, $tCur);
    $tCards = [
        [__('panel.today_general'), $tm($t['today_ggr']), null],
        [__('panel.week_general'), $tm($t['week_ggr']), null],
        [__('panel.today_sport_bets'), $tm($t['sport_bets']['amount']), __('panel.today_coupons', ['count' => $t['sport_bets']['count']])],
        [__('panel.today_sport_wins'), $tm($t['sport_wins']['amount']), __('panel.today_coupons', ['count' => $t['sport_wins']['count']])],
        [__('panel.today_sport_ggr'), $tm($t['sport_ggr']), null],
        [__('panel.week_sport_ggr'), $tm($t['week_sport_ggr']), null],
        [__('panel.today_casino_turnover'), $tm($t['casino_turnover']), null],
        [__('panel.today_casino_ggr'), $tm($t['casino_ggr']), null],
        [__('panel.week_casino_ggr'), $tm($t['week_casino_ggr']), null],
        [__('panel.today_players'), number_format($t['players'], 0, ',', '.'), null],
        [__('panel.week_top_winner'), $t['top_winner']['name'] ?? __('panel.today_no_record'), isset($t['top_winner']) ? $tm($t['top_winner']['net']) : null],
        [__('panel.week_top_loser'), $t['top_loser']['name'] ?? __('panel.today_no_record'), isset($t['top_loser']) ? $tm($t['top_loser']['net']) : null],
    ];
@endphp
<x-panel.card class="mb-4" :title="__('panel.today_title').' · '.$t['currency']" data-dashboard="today">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ($tCards as [$tLabel, $tValue, $tSub])
            <div class="rounded-lg border border-[#E3E6EB] bg-white p-3">
                <p class="text-xs text-slate-500">{{ $tLabel }}</p>
                <p class="mt-1 truncate font-numeric text-xl font-semibold">{{ $tValue }}</p>
                @if ($tSub)<p class="text-xs text-slate-500">{{ $tSub }}</p>@endif
            </div>
        @endforeach
    </div>
    <p class="mt-2 text-xs text-slate-500">{{ __('panel.today_note') }}</p>
</x-panel.card>
