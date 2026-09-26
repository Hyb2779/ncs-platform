<?php

namespace App\Services\Sport;

use App\Enums\WalletProduct;
use App\Enums\WalletTransactionType;
use App\Models\Coupon;
use App\Models\CouponSelection;
use App\Models\SportFixture;
use App\Models\SportWarning;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\ActivityLogger;
use App\Services\WalletException;
use App\Services\WalletService;
use Illuminate\Support\Carbon;

class CouponSettler
{
    public function __construct(
        private readonly SelectionEvaluator $evaluator,
        private readonly CouponCalculator $calculator,
        private readonly WalletService $wallets,
        private readonly ActivityLogger $activity,
    ) {}

    public function settle(Coupon $coupon): Coupon
    {
        return $this->wallets->within(function () use ($coupon): Coupon {
            $locked = Coupon::query()->whereKey($coupon->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'cancelled') {
                return $locked;
            }

            $locked->load(['selections.fixture', 'user']);
            $this->evaluatePending($locked);

            $locked->refresh()->load(['selections', 'user']);
            if ($locked->status === 'cancelled') {
                return $locked;
            }

            if ($locked->selections->contains(fn (CouponSelection $selection): bool => $selection->status === 'lost')) {
                if ($locked->status === 'pending') {
                    $this->mark($locked, 'lost');
                    $this->activity->write(null, 'coupon.settled', $locked->user, [
                        'coupon_no' => $locked->coupon_no,
                        'status' => 'lost',
                        'revision' => $locked->settlement_revision,
                    ]);
                }

                return $locked->refresh();
            }

            if ($locked->selections->contains(fn (CouponSelection $selection): bool => $selection->status === 'pending')) {
                return $locked;
            }

            if (in_array($locked->status, ['won', 'lost', 'void', 'refunded'], true) && $locked->settled_at !== null) {
                return $locked;
            }

            $this->pay($locked);

            return $locked->refresh();
        });
    }

    public function correct(Coupon $coupon, User $actor, ?int $fixtureId = null): Coupon
    {
        return $this->wallets->within(function () use ($coupon, $actor, $fixtureId): Coupon {
            $locked = Coupon::query()->whereKey($coupon->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'cancelled') {
                return $locked;
            }

            $locked->load(['selections.fixture', 'user']);
            $this->reverse($locked, $actor);

            $locked->settlement_revision = (int) $locked->settlement_revision + 1;
            $locked->status = 'pending';
            $locked->settled_at = null;
            $locked->save();

            foreach ($locked->selections as $selection) {
                if ($fixtureId === null || $selection->fixture_id === $fixtureId) {
                    $selection->status = 'pending';
                    $selection->settled_at = null;
                    $selection->save();
                }
            }

            $this->activity->write($actor, 'coupon.corrected', $locked->user, [
                'coupon_no' => $locked->coupon_no,
                'revision' => $locked->settlement_revision,
                'fixture_id' => $fixtureId,
            ]);

            return $this->settle($locked);
        });
    }

    private function evaluatePending(Coupon $coupon): void
    {
        foreach ($coupon->selections as $selection) {
            if ($selection->status !== 'pending') {
                continue;
            }

            $fixture = $selection->fixture;
            if ($fixture === null) {
                continue;
            }

            if ($this->startedAfterVoidWindow($selection, $fixture)) {
                $selection->status = 'void';
                $selection->settled_at = now();
                $selection->save();

                continue;
            }

            $result = $this->evaluator->evaluate(
                $selection->market_code,
                $selection->outcome,
                $fixture->ft_home,
                $fixture->ft_away,
                $fixture->ht_home !== null ? (int) $fixture->ht_home : null,
                $fixture->ht_away !== null ? (int) $fixture->ht_away : null,
            );
            if ($result === null) {
                continue;
            }

            $selection->status = $result;
            $selection->settled_at = now();
            $selection->save();
        }
    }

    private function startedAfterVoidWindow(CouponSelection $selection, SportFixture $fixture): bool
    {
        if ($selection->kickoff_at === null || $fixture->played_at === null) {
            return false;
        }

        if (! in_array($fixture->status, config('sport.settle_statuses'), true)) {
            return false;
        }

        $deadline = $selection->kickoff_at->copy()->addHours((int) config('sport.void_after_hours'));

        return $fixture->played_at->greaterThan($deadline);
    }

    private function pay(Coupon $coupon): void
    {
        $odds = $coupon->selections->map(
            fn (CouponSelection $selection): string => $selection->status === 'void' ? '1.00' : (string) $selection->odds
        )->all();
        $allVoid = $coupon->selections->every(fn (CouponSelection $selection): bool => $selection->status === 'void');
        $mode = $coupon->type === 'single' ? 'single' : 'combo';
        $payout = $allVoid
            ? bcadd((string) $coupon->stake, '0', 2)
            : $this->calculator->payout($mode, (string) $coupon->stake, $odds);
        $total = $this->calculator->total($mode, $odds);

        $this->credit(
            $coupon,
            $payout,
            $allVoid ? WalletTransactionType::Refund : WalletTransactionType::Win,
        );

        $coupon->total_odds = $total;
        $coupon->potential_win = $payout;
        $this->mark($coupon, $allVoid ? 'void' : 'won');
        $this->activity->write(null, 'coupon.settled', $coupon->user, [
            'coupon_no' => $coupon->coupon_no,
            'status' => $coupon->status,
            'payout' => $payout,
            'revision' => $coupon->settlement_revision,
        ]);
    }

    private function credit(Coupon $coupon, string $amount, WalletTransactionType $type): void
    {
        if (bccomp($amount, '0', 2) !== 1) {
            return;
        }

        $wallet = $coupon->user->wallet()->firstOrFail();
        $this->wallets->credit(
            $wallet,
            $amount,
            $type,
            WalletProduct::Sport,
            'coupon:'.$coupon->id.':settle:'.$coupon->settlement_revision,
            $coupon->coupon_no,
            null,
            null,
            null,
            null,
            null,
            $this->ledgerAt($wallet->id),
        );
    }

    private function reverse(Coupon $coupon, User $actor): void
    {
        $key = 'coupon:'.$coupon->id.':settle:'.$coupon->settlement_revision;
        $existing = WalletTransaction::query()->where('idempotency_key', $key)->first();
        if ($existing === null || bccomp((string) $existing->amount, '0', 2) !== 1) {
            return;
        }

        $wallet = $coupon->user->wallet()->lockForUpdate()->firstOrFail();
        $amount = bcadd((string) $existing->amount, '0', 2);
        $after = bcsub($this->normalize((string) $wallet->balance), $amount, 2);

        if (bccomp($after, '0', 2) < 0 && ! $wallet->allow_negative) {
            $shortfall = bcsub('0', $after, 2);
            $wallet->forceFill(['settlement_overdraft_amount' => $shortfall])->save();
            SportWarning::query()->updateOrCreate(
                [
                    'type' => SportWarning::Overdraft,
                    'user_id' => $coupon->user_id,
                    'coupon_id' => $coupon->id,
                ],
                [
                    'amount' => $shortfall,
                    'resolved_at' => null,
                ],
            );
        }

        try {
            $this->wallets->debit(
                $wallet->fresh(),
                $amount,
                WalletTransactionType::Adjustment,
                WalletProduct::Sport,
                'coupon:'.$coupon->id.':reverse:'.$coupon->settlement_revision,
                $coupon->coupon_no,
                null,
                null,
                $actor,
                null,
                null,
                $this->ledgerAt($wallet->id),
            );
        } catch (WalletException $exception) {
            throw new CouponException($exception->translationKey, $exception->replace);
        }
    }

    private function mark(Coupon $coupon, string $status): void
    {
        $coupon->status = $status;
        $coupon->settled_at = now();
        $coupon->save();
    }

    private function ledgerAt(int $walletId): Carbon
    {
        $latest = WalletTransaction::query()->where('wallet_id', $walletId)->max('created_at');
        if ($latest === null) {
            return now();
        }

        $next = Carbon::parse($latest)->addSecond();

        return $next->gt(now()) ? $next : now();
    }

    private function normalize(string $amount): string
    {
        return bcadd($amount, '0', 2);
    }
}
