<?php

namespace App\Http\Controllers\Site;

use App\Enums\Currency;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\SportFixture;
use App\Models\SportLeague;
use App\Models\SportOdd;
use App\Services\Sport\CouponBook;
use App\Services\Sport\CouponCalculator;
use App\Services\Sport\CouponException;
use App\Services\Sport\CouponPlacer;
use App\Services\Sport\MarginEngine;
use App\Services\Sport\LiveTicker;
use App\Services\Sport\ResultBoard;
use App\Support\Money;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SportController extends Controller
{
    public function index(Request $request): View
    {
        $when = (string) $request->query('when', '');
        if (! in_array($when, ['all', 'today', 'tomorrow', '3h'], true)) {
            $when = 'today';
        }

        $query = $this->bulletinQuery();

        if ($when === 'today' && ! $request->has('when') && ! (clone $query)->whereBetween('starts_at', display_span_utc())->exists()) {
            $when = 'all';
        }

        if ($when === 'today') {
            $query->whereBetween('starts_at', display_span_utc());
        } elseif ($when === 'tomorrow') {
            $query->whereBetween('starts_at', display_span_utc(1, 1));
        } elseif ($when === '3h') {
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
        $sport = $this->sportFilter($request);
        if ($sport === '') {
            $query->whereRaw('1 = 0');
        } else {
            $query->where('sport', $sport);
        }
        $page = $query->paginate(80)->withQueryString();

        return view('site.sport.index', [
            'fixtures' => $page->getCollection()->groupBy('league_id'),
            'pages' => $page,
            ...$this->sportFrame($request, $sport),
            'market' => $market,
            'when' => $when,
            'sport' => $sport,
            'columns' => $this->bulletinColumns($market),
            'cardColumns' => $this->cardColumns($market),
        ]);
    }

    public function live(Request $request): View
    {
        $sport = $this->sportFilter($request);
        $market = $this->marketFilter($request);
        $query = \App\Services\Sport\Bulletin::liveQuery();
        if ($sport === '') {
            $query->whereRaw('1 = 0');
        } else {
            $query->where('sport', $sport);
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

        $page = $query->paginate(80)->withQueryString();
        $statuses = $this->liveStatuses();

        return view('site.sport.live', [
            'fixtures' => $page->getCollection()->groupBy('league_id'),
            'pages' => $page,
            ...$this->sportFrame($request, $sport),
            'leagues' => SportLeague::query()->with('country')->withCount(['fixtures as bulletin_count' => function ($query) use ($sport, $statuses): void {
                $query->where('sport', $sport)->whereIn('status', $statuses);
            }])->where('is_active', true)->whereHas('fixtures', function ($query) use ($sport, $statuses): void {
                $query->where('sport', $sport)->whereIn('status', $statuses);
            })->orderByDesc('bulletin_count')->limit(40)->get(),
            'sportCounts' => SportFixture::query()
                ->whereIn('status', $statuses)
                ->whereHas('league', fn ($q) => $q->where('is_active', true))
                ->selectRaw('sport, count(*) as total')
                ->groupBy('sport')
                ->pluck('total', 'sport'),
            'market' => $market,
            'sport' => $sport,
            'columns' => $this->bulletinColumns($market),
            'cardColumns' => $this->cardColumns($market),
            'liveBoard' => true,
        ]);
    }

    public function liveData(Request $request): \Illuminate\Http\JsonResponse
    {
        $ids = collect(explode(',', (string) $request->query('ids')))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->take(100);

        $fixtures = $ids->isEmpty() ? collect() : SportFixture::query()
            ->whereIn('id', $ids)
            ->get(['id', 'status', 'elapsed', 'score_home', 'score_away']);

        return response()->json([
            'fixtures' => $fixtures->mapWithKeys(fn (SportFixture $fixture) => [$fixture->id => [
                'clock' => sport_clock($fixture),
                'score' => ($fixture->score_home ?? 0).' : '.($fixture->score_away ?? 0),
                'live' => in_array((string) $fixture->status, config('sport.live_statuses'), true),
            ]]),
        ]);
    }

    public function results(Request $request, ResultBoard $board): View
    {
        $sport = $this->sportFilter($request);

        return view('site.sport.results', [
            ...$board->present($request),
            ...$this->sportFrame($request, $sport),
            'sport' => $sport,
        ]);
    }

    public function show(Request $request, SportFixture $fixture): View
    {
        abort_if(in_array((string) $fixture->sport, sport_closed($request->user()), true), 404);
        $fixture->load([
            'league.country', 'home', 'away',
            'odds' => fn ($query) => $query->where('suspended', false),
            'odds.market',
        ]);
        $open = $fixture->odds->filter(fn (SportOdd $odd) => ! $odd->suspended && ! sport_offer_closed($fixture, $odd))->values();
        $board = $open->groupBy(fn (SportOdd $odd) => $odd->group_name ?: ('code:'.$odd->market->code));
        $rank = ['home' => 1, 'draw' => 2, 'away' => 3, 'home_draw' => 1, 'home_away' => 2, 'draw_away' => 3, 'under' => 1, 'over' => 2, 'yes' => 1, 'no' => 2];
        $order = array_flip([
            'Maç Sonucu', 'Maç Kazananı', 'Çifte Şans', 'Beraberlikte İade', 'Toplam Alt/Üst', 'Handikaplı',
            'Karşılıklı Gol', 'Maç Skoru', 'İlk Yarı Sonucu', 'İlk Yarı Alt/Üst', 'İkinci Yarı Sonucu',
        ]);
        $board = $board
            ->map(fn ($rows) => $rows->sortBy(fn (SportOdd $odd) => sprintf('%08.2f-%02d-%s', (float) $odd->handicap, $rank[$odd->outcome] ?? 50, (string) $odd->selection_name))->values())
            ->sortBy(fn ($rows, $name) => sprintf('%04d-%s', $order[$name] ?? 500, $name));

        return view('site.sport.show', [
            'fixture' => $fixture,
            'board' => $board,
            ...$this->sportFrame($request, (string) ($fixture->sport ?: 'football')),
            'detailTab' => $this->detailTab($request),
        ]);
    }

    public function combo(Request $request, CouponBook $coupon): RedirectResponse
    {
        $ids = collect((array) $request->input('odds', []))->map(fn ($id) => (int) $id)->filter()->unique()->take(20);
        $odds = SportOdd::query()->with('fixture')->whereIn('id', $ids)->get();
        foreach ($odds as $odd) {
            if ($odd->suspended || sport_offer_closed($odd->fixture, $odd) || ! sport_price_open($odd->fixture, (string) $odd->shown_odd)) {
                continue;
            }
            $coupon->add($odd);
        }

        return redirect()->route('site.sport');
    }

    public function add(Request $request, SportOdd $odd, CouponBook $coupon): RedirectResponse
    {
        $odd->loadMissing('fixture');
        abort_if($odd->suspended || sport_offer_closed($odd->fixture, $odd), 422);
        abort_if(in_array((string) $odd->fixture->sport, sport_closed($request->user()), true), 422);
        abort_unless(sport_price_open($odd->fixture, (string) $odd->shown_odd), 422);
        $coupon->add($odd);

        return back();
    }

    public function update(Request $request, CouponBook $coupon): RedirectResponse|JsonResponse
    {
        $coupon->update((string) $request->input('stake', ''), $request->boolean('accept'), (string) $request->input('mode', 'combo'));

        if ($request->expectsJson()) {
            $view = $this->couponView($request);

            return response()->json([
                'total' => $view['total'],
                'payout' => $view['payout'],
            ]);
        }

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

    public function place(Request $request, CouponBook $book, CouponPlacer $placer): RedirectResponse
    {
        $slip = $book->get();
        $slip['stake'] = (string) $request->input('stake', $slip['stake']);
        $slip['accept'] = $request->boolean('accept');
        $slip['mode'] = (string) $request->input('mode', $slip['mode']);
        $book->update($slip['stake'], $slip['accept'], $slip['mode']);

        try {
            $coupons = $placer->place(
                $request->user(),
                $slip,
                (string) $request->input('idempotency_key'),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (CouponException $exception) {
            if ($exception->changed !== []) {
                $book->syncShown(collect($exception->changed)->pluck('to', 'odd_id')->all());
            }

            return back()->withErrors(['coupon' => __($exception->translationKey, $exception->replace)]);
        } catch (QueryException|DeadlockException $exception) {
            report($exception);

            return back()->withErrors(['coupon' => __('sport.errors.request')]);
        }

        $book->clear();
        $numbers = collect($coupons)->pluck('coupon_no')->implode(', ');

        return back()->with('status', __('sport.coupon.placed', ['no' => $numbers]));
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
            $rows[] = ['odd' => $odd, 'shown' => $shown, 'saved' => $selection['shown']];
        }

        $stake = is_numeric($coupon['stake']) ? (string) $coupon['stake'] : '0';
        $currency = $request->user()?->currency ?? Currency::Try;
        $prices = array_column($rows, 'shown');
        $calculator = app(CouponCalculator::class);
        $total = $calculator->total($coupon['mode'], $prices);

        return [
            'rows' => $rows,
            'stake' => $coupon['stake'],
            'accept' => $coupon['accept'],
            'mode' => $coupon['mode'],
            'total' => $total,
            'payout' => Money::format($calculator->payout($coupon['mode'], $stake, $prices), $currency),
            'warnings' => array_unique($warnings),
            'currency' => $currency->value,
            'quick' => $currency === Currency::Try ? [50, 100, 250, 500, 1000] : [10, 25, 50, 100, 250],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sportFrame(Request $request, string $sport): array
    {
        return [
            'coupon' => $this->couponView($request),
            'liveFixtures' => LiveTicker::fixtures($sport),
            'leagues' => SportLeague::query()->with('country')->withCount(['fixtures as bulletin_count' => function ($query) use ($sport): void {
                $query->where('sport', $sport)
                    ->where('starts_at', '>=', now())
                    ->where('offer_count', '>', 0);
            }])->where('is_active', true)->whereHas('fixtures', function ($query) use ($sport): void {
                $query->where('sport', $sport)
                    ->where('starts_at', '>=', now())
                    ->where('offer_count', '>', 0);
            })->orderByDesc('is_featured')->orderBy('sort_order')->limit(40)->get(),
            'lookupCoupon' => $this->lookupCoupon($request),
            'sport' => $sport,
            'sportCounts' => SportFixture::query()
                ->where('starts_at', '>=', now())
                ->where('offer_count', '>', 0)
                ->whereHas('league', fn ($q) => $q->where('is_active', true))
                ->when(sport_closed($request->user()) !== [], fn ($query) => $query->whereNotIn('sport', sport_closed($request->user())))
                ->selectRaw('sport, count(*) as total')
                ->groupBy('sport')
                ->pluck('total', 'sport'),
        ];
    }

    /** @return list<string> */
    private function liveStatuses(): array
    {
        return [...config('sport.live_statuses'), 'HT'];
    }

    private function sportFilter(Request $request): string
    {
        $open = array_values(array_diff(
            ['football', 'basketball', 'tennis', 'volleyball'],
            sport_closed($request->user()),
        ));
        $sport = (string) $request->query('sport', 'football');
        if (! in_array($sport, ['football', 'basketball', 'tennis', 'volleyball'], true)) {
            $sport = 'football';
        }

        if (in_array($sport, $open, true)) {
            return $sport;
        }

        return $open[0] ?? '';
    }

    private function lookupCoupon(Request $request): ?Coupon
    {
        $number = trim((string) $request->query('coupon_no', ''));
        $user = $request->user();
        if ($number === '' || $user === null) {
            return null;
        }

        return Coupon::query()->with(['selections.fixture.home', 'selections.fixture.away'])->where('coupon_no', $number)->where('user_id', $user->id)->first();
    }

    private function bulletinQuery(): Builder
    {
        return \App\Services\Sport\Bulletin::query();
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
