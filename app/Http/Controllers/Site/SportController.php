<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\SportFixture;
use App\Models\SportLeague;
use App\Models\SportOdd;
use App\Services\Sport\CouponBook;
use App\Services\Sport\MarginEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SportController extends Controller
{
    public function index(Request $request): View
    {
        $filter = (string) $request->query('when', 'all');
        $query = SportFixture::query()
            ->with(['league.country', 'home', 'away', 'odds.market'])
            ->whereHas('league', fn ($q) => $q->where('is_active', true))
            ->whereHas('odds')
            ->where('starts_at', '>=', now()->utc()->startOfDay())
            ->where('starts_at', '<', now()->utc()->addDays(3)->endOfDay())
            ->orderBy('starts_at');

        if ($filter === 'today') {
            $query->whereBetween('starts_at', [now()->utc()->startOfDay(), now()->utc()->endOfDay()]);
        } elseif ($filter === 'tomorrow') {
            $query->whereBetween('starts_at', [now()->utc()->addDay()->startOfDay(), now()->utc()->addDay()->endOfDay()]);
        } elseif ($filter === '3h') {
            $query->whereBetween('starts_at', [now(), now()->addHours(3)]);
        }

        if ($request->filled('q')) {
            $term = '%'.$request->string('q').'%';
            $query->where(function ($inner) use ($term): void {
                $inner->whereHas('home', fn ($q) => $q->where('name', 'like', $term))
                    ->orWhereHas('away', fn ($q) => $q->where('name', 'like', $term))
                    ->orWhereHas('league', fn ($q) => $q->where('name', 'like', $term));
            });
        }

        if ($request->filled('league')) {
            $query->where('league_id', $request->integer('league'));
        }

        $market = $this->marketFilter($request);

        return view('site.sport.index', [
            'fixtures' => $query->limit(200)->get()->groupBy('league_id'),
            ...$this->sportFrame($request),
            'market' => $market,
            'columns' => $this->bulletinColumns($market),
            'cardColumns' => $this->cardColumns($market),
        ]);
    }

    public function show(Request $request, SportFixture $fixture): View
    {
        $fixture->load(['league.country', 'home', 'away', 'odds.market']);

        return view('site.sport.show', [
            'fixture' => $fixture,
            ...$this->sportFrame($request),
            'detailTab' => $this->detailTab($request),
        ]);
    }

    public function add(Request $request, SportOdd $odd, CouponBook $coupon): RedirectResponse
    {
        abort_if($odd->suspended, 422);
        $coupon->add($odd);

        return back();
    }

    public function update(Request $request, CouponBook $coupon): RedirectResponse
    {
        $coupon->update((string) $request->input('stake', ''), $request->boolean('accept'), (string) $request->input('mode', 'combo'));

        return back();
    }

    public function remove(SportOdd $odd, CouponBook $coupon): RedirectResponse
    {
        $coupon->remove($odd->id);

        return back();
    }

    public function clear(CouponBook $coupon): RedirectResponse
    {
        $coupon->clear();

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function couponView(Request $request): array
    {
        $coupon = app(CouponBook::class)->get();
        $ids = collect($coupon['selections'])->pluck('odd_id');
        $odds = SportOdd::query()->with(['fixture.home', 'fixture.away', 'fixture.league', 'market'])->whereIn('id', $ids)->get()->keyBy('id');
        $superadminId = $request->user()?->superadmin_id;
        $margins = app(MarginEngine::class);
        $rows = [];
        $total = '1.00';
        $warnings = [];

        foreach ($coupon['selections'] as $selection) {
            $odd = $odds->get($selection['odd_id']);
            if ($odd === null) {
                continue;
            }
            $shown = $margins->show((string) $odd->raw_odd, $odd->fixture, $odd->market->code, $superadminId);
            if ($odd->suspended) {
                $warnings[] = 'suspended';
            } elseif (bccomp($shown, (string) $selection['shown'], 2) !== 0) {
                $warnings[] = 'changed';
            }
            $total = bcmul($total, $shown, 2);
            $rows[] = ['odd' => $odd, 'shown' => $shown, 'saved' => $selection['shown']];
        }

        $stake = is_numeric($coupon['stake']) ? bcadd((string) $coupon['stake'], '0', 2) : '0.00';
        $currency = $request->user()?->currency->value ?? 'TRY';

        return [
            'rows' => $rows,
            'stake' => $coupon['stake'],
            'accept' => $coupon['accept'],
            'mode' => $coupon['mode'],
            'total' => $rows === [] ? '0.00' : $total,
            'payout' => bcmul($stake, $rows === [] ? '0' : $total, 2),
            'warnings' => array_unique($warnings),
            'currency' => $currency,
            'quick' => $currency === 'TRY' ? [50, 100, 250, 500, 1000] : [10, 25, 50, 100, 250],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sportFrame(Request $request): array
    {
        return [
            'coupon' => $this->couponView($request),
            'liveFixtures' => SportFixture::query()
                ->with(['home', 'away'])
                ->whereNotIn('status', [
                    ...config('football.open_statuses'),
                    'FT', 'AET', 'PEN', 'CANC', 'PST', 'ABD', 'AWD', 'WO',
                ])
                ->orderBy('starts_at')
                ->limit(16)
                ->get(),
            'leagues' => SportLeague::query()->with('country')->withCount(['fixtures as bulletin_count' => function ($query): void {
                $query->where('starts_at', '>=', now()->utc()->startOfDay())
                    ->where('starts_at', '<', now()->utc()->addDays(3)->endOfDay())
                    ->whereHas('odds');
            }])->where('is_active', true)->orderByDesc('is_featured')->orderBy('sort_order')->get(),
            'footballCount' => SportFixture::query()
                ->whereHas('league', fn ($q) => $q->where('is_active', true))
                ->whereHas('odds')
                ->where('starts_at', '>=', now()->utc()->startOfDay())
                ->where('starts_at', '<', now()->utc()->addDays(3)->endOfDay())
                ->count(),
        ];
    }

    private function marketFilter(Request $request): string
    {
        $filter = (string) $request->query('market', 'result');

        return in_array($filter, ['result', 'half', 'btts', 'ou'], true) ? $filter : 'result';
    }

    private function detailTab(Request $request): string
    {
        $tab = (string) $request->query('tab', 'result');

        return match ($tab) {
            'goals' => 'ou',
            'all' => 'result',
            default => in_array($tab, ['result', 'half', 'btts', 'ou'], true) ? $tab : 'result',
        };
    }

    /**
     * @return list<array{market: string, outcome: string, head: string}>
     */
    private function bulletinColumns(string $filter): array
    {
        return match ($filter) {
            'half' => $this->columnSet('HT1X2', ['home', 'draw', 'away']),
            'btts' => [
                ['market' => 'BTTS', 'outcome' => 'yes', 'head' => __('sport.col_btts_yes')],
                ['market' => 'BTTS', 'outcome' => 'no', 'head' => __('sport.col_btts_no')],
            ],
            'ou' => [
                ['market' => 'OU25', 'outcome' => 'under', 'head' => __('sport.col_ou_under')],
                ['market' => 'OU25', 'outcome' => 'over', 'head' => __('sport.col_ou_over')],
            ],
            default => [
                ...$this->columnSet('1X2', ['home', 'draw', 'away']),
                ['market' => 'OU25', 'outcome' => 'under', 'head' => __('sport.col_ou_under')],
                ['market' => 'OU25', 'outcome' => 'over', 'head' => __('sport.col_ou_over')],
                ['market' => 'BTTS', 'outcome' => 'yes', 'head' => __('sport.col_btts_yes')],
                ['market' => 'BTTS', 'outcome' => 'no', 'head' => __('sport.col_btts_no')],
            ],
        };
    }

    /**
     * @return list<array{market: string, outcome: string, head: string}>
     */
    private function cardColumns(string $filter): array
    {
        return match ($filter) {
            'half' => $this->columnSet('HT1X2', ['home', 'draw', 'away']),
            'btts' => [
                ['market' => 'BTTS', 'outcome' => 'yes', 'head' => __('sport.outcomes.yes')],
                ['market' => 'BTTS', 'outcome' => 'no', 'head' => __('sport.outcomes.no')],
            ],
            'ou' => [
                ['market' => 'OU25', 'outcome' => 'under', 'head' => __('sport.outcomes.under')],
                ['market' => 'OU25', 'outcome' => 'over', 'head' => __('sport.outcomes.over')],
            ],
            default => $this->columnSet('1X2', ['home', 'draw', 'away']),
        };
    }

    /**
     * @param  list<string>  $outcomes
     * @return list<array{market: string, outcome: string, head: string}>
     */
    private function columnSet(string $market, array $outcomes): array
    {
        return array_map(fn (string $outcome) => [
            'market' => $market,
            'outcome' => $outcome,
            'head' => __('sport.outcomes.'.$outcome),
        ], $outcomes);
    }
}
