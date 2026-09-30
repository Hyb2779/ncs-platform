<?php

namespace App\Services\Casino;

use App\Models\CasinoGame;
use App\Models\CasinoProvider as CasinoProviderModel;
use App\Models\User;
use App\Services\HierarchyService;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * RomaSpin (OroPlay protokolü). Bearer token: POST /auth/createtoken (Redis'te önbellekli, 5 dk erken yenilenir).
 * Seamless callback: {callback_url}/api/balance, /api/transaction, /api/batch-transaction; Basic base64(clientId:clientSecret).
 * Vendor type=1 (canlı casino) ve type=3 (mini oyun) senkronlanır. external_id = vendorCode|gameCode (lobby kodu sağlayıcılar arasında tekrar eder).
 */
class RomaSpinProvider implements CasinoProvider
{
    private const CODE = 'romaspin';

    /** vendor type -> kategori: 1 canlı casino, 3 mini oyun (Aviator, Spribe...). */
    private const TYPES = [1 => 'live', 3 => 'mini'];

    private const TOKEN_KEY = 'casino:romaspin:token';

    public function __construct(
        private readonly CasinoWallet $wallet,
        private readonly CasinoUserCode $codes,
        private readonly HierarchyService $hierarchy,
    ) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function syncGames(): int
    {
        $provider = CasinoProviderModel::query()->firstOrCreate(
            ['code' => self::CODE],
            ['name' => 'RomaSpin', 'status' => 'active', 'is_live' => true],
        );

        $vendors = $this->api('GET', '/vendors/list');
        if ((int) ($vendors['errorCode'] ?? -1) !== 0 || ! is_array($vendors['message'] ?? null)) {
            Log::warning('casino.romaspin.sync_failed', ['step' => 'vendors', 'response' => $vendors]);

            return 0;
        }

        $seen = [];
        $failed = [];
        $order = 0;

        foreach ($vendors['message'] as $vendor) {
            $category = is_array($vendor) ? (self::TYPES[(int) ($vendor['type'] ?? 0)] ?? null) : null;
            if ($category === null) {
                continue;
            }
            $vendorCode = (string) ($vendor['vendorCode'] ?? '');
            if ($vendorCode === '') {
                continue;
            }

            try {
                $list = $this->api('POST', '/games/list', ['vendorCode' => $vendorCode, 'language' => 'en']);
            } catch (\Throwable $e) {
                $failed[] = $vendorCode;
                Log::warning('casino.romaspin.vendor_skipped', ['vendor' => $vendorCode, 'error' => $e->getMessage()]);

                continue;
            }

            if ((int) ($list['errorCode'] ?? -1) !== 0 || ! is_array($list['message'] ?? null)) {
                $failed[] = $vendorCode;
                Log::warning('casino.romaspin.vendor_skipped', ['vendor' => $vendorCode, 'response' => $list]);

                continue;
            }

            $vendorName = (string) ($vendor['name'] ?? $vendorCode);
            foreach ($list['message'] as $game) {
                $gameCode = is_array($game) ? (string) ($game['gameCode'] ?? '') : '';
                if ($gameCode === '') {
                    continue;
                }
                $name = $gameCode === 'lobby' ? $vendorName.' Lobby' : (string) ($game['gameName'] ?? $gameCode);
                $record = CasinoGame::query()->updateOrCreate(
                    ['provider_id' => $provider->id, 'external_id' => $vendorCode.'|'.$gameCode],
                    [
                        'name' => $name,
                        'category' => $category,
                        'image_url' => $game['thumbnail'] ?? null,
                        'is_live' => $category === 'live',
                        'is_active' => ! (bool) ($game['underMaintenance'] ?? false),
                        'vendor' => $vendorCode,
                        'sort_order' => $order++,
                    ],
                );
                $seen[] = $record->id;
            }
        }

        // Listede artık olmayanlar pasif; o turda cevap vermeyen sağlayıcının oyunlarına dokunulmaz.
        CasinoGame::query()->where('provider_id', $provider->id)
            ->whereNotIn('id', $seen ?: [0])
            ->when($failed !== [], fn ($q) => $q->whereNotIn('vendor', $failed))
            ->update(['is_active' => false]);

        Log::info('casino.romaspin.synced', ['games' => count($seen), 'failed_vendors' => $failed]);

        return count($seen);
    }

    public function launch(User $user, CasinoGame $game, string $device): string
    {
        $currency = $user->currency instanceof \BackedEnum ? $user->currency->value : (string) $user->currency;
        if ($currency !== (string) config('casino.romaspin.currency', 'TRY')) {
            throw new \RuntimeException('romaspin currency not supported: '.$currency);
        }

        [$vendorCode, $gameCode] = array_pad(explode('|', (string) $game->external_id, 2), 2, '');
        $language = $user->language instanceof \BackedEnum ? $user->language->value : (string) $user->language;
        $body = [
            'vendorCode' => $vendorCode,
            'gameCode' => $gameCode,
            'userCode' => $this->codes->forUser($user),
            'language' => $language ?: 'en',
            'lobbyUrl' => $game->is_live ? route('site.live_casino') : route('site.mini'),
        ];

        $json = $this->api('POST', '/game/launch-url', $body);
        if ((int) ($json['errorCode'] ?? -1) === 2) {
            $this->api('POST', '/user/create', ['userCode' => $body['userCode']]);
            $json = $this->api('POST', '/game/launch-url', $body);
        }

        $url = $json['message'] ?? null;
        if ((int) ($json['errorCode'] ?? -1) !== 0 || ! is_string($url) || ! str_starts_with($url, 'http')) {
            Log::warning('casino.romaspin.launch_failed', ['game' => $game->external_id, 'response' => $json]);
            throw new \RuntimeException('romaspin launch failed: '.json_encode($json['errorCode'] ?? null));
        }

        return $url;
    }

    public function handleCallback(Request $request): Response
    {
        if (! $this->authorized($request)) {
            Log::warning('casino.romaspin.unauthorized', ['ip' => $request->ip(), 'path' => $request->path()]);

            return $this->respond(false, 0, 401);
        }

        $data = $request->all();
        $path = strtolower(rtrim($request->path(), '/'));
        Log::info('casino.romaspin.callback', ['ip' => $request->ip(), 'path' => $path, 'payload' => $data]);

        if (str_ends_with($path, 'batch-transaction') || str_ends_with($path, 'batch-transactions')) {
            return $this->batch($data, $request->ip());
        }

        if (str_ends_with($path, 'transaction')) {
            [$ok, $balance, $error] = $this->transaction($data, $request->ip());

            return $this->respond($ok, $balance, $error);
        }

        $user = $this->codes->resolve((string) ($data['userCode'] ?? ''));
        if ($user === null) {
            return $this->respond(false, 0, 2);
        }

        return $this->respond(true, (float) $this->wallet->balance($user), 0);
    }

    /** @return array{0: bool, 1: float, 2: int} */
    private function transaction(array $data, ?string $ip): array
    {
        $user = $this->codes->resolve((string) ($data['userCode'] ?? ''));
        if ($user === null) {
            return [false, 0.0, 2];
        }

        $code = (string) ($data['transactionCode'] ?? '');
        if ($code === '') {
            return [false, (float) $this->wallet->balance($user), 400];
        }

        $amount = (float) ($data['amount'] ?? 0);
        $abs = number_format(abs($amount), 2, '.', '');
        $round = isset($data['roundId']) ? (string) $data['roundId'] : null;
        $game = $this->findGame((string) ($data['vendorCode'] ?? ''), (string) ($data['gameCode'] ?? ''));

        if (filter_var($data['isCanceled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $result = $this->wallet->refund($user, self::CODE, $code.':cancel', $code, $abs, $round, $game, $data, $ip);
            if ($result['ignored']) {
                Log::warning('casino.romaspin.cancel_unmatched', ['transactionCode' => $code, 'user' => $user->id]);
            }

            return [true, (float) $result['balance'], 0];
        }

        // Yön: eksi tutar veya "...debit" kodu = bahis. Çelişirse bahis (bakiye eklemektense düşmek güvenli).
        $isDebit = $amount < 0 || str_ends_with(strtolower($code), 'debit');

        if ($isDebit) {
            if ($this->hierarchy->loginBlocked($user)) {
                return [false, (float) $this->wallet->balance($user), 4];
            }
            try {
                $result = $this->wallet->bet($user, self::CODE, $code, $abs, $round, $game, $data, $ip);
            } catch (InsufficientFunds) {
                return [false, (float) $this->wallet->balance($user), 4];
            }

            return [true, (float) $result['balance'], 0];
        }

        $result = $this->wallet->win($user, self::CODE, $code, $abs, $round, $game, $data, $ip);

        return [true, (float) $result['balance'], 0];
    }

    private function batch(array $data, ?string $ip): Response
    {
        $items = $data['transactions'] ?? [];
        if (is_array($items) && array_key_exists('transactionCode', $items)) {
            $items = [$items];
        }

        $balance = 0.0;
        foreach ((array) $items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $item['userCode'] ??= $data['userCode'] ?? null;
            [$ok, $balance, $error] = $this->transaction($item, $ip);
            if (! $ok) {
                return $this->respond(false, $balance, $error);
            }
        }

        if ($items === [] && ($user = $this->codes->resolve((string) ($data['userCode'] ?? ''))) !== null) {
            $balance = (float) $this->wallet->balance($user);
        }

        return $this->respond(true, $balance, 0);
    }

    private function findGame(string $vendorCode, string $gameCode): ?CasinoGame
    {
        if ($vendorCode === '' || $gameCode === '') {
            return null;
        }

        return CasinoGame::query()->where('external_id', $vendorCode.'|'.$gameCode)
            ->whereHas('provider', fn ($q) => $q->where('code', self::CODE))->first();
    }

    private function authorized(Request $request): bool
    {
        $secret = (string) config('casino.romaspin.client_secret');
        if ($secret === '') {
            return false;
        }
        $expected = 'Basic '.base64_encode(config('casino.romaspin.client_id').':'.$secret);

        return hash_equals($expected, (string) $request->header('Authorization', ''));
    }

    private function respond(bool $success, float $balance, int $errorCode): Response
    {
        return response()->json([
            'success' => $success,
            'message' => round($balance, 2),
            'errorCode' => $errorCode,
        ]);
    }

    /** @return array<string, mixed> */
    private function api(string $method, string $path, array $body = []): array
    {
        $send = function (string $token) use ($method, $path, $body): HttpResponse {
            $request = Http::acceptJson()->withToken($token)->timeout(20);

            return $method === 'GET' ? $request->get($this->url($path)) : $request->asJson()->post($this->url($path), $body);
        };

        $response = $send($this->token());
        if ($response->status() === 401) {
            $response = $send($this->token(true));
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function token(bool $fresh = false): string
    {
        if (! $fresh && is_string($cached = Cache::get(self::TOKEN_KEY))) {
            return $cached;
        }

        return Cache::lock(self::TOKEN_KEY.':lock', 15)->block(15, function () use ($fresh): string {
            if (! $fresh && is_string($cached = Cache::get(self::TOKEN_KEY))) {
                return $cached;
            }

            $json = Http::acceptJson()->asJson()->timeout(15)->post($this->url('/auth/createtoken'), [
                'clientId' => (string) config('casino.romaspin.client_id'),
                'clientSecret' => (string) config('casino.romaspin.client_secret'),
            ])->json();

            $token = is_array($json) ? ($json['token'] ?? null) : null;
            if (! is_string($token) || $token === '') {
                Log::error('casino.romaspin.token_failed', ['response' => $json]);
                throw new \RuntimeException('romaspin token failed');
            }

            $ttl = max(60, (int) ($json['expiration'] ?? 0) - time() - 300);
            Cache::put(self::TOKEN_KEY, $token, $ttl);

            return $token;
        });
    }

    private function url(string $path): string
    {
        return rtrim((string) config('casino.romaspin.url'), '/').$path;
    }
}
