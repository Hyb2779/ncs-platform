<?php

namespace App\Services\Casino;

use App\Models\CasinoGame;
use App\Models\CasinoProvider as CasinoProviderModel;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class OneGameXProvider implements CasinoProvider
{
    public function __construct(
        private readonly CasinoWallet $wallet,
        private readonly CasinoUserCode $codes,
    ) {}

    public function code(): string
    {
        return 'onegamex';
    }

    public function syncGames(): int
    {
        CasinoProviderModel::query()->updateOrCreate(
            ['code' => 'onegamex'],
            ['name' => '1GameX', 'status' => 'active', 'is_live' => true],
        );

        if (! is_string(config('casino.onegamex.url')) || config('casino.onegamex.url') === '') {
            Log::warning('casino.onegamex.sync_skipped', ['reason' => 'missing_base_url']);

            return 0;
        }

        return 0;
    }

    public function launch(User $user, CasinoGame $game, string $device): string
    {
        throw new \RuntimeException('onegamex launch url is not configured');
    }

    public function handleCallback(Request $request): Response
    {
        $raw = $request->getContent();
        $secret = (string) config('casino.onegamex.secret_key');
        $tokenId = (string) config('casino.onegamex.token_id');
        $signature = hash_hmac('sha256', $raw, $secret);
        $given = (string) $request->header('X-Signature', '');
        $givenToken = (string) $request->header('X-Token-Id', '');

        if ($secret === '' || $tokenId === '' || ! hash_equals($tokenId, $givenToken) || ! hash_equals($signature, $given)) {
            Log::warning('casino.callback.rejected', ['provider' => 'onegamex', 'reason' => 'invalid_signature']);

            return response()->json(['status' => 'ERROR'], 401);
        }

        $body = $request->json()->all();
        $user = $this->codes->resolve((string) ($body['user_code'] ?? ''));

        if ($user === null) {
            return response()->json(['status' => 'ERROR'], 422);
        }

        $game = isset($body['game_code'])
            ? CasinoGame::query()->where('external_id', $body['game_code'])->first()
            : null;
        $action = (string) ($body['action'] ?? 'balance');

        try {
            $balance = match ($action) {
                'bet' => $this->wallet->bet($user, 'onegamex', (string) $body['transaction_id'], $this->amount($body), $body['round_id'] ?? null, $game, $body, $request->ip())['balance'],
                'win' => $this->wallet->win($user, 'onegamex', (string) $body['transaction_id'], $this->amount($body), $body['round_id'] ?? null, $game, $body, $request->ip())['balance'],
                'refund' => $this->wallet->refund($user, 'onegamex', (string) $body['transaction_id'], $body['bet_transaction_id'] ?? null, $this->amount($body), $body['round_id'] ?? null, $game, $body, $request->ip())['balance'],
                default => $this->wallet->balance($user),
            };
        } catch (InsufficientFunds) {
            return response()->json(['status' => 'insufficient_funds'], 422);
        }

        return response()->json(['status' => 'ok', 'balance' => $balance]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function amount(array $body): string
    {
        return bcadd((string) ($body['amount'] ?? '0'), '0', 2);
    }
}
