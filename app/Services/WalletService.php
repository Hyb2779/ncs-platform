<?php

namespace App\Services;

use App\Enums\Currency;
use App\Enums\UserRole;
use App\Enums\WalletProduct;
use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WalletService
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function credit(
        Wallet $wallet,
        string $amount,
        WalletTransactionType $type,
        WalletProduct $product,
        string $idempotencyKey,
        ?string $reference = null,
        ?int $counterpartyUserId = null,
        ?string $note = null,
        ?User $actor = null,
        ?string $ip = null,
        ?string $id = null,
        ?Carbon $createdAt = null,
    ): WalletTransaction {
        $this->assertAmount($amount);

        return $this->apply($wallet, $amount, $type, $product, $idempotencyKey, $reference, $counterpartyUserId, $note, $actor, $ip, $id, $createdAt);
    }

    public function debit(
        Wallet $wallet,
        string $amount,
        WalletTransactionType $type,
        WalletProduct $product,
        string $idempotencyKey,
        ?string $reference = null,
        ?int $counterpartyUserId = null,
        ?string $note = null,
        ?User $actor = null,
        ?string $ip = null,
        ?string $id = null,
        ?Carbon $createdAt = null,
    ): WalletTransaction {
        $this->assertAmount($amount);

        return $this->apply($wallet, $this->negate($amount), $type, $product, $idempotencyKey, $reference, $counterpartyUserId, $note, $actor, $ip, $id, $createdAt);
    }

    /**
     * @return array{0: WalletTransaction, 1: WalletTransaction}
     */
    public function transfer(
        User $from,
        User $to,
        string $amount,
        string $idempotencyKey,
        ?User $actor = null,
        ?string $note = null,
        ?string $ip = null,
    ): array {
        $this->assertAmount($amount);
        $currency = $this->transferCurrency($from, $to);
        $fromWallet = $this->walletFor($from, $currency);
        $toWallet = $this->walletFor($to, $currency);

        if ($fromWallet->currency !== $toWallet->currency) {
            throw new WalletException('wallet.currency_mismatch');
        }

        $existingOut = WalletTransaction::query()->where('idempotency_key', $idempotencyKey.':out')->first();

        if ($existingOut !== null) {
            $existingIn = WalletTransaction::query()->where('idempotency_key', $idempotencyKey.':in')->firstOrFail();

            return [$existingOut, $existingIn];
        }

        return DB::transaction(function () use ($from, $to, $fromWallet, $toWallet, $amount, $idempotencyKey, $actor, $note, $ip) {
            $again = WalletTransaction::query()->where('idempotency_key', $idempotencyKey.':out')->first();

            if ($again !== null) {
                $incoming = WalletTransaction::query()->where('idempotency_key', $idempotencyKey.':in')->firstOrFail();

                return [$again, $incoming];
            }

            $locked = Wallet::query()
                ->whereIn('id', [$fromWallet->id, $toWallet->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $source = $locked->get($fromWallet->id);
            $target = $locked->get($toWallet->id);
            $outId = (string) Str::uuid();
            $inId = (string) Str::uuid();

            $out = $this->write(
                $source,
                $this->negate($amount),
                WalletTransactionType::TransferOut,
                WalletProduct::Transfer,
                $idempotencyKey.':out',
                $inId,
                $to->id,
                $note,
                $actor,
                $ip,
                $outId,
            );
            $in = $this->write(
                $target,
                $this->normalize($amount),
                WalletTransactionType::TransferIn,
                WalletProduct::Transfer,
                $idempotencyKey.':in',
                $outId,
                $from->id,
                $note,
                $actor,
                $ip,
                $inId,
            );

            $this->activity->write($actor, 'wallet.transferred', $to, [
                'from_user_id' => $from->id,
                'to_user_id' => $to->id,
                'amount' => $this->normalize($amount),
                'currency' => $target->currency->value,
                'out_id' => $out->id,
                'in_id' => $in->id,
            ]);

            return [$out, $in];
        });
    }

    public function walletFor(User $user, Currency $currency): Wallet
    {
        $wallet = $user->wallets()->where('currency', $currency)->first();

        if ($wallet === null || ($user->role !== UserRole::Owner && $user->currency !== $currency)) {
            throw new WalletException('wallet.currency_mismatch');
        }

        return $wallet;
    }

    private function transferCurrency(User $from, User $to): Currency
    {
        if ($from->role === UserRole::Owner && $to->role !== UserRole::Owner) {
            return $to->currency;
        }

        if ($to->role === UserRole::Owner && $from->role !== UserRole::Owner) {
            return $from->currency;
        }

        if ($from->currency !== $to->currency) {
            throw new WalletException('wallet.currency_mismatch');
        }

        return $from->currency;
    }

    private function apply(
        Wallet $wallet,
        string $signedAmount,
        WalletTransactionType $type,
        WalletProduct $product,
        string $idempotencyKey,
        ?string $reference,
        ?int $counterpartyUserId,
        ?string $note,
        ?User $actor,
        ?string $ip,
        ?string $id,
        ?Carbon $createdAt = null,
    ): WalletTransaction {
        $existing = WalletTransaction::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        for ($attempt = 0; $attempt < 8; $attempt++) {
            try {
                return DB::transaction(function () use ($wallet, $signedAmount, $type, $product, $idempotencyKey, $reference, $counterpartyUserId, $note, $actor, $ip, $id, $createdAt) {
                    $again = WalletTransaction::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();

                    if ($again !== null) {
                        return $again;
                    }

                    $locked = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();

                    return $this->write($locked, $signedAmount, $type, $product, $idempotencyKey, $reference, $counterpartyUserId, $note, $actor, $ip, $id, $createdAt);
                });
            } catch (WalletException $exception) {
                if ($exception->translationKey !== 'wallet.conflict' || $attempt === 7) {
                    throw $exception;
                }
            }
        }

        throw new WalletException('wallet.conflict');
    }

    private function write(
        Wallet $wallet,
        string $signedAmount,
        WalletTransactionType $type,
        WalletProduct $product,
        string $idempotencyKey,
        ?string $reference,
        ?int $counterpartyUserId,
        ?string $note,
        ?User $actor,
        ?string $ip,
        ?string $id,
        ?Carbon $createdAt = null,
    ): WalletTransaction {
        $before = $this->normalize((string) $wallet->balance);
        $amount = $this->normalize($signedAmount);
        $after = bcadd($before, $amount, 2);

        if (bccomp($after, '0', 2) < 0 && ! $wallet->allow_negative) {
            throw new WalletException('wallet.insufficient_balance');
        }

        $updated = Wallet::query()
            ->whereKey($wallet->id)
            ->where('balance', $before)
            ->update([
                'balance' => $after,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new WalletException('wallet.conflict');
        }

        $wallet->balance = $after;

        $transaction = WalletTransaction::query()->create([
            'id' => $id ?? (string) Str::uuid(),
            'wallet_id' => $wallet->id,
            'user_id' => $wallet->user_id,
            'type' => $type,
            'product' => $product,
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => $after,
            'idempotency_key' => $idempotencyKey,
            'reference' => $reference,
            'counterparty_user_id' => $counterpartyUserId,
            'note' => $note,
            'created_by' => $actor?->id,
            'ip' => $ip,
            'created_at' => $createdAt ?? now(),
        ]);

        $this->activity->write($actor, 'wallet.posted', $wallet->user, [
            'transaction_id' => $transaction->id,
            'type' => $type->value,
            'amount' => $amount,
            'currency' => $wallet->currency->value,
        ]);

        return $transaction;
    }

    private function assertAmount(string $amount): void
    {
        if (! preg_match('/^\d+(\.\d{1,2})?$/', $amount) || bccomp($this->normalize($amount), '0', 2) !== 1) {
            throw new WalletException('wallet.invalid_amount');
        }
    }

    private function normalize(string $amount): string
    {
        return bcadd($amount, '0', 2);
    }

    private function negate(string $amount): string
    {
        return bcsub('0', $this->normalize($amount), 2);
    }
}
