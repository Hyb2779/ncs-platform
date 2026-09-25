<?php

namespace App\Services\Sport;

use App\Enums\UserRole;
use App\Enums\WalletProduct;
use App\Enums\WalletTransactionType;
use App\Models\Coupon;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\WalletException;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;

class CouponCanceller
{
    public function __construct(
        private readonly SportLimits $limits,
        private readonly WalletService $wallets,
        private readonly ActivityLogger $activity,
    ) {}

    public function cancel(User $actor, Coupon $coupon, string $reason, ?string $ip): Coupon
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new CouponException('sport.errors.cancel_reason');
        }

        if ($coupon->status !== 'pending') {
            throw new CouponException('sport.errors.not_pending');
        }

        $coupon->loadMissing('user', 'selections.fixture');
        $this->authorize($actor, $coupon);

        try {
            return DB::transaction(function () use ($actor, $coupon, $reason, $ip) {
                $locked = Coupon::query()->whereKey($coupon->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'pending') {
                    throw new CouponException('sport.errors.not_pending');
                }

                $wallet = $locked->user->wallet()->firstOrFail();
                $this->wallets->credit(
                    $wallet,
                    (string) $locked->stake,
                    WalletTransactionType::Refund,
                    WalletProduct::Sport,
                    'coupon-refund:'.$locked->id,
                    $locked->coupon_no,
                    null,
                    $reason,
                    $actor,
                    $ip,
                    null,
                    $this->ledgerAt($wallet->id),
                );

                $locked->status = 'cancelled';
                $locked->cancelled_by = $actor->id;
                $locked->cancel_reason = $reason;
                $locked->settled_at = now();
                $locked->save();
                $this->activity->write($actor, 'coupon.cancelled', $locked->user, [
                    'coupon_no' => $locked->coupon_no,
                    'reason' => $reason,
                    'stake' => (string) $locked->stake,
                ]);

                return $locked;
            });
        } catch (WalletException $exception) {
            throw new CouponException($exception->translationKey, $exception->replace);
        }
    }

    private function ledgerAt(int $walletId): \Illuminate\Support\Carbon
    {
        $latest = \App\Models\WalletTransaction::query()->where('wallet_id', $walletId)->max('created_at');

        return ($latest === null ? now() : \Illuminate\Support\Carbon::parse($latest))->addSecond();
    }

    private function authorize(User $actor, Coupon $coupon): void
    {
        if ($actor->id === $coupon->user_id) {
            $minutes = (int) $this->limits->forUser($actor)->cancel_minutes;
            if ($minutes < 1 || $coupon->placed_at->copy()->addMinutes($minutes)->isPast()) {
                throw new CouponException('sport.errors.cancel_closed');
            }
            foreach ($coupon->selections as $selection) {
                if (! $selection->fixture->isOpen()) {
                    throw new CouponException('sport.errors.cancel_started');
                }
            }

            return;
        }

        if ($actor->role === UserRole::Uye || ! $coupon->user->isInSubtreeOf($actor) || $actor->id === $coupon->user_id) {
            throw new CouponException('sport.errors.cancel_forbidden');
        }
    }
}
