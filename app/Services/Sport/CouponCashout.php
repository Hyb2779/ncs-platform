<?php

namespace App\Services\Sport;

use App\Enums\WalletProduct;
use App\Enums\WalletTransactionType;
use App\Models\Coupon;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\ActivityLogger;
use App\Services\WalletException;
use App\Services\WalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CouponCashout
{
    public function __construct(
        private readonly CashoutQuote $quotes,
        private readonly WalletService $wallets,
        private readonly ActivityLogger $activity,
    ) {}

    public function take(User $actor, Coupon $coupon, string $shown, ?string $ip): Coupon
    {
        if ($actor->id !== $coupon->user_id) {
            throw new CouponException('sport.errors.cancel_forbidden');
        }
        if (! preg_match('/^\d+(\.\d{1,2})?$/', $shown)) {
            throw new CouponException('sport.errors.cashout_closed');
        }
        $shown = bcadd($shown, '0', 2);

        try {
            return DB::transaction(function () use ($actor, $coupon, $shown, $ip) {
                $locked = Coupon::query()->whereKey($coupon->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'pending') {
                    throw new CouponException('sport.errors.cashout_closed');
                }

                $locked->load(['selections.fixture', 'user']);
                $fresh = $this->quotes->quote($locked);
                if ($fresh === null) {
                    throw new CouponException('sport.errors.cashout_closed');
                }
                if ($this->moved($shown, $fresh)) {
                    throw new CouponException('sport.errors.cashout_moved', ['amount' => $fresh]);
                }

                $wallet = $locked->user->wallet()->firstOrFail();
                $this->wallets->credit(
                    $wallet,
                    $fresh,
                    WalletTransactionType::Cashout,
                    WalletProduct::Sport,
                    'coupon-cashout:'.$locked->id,
                    $locked->coupon_no,
                    null,
                    null,
                    $actor,
                    $ip,
                    null,
                    $this->ledgerAt($wallet->id),
                );

                $locked->status = 'cashed_out';
                $locked->settled_at = now();
                $locked->cancelled_by = $actor->id;
                $locked->save();
                $this->activity->write($actor, 'coupon.cashed_out', $locked->user, [
                    'coupon_no' => $locked->coupon_no,
                    'amount' => $fresh,
                ]);

                return $locked;
            });
        } catch (WalletException $exception) {
            throw new CouponException($exception->translationKey, $exception->replace);
        }
    }

    private function moved(string $shown, string $fresh): bool
    {
        if (bccomp($shown, '0', 2) !== 1) {
            return true;
        }

        $gap = bcsub($fresh, $shown, 4);
        if (str_starts_with($gap, '-')) {
            $gap = substr($gap, 1);
        }
        $drift = bcadd((string) config('sport.cashout_drift', '0.02'), '0', 4);

        return bccomp(bcdiv($gap, $shown, 4), $drift, 4) === 1;
    }

    private function ledgerAt(int $walletId): Carbon
    {
        $latest = WalletTransaction::query()->where('wallet_id', $walletId)->max('created_at');

        return ($latest === null ? now() : Carbon::parse($latest))->addSecond();
    }
}
