<?php

namespace App\Services;

use App\Enums\Currency;
use App\Models\CasinoGame;
use App\Models\SportFixture;
use App\Models\User;
use App\Services\Sport\Bulletin;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Ana sayfa (vitrin) verisi. */
class HomeFeed
{
    public function build(?User $viewer): array
    {
        $now = now()->utc();
        $zone = $viewer?->timezone ?? 'UTC';

        $candidates = Bulletin::query()
            ->where('starts_at', '>', $now->copy()->addMinutes(10))
            ->limit(80)
            ->get();

        $picks = DB::table('coupon_selections')
            ->whereIn('fixture_id', $candidates->pluck('id'))
            ->where('created_at', '>=', $now->copy()->subDay())
            ->selectRaw('fixture_id, COUNT(*) AS c')
            ->groupBy('fixture_id')
            ->pluck('c', 'fixture_id');

        $withOdds = $candidates->filter(fn (SportFixture $f) => $this->odd($f, '1X2', 'home') && $this->odd($f, '1X2', 'draw') && $this->odd($f, '1X2', 'away'));
        $popular = $withOdds->sortBy([fn ($a, $b) => ($picks[$b->id] ?? 0) <=> ($picks[$a->id] ?? 0), fn ($a, $b) => $a->starts_at <=> $b->starts_at])->values()->take(4);

        return [
            'featured' => $popular->first() ? $this->match($popular->first(), $zone) : null,
            'quick' => [
                'live' => SportFixture::query()->inPlay()->count(),
                'today' => Bulletin::query()->whereBetween('starts_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()])->count(),
                'slots' => app(\App\Services\Casino\GameAvailability::class)->apply(CasinoGame::query()->where('is_active', true)->where('is_live', false)
                    ->where(fn ($q) => $q->whereNull('category')->orWhereNotIn('category', ['mini', 'virtual'])), $viewer)->count(), // lobideki slot kurali: mini haric
                'mini' => app(\App\Services\Casino\GameAvailability::class)->apply(CasinoGame::query()->where('is_active', true)->where('category', 'mini'), $viewer)->count(),
                'casino' => app(\App\Services\Casino\GameAvailability::class)->apply(CasinoGame::query()->where('is_active', true)->where('is_live', true), $viewer)->count(),
            ],
            'upcoming' => $candidates->take(5)->map(fn ($f) => $this->match($f, $zone))->values(),
            'popular' => $popular->map(fn ($f) => $this->match($f, $zone))->values(),
            'combo' => $this->combo($candidates, $zone),
            'winners' => $this->winners($viewer),
        ];
    }

    private function odd(SportFixture $fixture, string $market, string $outcome)
    {
        return $fixture->odds->first(fn ($o) => $o->market?->code === $market && $o->outcome === $outcome && ! $o->suspended);
    }

    private function match(SportFixture $f, string $zone): array
    {
        return [
            'id' => $f->id,
            'time' => sport_digits($f->starts_at->timezone($zone)->format('H:i')),
            'home' => sport_name($f->home),
            'away' => sport_name($f->away),
            'league' => sport_name($f->league),
            'o1' => $this->odd($f, '1X2', 'home'),
            'ox' => $this->odd($f, '1X2', 'draw'),
            'o2' => $this->odd($f, '1X2', 'away'),
        ];
    }

    private function combo(Collection $candidates, string $zone): ?array
    {
        $rows = [];
        foreach ($candidates as $f) {
            $choices = collect([$this->odd($f, '1X2', 'home'), $this->odd($f, '1X2', 'away')])
                ->filter()
                ->filter(fn ($o) => (float) $o->shown_odd >= 1.25 && (float) $o->shown_odd <= 1.90)
                ->sortBy(fn ($o) => (float) $o->shown_odd);
            $pick = $choices->first();
            if ($pick === null) {
                $over = $this->odd($f, 'OU25', 'over');
                $pick = ($over && (float) $over->shown_odd >= 1.25 && (float) $over->shown_odd <= 1.90) ? $over : null;
            }
            if ($pick === null || ! sport_price_open($f, (string) $pick->shown_odd)) {
                continue;
            }
            $key = $pick->market->code === 'OU25' ? 'pick_over' : ($pick->outcome === 'home' ? 'pick_home' : 'pick_away');
            $rows[] = ['odd' => $pick, 'match' => $this->match($f, $zone), 'label' => __('home.'.$key)];
            if (count($rows) === 4) {
                break;
            }
        }
        if (count($rows) < 2) {
            return null;
        }
        $total = '1';
        foreach ($rows as $r) {
            $total = bcmul($total, (string) $r['odd']->shown_odd, 4);
        }

        return ['rows' => $rows, 'total' => number_format(round((float) $total, 2), 2, '.', '')];
    }

    private function winners(?User $viewer): array
    {
        if ($viewer === null || $viewer->superadmin_id === null) {
            return [];
        }

        return DB::table('wallet_transactions as t')
            ->join('wallets as w', 'w.id', '=', 't.wallet_id')
            ->join('users as u', 'u.id', '=', 'w.user_id')
            ->where('t.type', 'win')
            ->whereIn('t.product', ['slot', 'sport', 'live_casino'])
            ->where('u.superadmin_id', $viewer->superadmin_id)
            ->where('u.role', 'uye')
            ->where('t.created_at', '>=', now()->subDays(2))
            ->orderByDesc('t.amount')
            ->limit(6)
            ->get(['u.username', 't.amount', 't.product', 'w.currency'])
            ->map(fn ($r) => [
                'user' => mb_substr($r->username, 0, 2).'***'.mb_substr($r->username, -2),
                'product' => __('home.product_'.$r->product),
                'amount' => \App\Support\Money::format((string) $r->amount, Currency::from($r->currency)),
            ])->all();
    }
}
