<?php

namespace App\Services\Casino;

use App\Enums\Language;
use App\Enums\UserStatus;
use App\Models\CasinoGame;
use App\Models\CasinoProvider as CasinoProviderModel;
use App\Models\CasinoProviderUser;
use App\Models\GameRound;
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
        $payload = [
            'user_code' => $this->externalUser($user),
            'provider_id' => (int) $vendorId,
            'game_symbol' => $symbol,
            'lang' => $this->lang($user->language),
            'return_url' => url('/slots'),
        ];
        if ($device === 'mobile') {
            $payload['mobile'] = 1;
            $payload['is_mobile'] = 1;
        }

        $result = $this->post('/v4/game/game-url', $payload);
        $url = (string) ($result['data']['game_url'] ?? '');

        if ($url === '') {
            throw new \RuntimeException('goldpalace game-url returned no url');
        }

        return $url;
    }

    public function handleCallback(Request $request): Response
    {
        $token = (string) $request->header('Callback-Token', '');
        $expected = (string) config('casino.goldpalace.callback_token');

        if ($expected === '' || ! hash_equals($expected, $token)) {
            return $this->fail('INVALID_TOKEN', 401);
        }

        $command = strtolower((string) $request->input('command', ''));
        $data = $request->input('data', []);
        $data = is_array($data) ? $data : [];

        if ($command === 'bonuscall' || $command === 'bonus_call') {
            $command = 'win';
        }

        Log::info('casino.goldpalace.callback', [
            'command' => $command !== '' ? $command : null,
            'account' => isset($data['account']) ? (string) $data['account'] : null,
            'data_keys' => array_keys($data),
        ]);

        return match ($command) {
            'authenticate' => $this->handleAuthenticate($data),
            'balance' => $this->handleBalance($data),
            'bet' => $this->handleBet($data, $request),
            'win' => $this->handleWin($data, $request),
            'cancel' => $this->handleCancel($data, $request),
            'status' => $this->handleStatus($data),
            default => $this->fail('UNKNOWN_COMMAND'),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function handleAuthenticate(array $data): JsonResponse
    {
        $account = (string) ($data['account'] ?? '');
        $user = $this->resolveUser($account);
        if ($user === null) {
            return $this->fail('USER_NOT_FOUND');
        }
        if ($deny = $this->rejectInactive($user)) {
            return $deny;
        }

        return $this->ok([
            'account' => $account,
            'balance' => (float) $this->wallet->balance($user),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function handleBalance(array $data): JsonResponse
    {
        $user = $this->resolveUser((string) ($data['account'] ?? ''));
        if ($user === null) {
            return $this->fail('USER_NOT_FOUND');
        }

        return $this->ok(['balance' => (float) $this->wallet->balance($user)]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function handleBet(array $data, Request $request): JsonResponse
    {
        $user = $this->resolveUser((string) ($data['account'] ?? ''));
        if ($user === null) {
            return $this->fail('USER_NOT_FOUND');
        }
        if ($deny = $this->rejectInactive($user)) {
            return $deny;
        }

        try {
            $result = $this->wallet->bet(
                $user,
                'goldpalace',
                (string) ($data['trans_guid'] ?? ''),
                $this->amount($data),
                $this->roundId($data),
                $this->findGame($data),
                $data,
                $request->ip(),
            );
        } catch (InsufficientFunds) {
            return $this->fail('INSUFFICIENT_BALANCE');
        }

        return $this->ok(['balance' => (float) $result['balance']]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function handleWin(array $data, Request $request): JsonResponse
    {
        $user = $this->resolveUser((string) ($data['account'] ?? ''));
        if ($user === null) {
            return $this->fail('USER_NOT_FOUND');
        }

        $result = $this->wallet->win(
            $user,
            'goldpalace',
            (string) ($data['trans_guid'] ?? ''),
            $this->amount($data),
            $this->roundId($data),
            $this->findGame($data),
            $data,
            $request->ip(),
        );

        return $this->ok(['balance' => (float) $result['balance']]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function handleCancel(array $data, Request $request): JsonResponse
    {
        $user = $this->resolveUser((string) ($data['account'] ?? ''));
        if ($user === null) {
            return $this->fail('USER_NOT_FOUND');
        }

        $originalId = isset($data['cancel_trans_guid']) ? (string) $data['cancel_trans_guid'] : '';
        $amount = $this->amount($data);
        if ($originalId !== '') {
            $original = GameRound::query()
                ->where('provider', 'goldpalace')
                ->where('provider_transaction_id', $originalId)
                ->first();
            if ($original !== null) {
                $stake = bccomp((string) $original->bet, '0', 2) === 1
                    ? (string) $original->bet
                    : (string) $original->win;
                if (bccomp($stake, '0', 2) === 1) {
                    $amount = bcadd($stake, '0', 2);
                }
            }
        }

        $result = $this->wallet->refund(
            $user,
            'goldpalace',
            (string) ($data['trans_guid'] ?? ''),
            $originalId !== '' ? $originalId : null,
            $amount,
            $this->roundId($data),
            $this->findGame($data),
            $data,
            $request->ip(),
        );

        return $this->ok(['balance' => (float) $result['balance']]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function handleStatus(array $data): JsonResponse
    {
        $trans = GameRound::query()
            ->where('provider', 'goldpalace')
            ->where('provider_transaction_id', (string) ($data['trans_guid'] ?? ''))
            ->first();

        if ($trans === null) {
            return $this->fail('TRANS_NOT_FOUND');
        }

        return $this->ok([
            'account' => (string) ($data['account'] ?? ''),
            'trans_guid' => $trans->provider_transaction_id,
            'trans_status' => $trans->status === 'refunded' ? 'CANCELLED' : 'OK',
        ]);
    }

    private function rejectInactive(User $user): ?JsonResponse
    {
        if ($user->status !== UserStatus::Active) {
            return $this->fail('USER_DISABLED');
        }

        return null;
    }

    private function externalUser(User $user): int
    {
        $mapped = CasinoProviderUser::query()->where('provider', 'goldpalace')->where('user_id', $user->id)->first();

        if ($mapped !== null) {
            return (int) $mapped->external_code;
        }

        $created = $this->createUser($this->codes->forUser($user));
        $code = $created['data']['user_code'] ?? null;

        if ($code === null || $code === '') {
            throw new \RuntimeException('goldpalace user create returned no user_code');
        }

        CasinoProviderUser::query()->create([
            'provider' => 'goldpalace',
            'user_id' => $user->id,
            'external_code' => (string) $code,
        ]);

        return (int) $code;
    }

    /**
     * @return array<string, mixed>
     */
    private function createUser(string $name): array
    {
        $result = $this->request('/v4/user/create', ['name' => $name]);
        if ((int) ($result['code'] ?? 1) !== 0) {
            usleep(350000);
            $result = $this->request('/v4/user/create', ['name' => $name]);
        }
        if ((int) ($result['code'] ?? 1) !== 0) {
            Log::error('casino.goldpalace.http', [
                'path' => '/v4/user/create',
                'code' => $result['code'] ?? null,
                'message' => SecretMask::mask((string) ($result['message'] ?? '')),
            ]);

            throw new \RuntimeException('goldpalace request failed');
        }

        return $result;
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
            Language::En => 1,
            Language::Tr => 7,
            Language::De => 10,
            Language::Ar => 15,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function findGame(array $data): ?CasinoGame
    {
        $gameCode = isset($data['game_code']) ? (string) $data['game_code'] : '';
        if ($gameCode === '') {
            return null;
        }

        $vendorId = $data['provider_id'] ?? null;
        if ($vendorId !== null && $vendorId !== '') {
            $byVendor = CasinoGame::query()->where('external_id', $vendorId.':'.$gameCode)->first();
            if ($byVendor !== null) {
                return $byVendor;
            }
        }

        return CasinoGame::query()->where('external_id', $gameCode)->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function roundId(array $data): ?string
    {
        $roundId = $data['round_id'] ?? null;

        return $roundId === null || $roundId === '' ? null : (string) $roundId;
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
        $response = $this->request($path, $body);

        if ((int) ($response['code'] ?? 1) !== 0) {
            Log::error('casino.goldpalace.http', [
                'path' => $path,
                'code' => $response['code'] ?? null,
                'message' => SecretMask::mask((string) ($response['message'] ?? '')),
            ]);

            throw new \RuntimeException('goldpalace request failed');
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function request(string $path, array $body): array
    {
        $url = rtrim((string) config('casino.goldpalace.url'), '/').$path;

        try {
            $response = Http::withToken((string) config('casino.goldpalace.api_token'))
                ->acceptJson()
                ->asJson()
                ->timeout(30)
                ->connectTimeout(10)
                ->withOptions(['force_ip_resolve' => 'v4'])
                ->post($url, $body ?: (object) []);
            $json = $response->json();
        } catch (RequestException $exception) {
            Log::error('casino.goldpalace.http', ['path' => $path, 'message' => SecretMask::mask($exception->getMessage())]);

            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('casino.goldpalace.http', ['path' => $path, 'message' => SecretMask::mask($exception->getMessage())]);

            return ['code' => 1, 'message' => 'TIMEOUT'];
        }

        if (! is_array($json)) {
            Log::error('casino.goldpalace.http', [
                'path' => $path,
                'status' => $response->status(),
                'body' => SecretMask::mask($response->body()),
            ]);

            return ['code' => 1, 'message' => 'EMPTY_RESPONSE'];
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function ok(array $data): JsonResponse
    {
        return response()->json([
            'result' => 0,
            'status' => 'OK',
            'data' => $data,
        ]);
    }

    private function fail(string $status, int $http = 200): JsonResponse
    {
        Log::warning('casino.callback.rejected', ['provider' => 'goldpalace', 'status' => $status]);

        return response()->json(['result' => 1, 'status' => $status], $http);
    }
}
