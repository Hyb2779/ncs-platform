<?php

namespace App\Services\Sport;

use App\Enums\WalletProduct;
use App\Enums\WalletTransactionType;
use App\Models\Coupon;
use App\Models\CouponPlacement;
use App\Models\CouponSelection;
use App\Models\SportFixture;
use App\Models\SportOdd;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\WalletException;
use App\Services\WalletService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CouponPlacer
{
    public function __construct(
        private readonly CouponCalculator $calculator,
        private readonly MarginEngine $margins,
        private readonly CouponLimitGuard $guard,
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
        $this->guard->assertSlip($user, $mode, $stake, $rows);

        try {
            return $this->wallets->within(function () use ($user, $clientKey, $stake, $mode, $accept, $rows, $ip, $device) {
                $lockedWallet = Wallet::query()
                    ->where('user_id', $user->id)
                    ->where('currency', $user->currency->value)
                    ->lockForUpdate()
                    ->first();
                if ($lockedWallet === null) {
                    throw new CouponException('sport.errors.stake');
                }

                $again = $this->existing($user, $clientKey);
                if ($again !== []) {
                    return $again;
                }

                CouponPlacement::query()->create(['client_key' => $clientKey, 'user_id' => $user->id]);
                $coupons = [];
                $groups = $mode === 'single' ? array_map(fn (array $row) => [$row], $rows) : [$rows];

                foreach ($groups as $index => $group) {
                    $this->guard->assertTotals($user, $mode, $stake, $group);
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
                            'kickoff_at' => $row['kickoff'],
                            'status' => 'pending',
                            ...$this->snapshot($row['fixture']),
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
    /**
     * @return array{placed_status: string, placed_minute: int|null, placed_home: int|null, placed_away: int|null}
     */
    private function snapshot(SportFixture $fixture): array
    {
        if ($fixture->isOpen()) {
            return [
                'placed_status' => 'NS',
                'placed_minute' => null,
                'placed_home' => null,
                'placed_away' => null,
            ];
        }

        return [
            'placed_status' => (string) $fixture->status,
            'placed_minute' => $fixture->elapsed,
            'placed_home' => $fixture->score_home === null ? null : (int) $fixture->score_home,
            'placed_away' => $fixture->score_away === null ? null : (int) $fixture->score_away,
        ];
    }

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
                'fixture' => $odd->fixture,
            ];
        }

        if ($changed !== [] && ! $accept) {
            throw new CouponException('sport.errors.odds_changed', [], $changed);
        }

        return $rows;
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

    private function ledgerAt(int $walletId, int $offset): Carbon
    {
        $latest = WalletTransaction::query()->where('wallet_id', $walletId)->max('created_at');
        $base = $latest === null ? now() : Carbon::parse($latest)->addSecond();

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
