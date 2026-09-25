<?php

namespace App\Services\Casino;

use App\Models\CasinoGame;
use App\Models\CasinoProvider as CasinoProviderModel;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class DemoProvider implements CasinoProvider
{
    public function __construct(
        private readonly CasinoWallet $wallet,
        private readonly CasinoUserCode $codes,
    ) {}

    public function code(): string
    {
        return 'demo';
    }

    public function syncGames(): int
    {
        if (app()->isProduction()) {
            return 0;
        }

        $provider = CasinoProviderModel::query()->updateOrCreate(
            ['code' => 'demo'],
            ['name' => 'Demo', 'status' => 'active', 'is_live' => false],
        );

        $games = [
            ['slot-1', 'Demo Slot 1', 'slot', false, true],
            ['slot-2', 'Demo Slot 2', 'slot', false, true],
            ['slot-3', 'Demo Slot 3', 'slot', false, false],
            ['slot-4', 'Demo Slot 4', 'slot', false, false],
            ['slot-5', 'Demo Slot 5', 'slot', false, false],
            ['slot-6', 'Demo Slot 6', 'slot', false, false],
            ['live-1', 'Demo Roulette', 'live', true, true],
            ['live-2', 'Demo Blackjack', 'live', true, false],
            ['live-3', 'Demo Baccarat', 'live', true, false],
            ['live-4', 'Demo Poker', 'live', true, false],
        ];

        foreach ($games as $index => [$external, $name, $category, $live, $popular]) {
            CasinoGame::query()->updateOrCreate(
                ['provider_id' => $provider->id, 'external_id' => $external],
                [
                    'name' => $name,
                    'category' => $category,
                    'image_url' => null,
                    'is_live' => $live,
                    'is_active' => true,
                    'sort_order' => $index,
                    'is_popular' => $popular,
                ],
            );
        }

        return count($games);
    }

    public function launch(User $user, CasinoGame $game, string $device): string
    {
        return route('site.demo', ['game' => $game->id]);
    }

    public function handleCallback(Request $request): Response
    {
        $raw = $request->getContent();
        $expected = hash_hmac('sha256', $raw, (string) config('casino.demo_secret'));
        $given = (string) $request->header('X-Demo-Signature', '');

        if (! hash_equals($expected, $given)) {
            return $this->error('invalid_signature', 401);
        }

        $body = $request->json()->all();
        $user = $this->codes->resolve((string) ($body['user_code'] ?? ''));

        if ($user === null) {
            return $this->error('invalid_user', 422);
        }

        $action = (string) ($body['action'] ?? 'balance');
        $game = isset($body['game_code'])
            ? CasinoGame::query()->where('external_id', $body['game_code'])->first()
            : null;

        try {
            $balance = match ($action) {
                'bet' => $this->wallet->bet($user, 'demo', (string) $body['transaction_id'], $this->amount($body), $body['round_id'] ?? null, $game, $body, $request->ip())['balance'],
                'win' => $this->wallet->win($user, 'demo', (string) $body['transaction_id'], $this->amount($body), $body['round_id'] ?? null, $game, $body, $request->ip())['balance'],
                'refund' => $this->wallet->refund($user, 'demo', (string) $body['transaction_id'], $body['bet_transaction_id'] ?? null, $this->amount($body), $body['round_id'] ?? null, $game, $body, $request->ip())['balance'],
                default => $this->wallet->balance($user),
            };
        } catch (InsufficientFunds) {
            return $this->error('insufficient_funds', 422);
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

    private function error(string $reason, int $status): JsonResponse
    {
        Log::warning('casino.callback.rejected', ['provider' => 'demo', 'reason' => $reason]);

        return response()->json(['status' => 'error', 'message' => $reason === 'insufficient_funds' ? 'insufficient_funds' : 'ERROR'], $status);
    }
}
