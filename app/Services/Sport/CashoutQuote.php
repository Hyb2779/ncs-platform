<?php

namespace App\Services\Sport;

use App\Models\Coupon;
use App\Models\CouponSelection;
use App\Models\SportFixture;
use App\Models\SportOdd;

/** Bekleyen kuponun bozdurma tutarı. Fiyat yoksa, bayatsa veya bacak kayıpsa teklif yok. */
class CashoutQuote
{
    public function __construct(
        private readonly SelectionEvaluator $evaluator,
        private readonly OfferEvaluator $offers,
    ) {}

    public function quote(Coupon $coupon): ?string
    {
        if ($coupon->status !== 'pending') {
            return null;
        }

        $coupon->loadMissing(['selections.fixture']);
        $factor = '1';
        $open = 0;

        foreach ($coupon->selections as $selection) {
            $fixture = $selection->fixture;
            if ($fixture === null) {
                return null;
            }

            $odd = $this->current($selection);
            $state = $this->state($selection, $fixture, $odd);
            if ($state === 'lost') {
                return null;
            }
            if ($state === 'void') {
                continue;
            }
            if ($state === 'won') {
                $factor = bcmul($factor, $this->normalize((string) $selection->odds), 8);

                continue;
            }
            if (sport_live_clock_closed($fixture) || $odd === null || sport_over_goal_closed($fixture, $odd) || ! $this->fresh($fixture, $odd)) {
                return null;
            }

            $current = $this->normalize((string) $odd->shown_odd);
            if (bccomp($current, '1.01', 2) === -1) {
                return null;
            }

            $open++;
            $factor = bcmul($factor, bcdiv($this->normalize((string) $selection->odds), $current, 8), 8);
        }

        if ($open === 0) {
            return null;
        }

        $keep = $this->normalize((string) config('sport.cashout_keep', '0.90'));
        $amount = $this->round2(bcmul(bcmul($this->normalize((string) $coupon->stake), $factor, 8), $keep, 8));
        $cap = bcsub($this->normalize((string) $coupon->potential_win), '0.01', 2);
        if (bccomp($amount, $cap, 2) === 1) {
            $amount = $cap;
        }
        if (bccomp($amount, '0.01', 2) === -1) {
            return null;
        }

        return $amount;
    }

    private function current(CouponSelection $selection): ?SportOdd
    {
        return SportOdd::query()
            ->where('fixture_id', $selection->fixture_id)
            ->where('outcome', $selection->outcome)
            ->whereHas('market', fn ($query) => $query->where('code', $selection->market_code))
            ->orderByDesc('quoted_at')
            ->first();
    }

    private function state(CouponSelection $selection, SportFixture $fixture, ?SportOdd $odd): string
    {
        if (in_array($selection->status, ['won', 'lost', 'void'], true)) {
            return $selection->status;
        }

        if (in_array($fixture->status, ['FT', 'AET', 'PEN'], true)) {
            $result = $this->evaluator->evaluate(
                $selection->market_code,
                $selection->outcome,
                $fixture->ft_home,
                $fixture->ft_away,
                $fixture->ht_home !== null ? (int) $fixture->ht_home : null,
                $fixture->ht_away !== null ? (int) $fixture->ht_away : null,
            );
            if ($result === null && $selection->market_code === 'BOOK') {
                $result = $this->offers->evaluate($odd, $fixture);
            }

            return $result ?? 'open';
        }

        return $this->locked($selection, $fixture, $odd) ?? 'open';
    }

    /** Canlı skorda geri dönmeyen sonuç. 1X2 gibi bitiş bekleyen market açık kalır. */
    private function locked(CouponSelection $selection, SportFixture $fixture, ?SportOdd $odd): ?string
    {
        if (! $this->inPlay($fixture)) {
            return null;
        }

        $score = $this->board($fixture);
        if ($score === null) {
            return null;
        }

        $code = $selection->market_code;
        if (isset(['OU15' => '1.5', 'OU25' => '2.5', 'OU35' => '3.5'][$code])) {
            return $this->ou($selection->outcome, $score[0] + $score[1], ['OU15' => '1.5', 'OU25' => '2.5', 'OU35' => '3.5'][$code]);
        }
        if ($code === 'BTTS') {
            return $this->both($selection->outcome, $score);
        }
        if ($code === 'HT1X2' && $this->halfOver($fixture)) {
            return $this->evaluator->evaluate(
                'HT1X2',
                $selection->outcome,
                null,
                null,
                $fixture->ht_home !== null ? (int) $fixture->ht_home : null,
                $fixture->ht_away !== null ? (int) $fixture->ht_away : null,
            );
        }
        if ($odd === null || $code !== 'BOOK') {
            return null;
        }

        return $this->book($odd, $fixture, $score);
    }

    /** @param  array{0: int, 1: int}  $score */
    private function book(SportOdd $odd, SportFixture $fixture, array $score): ?string
    {
        $group = (string) $odd->group_name;
        $pick = (string) $odd->selection_name;
        $line = (string) $odd->handicap;
        $half = str_starts_with($group, 'İlk Yarı');
        $base = $half && $this->halfOver($fixture) ? $this->pair($fixture->ht_home, $fixture->ht_away) : $score;
        if ($half && $base === null) {
            return null;
        }
        $base ??= $score;

        if (in_array($group, ['Toplam Alt/Üst', 'İlk Yarı Alt/Üst', 'İlk Yarı Toplam Alt/Üst'], true)) {
            return $this->ou($pick, $base[0] + $base[1], $line);
        }
        if (in_array($group, ['Ev Sahibi Toplam Alt/Üst', 'İlk Yarı Ev Sahibi Toplam Alt/Üst'], true)) {
            return $this->ou($pick, $base[0], $line);
        }
        if (in_array($group, ['Deplasman Toplam Alt/Üst', 'İlk Yarı Deplasman Toplam Alt/Üst'], true)) {
            return $this->ou($pick, $base[1], $line);
        }
        if (in_array($group, ['Karşılıklı Gol', 'İlk Yarı Karşılıklı Gol'], true)) {
            return $this->both($pick, $base);
        }
        if (in_array($group, ['Maç Skoru', 'İlk Yarı Skoru'], true) && preg_match('/^(\d+)\s*[:\-]\s*(\d+)$/', $pick, $found) === 1) {
            if ($group === 'İlk Yarı Skoru' && $this->halfOver($fixture)) {
                return ((int) $found[1] === $base[0] && (int) $found[2] === $base[1]) ? 'won' : 'lost';
            }
            if ($base[0] > (int) $found[1] || $base[1] > (int) $found[2]) {
                return 'lost';
            }
        }

        return null;
    }

    private function ou(string $pick, int $goals, string $line): ?string
    {
        if (bccomp((string) $goals, $line, 2) !== 1) {
            return null;
        }

        return match ($pick) {
            'over', 'Üst' => 'won',
            'under', 'Alt' => 'lost',
            default => null,
        };
    }

    /** @param  array{0: int, 1: int}  $score */
    private function both(string $pick, array $score): ?string
    {
        if ($score[0] < 1 || $score[1] < 1) {
            return null;
        }

        return match ($pick) {
            'yes', 'Var', 'Evet' => 'won',
            'no', 'Yok', 'Hayır' => 'lost',
            default => null,
        };
    }

    private function fresh(SportFixture $fixture, SportOdd $odd): bool
    {
        if ($odd->suspended || $odd->quoted_at === null) {
            return false;
        }
        if ($fixture->starts_at !== null && $fixture->starts_at->lte(now()) && ! $this->inPlay($fixture)) {
            return false;
        }

        $seconds = $this->inPlay($fixture)
            ? (int) config('sport.cashout_live_seconds', 90)
            : (int) config('sport.cashout_prematch_seconds', 600);

        return $odd->quoted_at->greaterThanOrEqualTo(now()->subSeconds($seconds));
    }

    private function inPlay(SportFixture $fixture): bool
    {
        return in_array($fixture->status, [...config('sport.live_statuses'), 'HT'], true);
    }

    private function halfOver(SportFixture $fixture): bool
    {
        return in_array($fixture->status, ['HT', '2H', 'ET', 'P', 'BT', 'FT', 'AET', 'PEN'], true);
    }

    /** @return array{0: int, 1: int}|null */
    private function board(SportFixture $fixture): ?array
    {
        return $this->pair($fixture->score_home, $fixture->score_away);
    }

    /** @return array{0: int, 1: int}|null */
    private function pair(mixed $home, mixed $away): ?array
    {
        if ($home === null || $away === null || ! is_numeric($home) || ! is_numeric($away)) {
            return null;
        }

        return [(int) $home, (int) $away];
    }

    private function normalize(string $value): string
    {
        return bcadd($value, '0', 8);
    }

    private function round2(string $value): string
    {
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '0');
        $fraction = str_pad(substr($fraction, 0, 8), 3, '0');
        $rounded = $whole.'.'.substr($fraction, 0, 2);
        if ((int) $fraction[2] >= 5) {
            $rounded = bcadd($rounded, '0.01', 2);
        }

        return $rounded;
    }
}
