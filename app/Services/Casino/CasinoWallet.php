<?php

namespace App\Services\Casino;

use App\Enums\WalletProduct;
use App\Enums\WalletTransactionType;
use App\Models\CasinoGame;
use App\Models\GameRound;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\WalletException;
use App\Services\WalletService;

class CasinoWallet
{
    public function __construct(private readonly WalletService $wallets) {}

    public function balance(User $user): string
    {
        return bcadd((string) $user->wallet()->first()->balance, '0', 2);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{balance: string, applied: bool}
     */
    public function bet(User $user, string $provider, string $transactionId, string $amount, ?string $roundId, ?CasinoGame $game, array $payload, ?string $ip): array
    {
        return $this->move($user, $provider, $transactionId, $amount, $roundId, $game, $payload, $ip, true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{balance: string, applied: bool}
     */
    public function win(User $user, string $provider, string $transactionId, string $amount, ?string $roundId, ?CasinoGame $game, array $payload, ?string $ip): array
    {
        return $this->move($user, $provider, $transactionId, $amount, $roundId, $game, $payload, $ip, false);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{balance: string, applied: bool, ignored: bool}
     */
    public function refund(User $user, string $provider, string $transactionId, ?string $betTransactionId, string $amount, ?string $roundId, ?CasinoGame $game, array $payload, ?string $ip): array
    {
        $key = $provider.':'.$transactionId;
        $existing = WalletTransaction::query()->where('idempotency_key', $key)->first();

        if ($existing !== null) {
            return ['balance' => (string) $existing->balance_after, 'applied' => false, 'ignored' => false];
        }

        $bet = $betTransactionId === null
            ? null
            : GameRound::query()->where('provider', $provider)->where('provider_transaction_id', $betTransactionId)->where('status', 'bet')->first();

        if ($bet === null || $bet->status === 'refunded') {
            return ['balance' => $this->balance($user), 'applied' => false, 'ignored' => true];
        }

        $result = $this->credit($user, $provider, $transactionId, $amount, $game, WalletTransactionType::Refund, $payload, $ip, 'refund');

        if ($result['applied']) {
            $bet->status = 'refunded';
            $bet->save();
        }

        return ['balance' => $result['balance'], 'applied' => $result['applied'], 'ignored' => false];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{balance: string, applied: bool}
     */
    private function move(User $user, string $provider, string $transactionId, string $amount, ?string $roundId, ?CasinoGame $game, array $payload, ?string $ip, bool $debit): array
    {
        $key = $provider.':'.$transactionId;
        $existing = WalletTransaction::query()->where('idempotency_key', $key)->first();

        if ($existing !== null) {
            return ['balance' => (string) $existing->balance_after, 'applied' => false];
        }

        $product = ($game?->is_live ?? false) ? WalletProduct::LiveCasino : WalletProduct::Slot;
        $wallet = $user->wallet()->first();

        try {
            $transaction = $debit
                ? $this->wallets->debit($wallet, $amount, WalletTransactionType::Bet, $product, $key, $roundId, null, null, $user, $ip)
                : $this->wallets->credit($wallet, $amount, WalletTransactionType::Win, $product, $key, $roundId, null, null, $user, $ip);
        } catch (WalletException $exception) {
            if ($exception->translationKey === 'wallet.insufficient_balance') {
                throw new InsufficientFunds();
            }

            throw $exception;
        }

        GameRound::query()->create([
            'provider' => $provider,
            'provider_transaction_id' => $transactionId,
            'round_id' => $roundId,
            'user_id' => $user->id,
            'game_id' => $game?->id,
            'bet' => $debit ? $amount : '0.00',
            'win' => $debit ? '0.00' : $amount,
            'balance_before' => $transaction->balance_before,
            'amount' => $transaction->amount,
            'balance_after' => $transaction->balance_after,
            'status' => $debit ? 'bet' : 'win',
            'payload' => $payload,
            'created_at' => now(),
        ]);

        return ['balance' => (string) $transaction->balance_after, 'applied' => true];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{balance: string, applied: bool}
     */
    private function credit(User $user, string $provider, string $transactionId, string $amount, ?CasinoGame $game, WalletTransactionType $type, array $payload, ?string $ip, string $status): array
    {
        $key = $provider.':'.$transactionId;
        $product = ($game?->is_live ?? false) ? WalletProduct::LiveCasino : WalletProduct::Slot;
        $transaction = $this->wallets->credit($user->wallet()->first(), $amount, $type, $product, $key, null, null, null, $user, $ip);

        GameRound::query()->create([
            'provider' => $provider,
            'provider_transaction_id' => $transactionId,
            'round_id' => null,
            'user_id' => $user->id,
            'game_id' => $game?->id,
            'bet' => '0.00',
            'win' => $amount,
            'balance_before' => $transaction->balance_before,
            'amount' => $transaction->amount,
            'balance_after' => $transaction->balance_after,
            'status' => $status,
            'payload' => $payload,
            'created_at' => now(),
        ]);

        return ['balance' => (string) $transaction->balance_after, 'applied' => true];
    }
}
