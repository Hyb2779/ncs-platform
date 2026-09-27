<?php

namespace App\Http\Controllers\Bridge;

use App\Enums\UserRole;
use App\Enums\WalletProduct;
use App\Enums\WalletTransactionType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\HierarchyService;
use App\Services\WalletException;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * NCS VIP -> Wegas köprüsü: "Wegas Spor" (Tipo) cüzdan callback'leri.
 * Tipo, callback'leri NCS VIP'e gönderir; player_id "wegas:" ile başlıyorsa NCS VIP bu uca iletir.
 * Cevap formatı NCS VIP'in Tipo'ya verdiğiyle birebir aynıdır.
 */
class TipoBridgeController extends Controller
{
    public const PREFIX = 'wegas:';

    public function __invoke(Request $request, WalletService $wallets, HierarchyService $hierarchy): JsonResponse
    {
        if (! $this->authorized($request)) {
            Log::warning('tipo_bridge_rejected', ['ip' => $request->ip()]);

            return $this->fail('Geçersiz imza.', 401);
        }

        $body = json_decode($request->getContent(), true);
        $body = is_array($body) ? $body : [];
        $action = (string) ($body['action'] ?? '');
        $txId = trim((string) ($body['tx_id'] ?? ''));
        $amount = isset($body['amount']) ? round((float) $body['amount'], 2) : 0.0;
        $betId = isset($body['bet_id']) ? (string) $body['bet_id'] : null;

        $user = $this->player(trim((string) ($body['player_id'] ?? '')));
        if ($user === null) {
            return $this->fail('player_id bulunamadi.');
        }
        if ($user->currency->value !== 'TRY') {
            return $this->fail('Para birimi desteklenmiyor.');
        }

        try {
            return match ($action) {
                'getBalance', 'get_balance', 'balance' => $this->ok($user),
                'debit', 'withdraw' => $this->move($user, $wallets, $hierarchy, $txId, $amount, $betId, true, $request->ip()),
                'credit', 'deposit' => $this->move($user, $wallets, $hierarchy, $txId, $amount, $betId, false, $request->ip()),
                'rollback', 'refund' => $this->rollback($user, $wallets, $txId, $request->ip()),
                default => $this->fail('Geçersiz cüzdan işlemi.'),
            };
        } catch (\InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage());
        }
    }

    private function authorized(Request $request): bool
    {
        $secret = (string) config('services.ncs_bridge.secret');
        $ips = array_filter(array_map('trim', explode(',', (string) config('services.ncs_bridge.allowed_ips'))));
        $timestamp = (string) $request->header('X-Bridge-Timestamp', '');
        $signature = (string) $request->header('X-Bridge-Signature', '');

        if ($secret === '' || $signature === '' || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 60) {
            return false;
        }
        if ($ips !== [] && ! in_array($request->ip(), $ips, true)) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret), $signature);
    }

    private function player(string $playerId): ?User
    {
        if (! str_starts_with($playerId, self::PREFIX)) {
            return null;
        }
        $id = substr($playerId, strlen(self::PREFIX));

        return ctype_digit($id) ? User::query()->whereKey((int) $id)->where('role', UserRole::Uye)->first() : null;
    }

    private function ok(User $user, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'success' => true,
            'balance' => (float) $user->wallet()->first()->balance,
            'currency' => 'TRY',
            'lang' => match ($user->language->value) {
                'en' => 'en',
                'de' => 'de',
                'ar' => 'en',
                default => 'tr',
            },
        ], $extra));
    }

    private function fail(string $message, int $status = 422): JsonResponse
    {
        return response()->json(['success' => false, 'error' => $message], $status);
    }

    private function move(User $user, WalletService $wallets, HierarchyService $hierarchy, string $txId, float $amount, ?string $betId, bool $debit, ?string $ip): JsonResponse
    {
        if ($txId === '') {
            throw new \InvalidArgumentException('tx_id zorunludur.');
        }
        if ($amount <= 0) {
            throw new \InvalidArgumentException("Tutar 0'dan büyük olmalıdır.");
        }
        if ($debit && $amount > (float) config('services.ncs_bridge.max_debit_stake', 10000)) {
            throw new \InvalidArgumentException('Limit aşıldı.');
        }

        $key = 'tipo:'.$txId;
        if (WalletTransaction::query()->where('idempotency_key', $key)->exists()) {
            return $this->ok($user, ['tx_id' => $txId]);
        }
        if ($debit && $hierarchy->loginBlocked($user)) {
            throw new \InvalidArgumentException('Hesabınız pasif durumda.');
        }

        $wallet = $user->wallet()->first();
        $value = number_format($amount, 2, '.', '');
        $reference = 'tipo:'.($betId ?? $txId);

        try {
            $debit
                ? $wallets->debit($wallet, $value, WalletTransactionType::Bet, WalletProduct::Sport, $key, $reference, null, null, $user, $ip)
                : $wallets->credit($wallet, $value, WalletTransactionType::Win, WalletProduct::Sport, $key, $reference, null, null, $user, $ip);
        } catch (WalletException $exception) {
            if ($exception->translationKey === 'wallet.insufficient_balance') {
                throw new \InvalidArgumentException('Yetersiz bakiye.');
            }
            throw $exception;
        }

        return $this->ok($user, ['tx_id' => $txId]);
    }

    private function rollback(User $user, WalletService $wallets, string $txId, ?string $ip): JsonResponse
    {
        if ($txId === '') {
            throw new \InvalidArgumentException('tx_id zorunludur.');
        }

        $rollbackId = 'rollback:'.$txId;
        $key = 'tipo:'.$rollbackId;
        if (WalletTransaction::query()->where('idempotency_key', $key)->exists()) {
            return $this->ok($user, ['tx_id' => $rollbackId]);
        }

        $original = WalletTransaction::query()->where('idempotency_key', 'tipo:'.$txId)->first();
        if ($original === null) {
            return $this->ok($user, ['tx_id' => $rollbackId]);
        }

        $wallet = $user->wallet()->first();
        if ((int) $original->wallet_id !== (int) $wallet->id) {
            throw new \InvalidArgumentException('tx_id bu oyuncuya ait değil.');
        }

        $type = $original->type instanceof WalletTransactionType ? $original->type->value : (string) $original->type;
        $value = number_format(abs((float) $original->amount), 2, '.', '');

        try {
            $type === 'bet'
                ? $wallets->credit($wallet, $value, WalletTransactionType::Refund, WalletProduct::Sport, $key, 'tipo:'.$txId, null, null, $user, $ip)
                : $wallets->debit($wallet, $value, WalletTransactionType::Refund, WalletProduct::Sport, $key, 'tipo:'.$txId, null, null, $user, $ip);
        } catch (WalletException $exception) {
            if ($exception->translationKey === 'wallet.insufficient_balance') {
                throw new \InvalidArgumentException('Rollback için yetersiz bakiye.');
            }
            throw $exception;
        }

        return $this->ok($user, ['tx_id' => $rollbackId]);
    }
}
