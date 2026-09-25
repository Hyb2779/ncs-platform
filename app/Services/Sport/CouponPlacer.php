<?php

namespace App\Services\Sport;

use App\Enums\WalletProduct;
use App\Enums\WalletTransactionType;
use App\Models\Coupon;
use App\Models\CouponPlacement;
use App\Models\CouponSelection;
use App\Models\SportOdd;
use App\Models\User;
use App\Services\WalletException;
use App\Services\WalletService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

class CouponPlacer
{
    public function __construct(
        private readonly CouponCalculator $calculator,
        private readonly MarginEngine $margins,
        private readonly SportLimits $limits,
        private readonly WalletService $wallets,
    ) {}

    /**
     * @param  array{selections: list<array{odd_id: int, fixture_id: int, outcome: string, shown: string}>, stake: string, accept: bool, mode: string}  $slip
     * @return list<Coupon>
     */
    public function place(User $user, array $slip, string $clientKey, ?string $ip, ?string $device): array
    {
        $existing = $this->existing($user, $clientKey);
        if ($existing !== []) {
            return $existing;
        }

        $stake = $this->stake($slip['stake'] ?? '');
        $mode = ($slip['mode'] ?? '') === 'single' ? 'single' : 'combo';
        $accept = (bool) ($slip['accept'] ?? false);
        $rows = $this->rows($user, $slip['selections'] ?? [], $accept);
        $this->guardLimits($user, $mode, $stake, $rows);

        try {
            return $this->wallets->within(function () use ($user, $clientKey, $stake, $mode, $accept, $rows, $ip, $device) {
                $again = $this->existing($user, $clientKey);
                if ($again !== []) {
                    return $again;
                }

                CouponPlacement::query()->create(['client_key' => $clientKey, 'user_id' => $user->id]);
                $coupons = [];
                $groups = $mode === 'single' ? array_map(fn (array $row) => [$row], $rows) : [$rows];

                foreach ($groups as $index => $group) {
                    $odds = array_column($group, 'shown');
                    $total = $this->calculator->total($mode === 'single' ? 'single' : 'combo', $odds);
                    $win = $this->calculator->payout($mode === 'single' ? 'single' : 'combo', $stake, $odds);
                    $coupon = Coupon::query()->create([
                        'coupon_no' => $this->number(),
                        'user_id' => $user->id,
                        'superadmin_id' => $user->superadmin_id,
                        'client_key' => $clientKey,
                        'type' => $mode,
                        'stake' => $stake,
                        'total_odds' => $total,
                        'potential_win' => $win,
                        'status' => 'pending',
                        'accept_odds_change' => $accept,
                        'ip' => $ip,
                        'device' => $device === null ? null : Str::limit($device, 255, ''),
                        'placed_at' => now(),
                    ]);
                    foreach ($group as $row) {
                        CouponSelection::query()->create([
                            'coupon_id' => $coupon->id,
                            'fixture_id' => $row['fixture_id'],
                            'market_code' => $row['market'],
                            'outcome' => $row['outcome'],
                            'odds' => $row['shown'],
                            'raw_odds' => $row['raw'],
                            'kickoff' => $row['kickoff'],
                            'status' => 'pending',
                        ]);
                    }
                    $wallet = $user->wallet()->firstOrFail();
                    $this->wallets->debit(
                        $wallet,
                        $stake,
                        WalletTransactionType::Bet,
                        WalletProduct::Sport,
                        $clientKey.':'.$index,
                        $coupon->coupon_no,
                        null,
                        null,
                        $user,
                        $ip,
                        null,
                        $this->ledgerAt($wallet->id, $index),
                    );
                    $coupons[] = $coupon->load('selections');
                }

                return $coupons;
            });
        } catch (WalletException $exception) {
            throw new CouponException($exception->translationKey, $exception->replace);
        } catch (UniqueConstraintViolationException) {
            return $this->existing($user, $clientKey);
        }
    }

    /**
     * @param  list<array{odd_id: int, fixture_id: int, outcome: string, shown: string}>  $selections
     * @return list<array{odd_id: int, fixture_id: int, outcome: string, market: string, shown: string, raw: string, kickoff: mixed}>
     */
    private function rows(User $user, array $selections, bool $accept): array
    {
        if ($selections === []) {
            throw new CouponException('sport.errors.empty');
        }

        $odds = SportOdd::query()->with(['fixture.league', 'market'])->whereIn('id', collect($selections)->pluck('odd_id'))->get()->keyBy('id');
        $rows = [];
        $changed = [];

        foreach ($selections as $selection) {
            $odd = $odds->get($selection['odd_id']);
            if ($odd === null || $odd->suspended || ! $odd->fixture->isOpen() || ! $odd->fixture->league->is_active) {
                $key = $odd === null || $odd->suspended ? 'sport.errors.suspended' : ($odd->fixture->isOpen() ? 'sport.errors.inactive' : 'sport.errors.started');
                throw new CouponException($key);
            }
            $shown = $this->margins->show((string) $odd->raw_odd, $odd->fixture, $odd->market->code, $user->superadmin_id);
            if (bccomp($shown, (string) $selection['shown'], 2) !== 0) {
                $changed[] = ['odd_id' => $odd->id, 'outcome' => $odd->outcome, 'from' => (string) $selection['shown'], 'to' => $shown];
                if (! $accept) {
                    continue;
                }
            }
            $rows[] = [
                'odd_id' => $odd->id,
                'fixture_id' => $odd->fixture_id,
                'outcome' => $odd->outcome,
                'market' => $odd->market->code,
                'shown' => $shown,
                'raw' => number_format((float) $odd->raw_odd, 2, '.', ''),
                'kickoff' => $odd->fixture->starts_at,
            ];
        }

        if ($changed !== [] && ! $accept) {
            throw new CouponException('sport.errors.odds_changed', [], $changed);
        }

        return $rows;
    }

    /**
     * @param  list<array{shown: string}>  $rows
     */
    private function guardLimits(User $user, string $mode, string $stake, array $rows): void
    {
        $limit = $this->limits->forUser($user);
        $count = count($rows);

        if (bccomp($stake, (string) $limit->min_stake, 2) < 0) {
            throw new CouponException('sport.errors.min_stake', ['amount' => $limit->min_stake]);
        }
        if (bccomp($stake, (string) $limit->max_stake, 2) > 0) {
            throw new CouponException('sport.errors.max_stake', ['amount' => $limit->max_stake]);
        }
        if ($mode === 'combo' && $count < (int) $limit->combo_min) {
            throw new CouponException('sport.errors.combo_min', ['count' => $limit->combo_min]);
        }
        if ($mode === 'combo' && $count > (int) $limit->combo_max) {
            throw new CouponException('sport.errors.combo_max', ['count' => $limit->combo_max]);
        }

        $groups = $mode === 'single' ? array_map(fn (array $row) => [$row], $rows) : [$rows];
        foreach ($groups as $group) {
            $prices = array_column($group, 'shown');
            foreach ($prices as $price) {
                if ($mode === 'single' && bccomp($price, (string) $limit->min_odd, 2) < 0) {
                    throw new CouponException('sport.errors.min_odd', ['odd' => $limit->min_odd]);
                }
            }
            $total = $this->calculator->total($mode === 'single' ? 'single' : 'combo', $prices);
            if (bccomp($total, (string) $limit->min_total_odds, 2) < 0) {
                throw new CouponException('sport.errors.min_total', ['odd' => $limit->min_total_odds]);
            }
            $win = $this->calculator->payout($mode === 'single' ? 'single' : 'combo', $stake, $prices);
            if (bccomp($win, (string) $limit->max_win, 2) > 0) {
                throw new CouponException('sport.errors.max_win', ['amount' => $limit->max_win]);
            }
        }

        $today = Coupon::query()
            ->where('user_id', $user->id)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->where('placed_at', '>=', now()->timezone($user->timezone)->startOfDay()->utc())
            ->sum('stake');
        $adding = $mode === 'single' ? bcmul($stake, (string) $count, 2) : $stake;
        if (bccomp(bcadd((string) $today, $adding, 2), (string) $limit->daily_max, 2) > 0) {
            throw new CouponException('sport.errors.daily_max', ['amount' => $limit->daily_max]);
        }
    }

    private function stake(string $stake): string
    {
        if (! preg_match('/^\d+(\.\d{1,2})?$/', $stake) || bccomp(bcadd($stake, '0', 2), '0', 2) !== 1) {
            throw new CouponException('sport.errors.stake');
        }

        return bcadd($stake, '0', 2);
    }

    /**
     * @return list<Coupon>
     */
    private function existing(User $user, string $clientKey): array
    {
        if ($clientKey === '') {
            throw new CouponException('sport.errors.stake');
        }

        $owned = Coupon::query()->where('client_key', $clientKey)->where('user_id', $user->id)->orderBy('id')->get();
        if ($owned->isNotEmpty()) {
            return $owned->all();
        }

        if (CouponPlacement::query()->where('client_key', $clientKey)->where('user_id', '!=', $user->id)->exists()) {
            throw new CouponException('sport.errors.stake');
        }

        return [];
    }

    private function ledgerAt(int $walletId, int $offset): \Illuminate\Support\Carbon
    {
        $latest = \App\Models\WalletTransaction::query()->where('wallet_id', $walletId)->max('created_at');
        $base = $latest === null ? now() : \Illuminate\Support\Carbon::parse($latest)->addSecond();

        return $base->addSeconds($offset);
    }

    private function number(): string
    {
        do {
            $number = (string) random_int(10000000, 99999999);
        } while (Coupon::query()->where('coupon_no', $number)->exists());

        return $number;
    }
}
