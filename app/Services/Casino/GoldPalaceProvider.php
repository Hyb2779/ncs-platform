<?php

namespace App\Services\Casino;

use App\Enums\Language;
use App\Models\CasinoGame;
use App\Models\CasinoProvider as CasinoProviderModel;
use App\Models\CasinoProviderUser;
use App\Models\User;
use App\Support\SecretMask;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class GoldPalaceProvider implements CasinoProvider
{
    public function __construct(
        private readonly CasinoWallet $wallet,
        private readonly CasinoUserCode $codes,
    ) {}

    public function code(): string
    {
        return 'goldpalace';
    }

    public function syncGames(): int
    {
        $provider = CasinoProviderModel::query()->updateOrCreate(
            ['code' => 'goldpalace'],
            ['name' => 'GoldPalace', 'status' => 'active', 'is_live' => false],
        );
        $vendors = $this->post('/v4/game/providers', ['lang' => 1]);
        $count = 0;

        foreach ($vendors['data'] ?? [] as $vendor) {
            $games = $this->post('/v4/game/games', [
                'provider_id' => $vendor['provider_id'],
                'lang' => 1,
            ]);

            foreach ($games['data'] ?? [] as $index => $game) {
                CasinoGame::query()->updateOrCreate(
                    ['provider_id' => $provider->id, 'external_id' => $vendor['provider_id'].':'.$game['game_code']],
                    [
                        'name' => (string) ($game['locale_name'] ?: $game['game_name']),
                        'category' => (string) ($game['category'] ?: 'slot'),
                        'image_url' => $game['game_image'] ?? null,
                        'is_live' => false,
                        'is_active' => (bool) ($game['launch_enable'] ?? true),
                        'sort_order' => $index,
                        'is_popular' => $index < 12,
                    ],
                );
                $count++;
            }
        }

        return $count;
    }

    public function launch(User $user, CasinoGame $game, string $device): string
    {
        [$vendorId, $symbol] = array_pad(explode(':', $game->external_id, 2), 2, $game->external_id);
        $result = $this->post('/v4/game/game-url', [
            'user_code' => $this->externalUser($user),
            'provider_id' => (int) $vendorId,
            'game_symbol' => $symbol,
            'lang' => $this->lang($user->language),
            'return_url' => url('/slots'),
        ]);

        return (string) ($result['data']['game_url'] ?? '');
    }

    public function handleCallback(Request $request): Response
    {
        $token = (string) $request->header('Callback-Token', '');
        $expected = (string) config('casino.goldpalace.callback_token');

        if ($expected === '' || ! hash_equals($expected, $token)) {
            return $this->error(1010, 'ERROR');
        }

        $body = $request->json()->all();
        $user = $this->resolveUser((string) ($body['user_code'] ?? ''));

        if ($user === null) {
            return $this->error(2002, 'ERROR');
        }

        $game = isset($body['game_code'])
            ? CasinoGame::query()->where('external_id', $body['game_code'])->first()
            : null;
        $type = isset($body['transaction_type']) ? (int) $body['transaction_type'] : 0;

        try {
            $balance = match ($type) {
                1 => $this->wallet->bet($user, 'goldpalace', (string) $body['transaction_id'], $this->amount($body), $body['round_id'] ?? null, $game, $body, $request->ip())['balance'],
                2 => $this->wallet->win($user, 'goldpalace', (string) $body['transaction_id'], $this->amount($body), $body['round_id'] ?? null, $game, $body, $request->ip())['balance'],
                16 => $this->wallet->refund($user, 'goldpalace', (string) $body['transaction_id'], $body['bet_transaction_id'] ?? null, $this->amount($body), $body['round_id'] ?? null, $game, $body, $request->ip())['balance'],
                default => $this->wallet->balance($user),
            };
        } catch (InsufficientFunds) {
            return $this->error(2006, 'BALANCE_NOT_ENOUGH');
        }

        return response()->json(['code' => 0, 'message' => 'OK', 'data' => ['balance' => (float) $balance]]);
    }

    private function externalUser(User $user): int
    {
        $mapped = CasinoProviderUser::query()->where('provider', 'goldpalace')->where('user_id', $user->id)->first();

        if ($mapped !== null) {
            return (int) $mapped->external_code;
        }

        $created = $this->post('/v4/user/create', ['name' => $this->codes->forUser($user)]);
        $code = (string) $created['data']['user_code'];
        CasinoProviderUser::query()->create([
            'provider' => 'goldpalace',
            'user_id' => $user->id,
            'external_code' => $code,
        ]);

        return (int) $code;
    }

    private function resolveUser(string $code): ?User
    {
        $byPrefix = $this->codes->resolve($code);

        if ($byPrefix !== null) {
            return $byPrefix;
        }

        $mapped = CasinoProviderUser::query()->where('provider', 'goldpalace')->where('external_code', $code)->first();

        return $mapped === null ? null : User::query()->find($mapped->user_id);
    }

    private function lang(Language $language): int
    {
        return match ($language) {
            Language::En, Language::Ar => 1,
            Language::Tr => 7,
            Language::De => 8,
        };
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function amount(array $body): string
    {
        return bcadd((string) ($body['amount'] ?? '0'), '0', 2);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        $url = rtrim((string) config('casino.goldpalace.url'), '/').$path;

        try {
            $response = Http::withToken((string) config('casino.goldpalace.api_token'))
                ->acceptJson()
                ->post($url, $body)
                ->throw()
                ->json();
        } catch (RequestException $exception) {
            Log::error('casino.goldpalace.http', ['message' => SecretMask::mask($exception->getMessage())]);

            throw $exception;
        }

        return is_array($response) ? $response : [];
    }

    private function error(int $code, string $message): JsonResponse
    {
        Log::warning('casino.callback.rejected', ['provider' => 'goldpalace', 'code' => $code]);

        return response()->json(['code' => $code, 'message' => $message]);
    }
}
