<?php

namespace App\Services\Casino;

use App\Models\CasinoGame;
use App\Models\CasinoProvider as CasinoProviderModel;
use App\Models\User;
use App\Services\HierarchyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * 1GameX canlı casino. API: POST /GameList, /OpenGame (token + password + signature).
 * İmza: sha1(json(gövde) + secret_key). Callback cevabı: sha1(gelen_imza + secret_key).
 * Callback'ler tek adrese gelir: bet/win varsa play, sadece amount varsa refund, diğer durumda getBalance.
 */
class OneGameXProvider implements CasinoProvider
{
    public function __construct(
        private readonly CasinoWallet $wallet,
        private readonly CasinoUserCode $codes,
        private readonly HierarchyService $hierarchy,
    ) {}

    public function code(): string
    {
        return 'onegamex';
    }

    public function syncGames(): int
    {
        $provider = CasinoProviderModel::query()->updateOrCreate(
            ['code' => 'onegamex'],
            ['name' => '1GameX', 'status' => 'active', 'is_live' => true],
        );

        $json = $this->request('/GameList');
        if ((int) ($json['result'] ?? 0) !== 1) {
            Log::warning('casino.onegamex.sync_failed', ['message' => $json['message'] ?? null]);

            return 0;
        }

        $seen = [];
        $order = 0;
        foreach ((array) ($json['games'] ?? []) as $brand => $games) {
            foreach ((array) $games as $game) {
                if (! is_array($game) || ! isset($game['id'])) {
                    continue;
                }
                // live -> Canlı Casino, virtual -> Sanal Bahis; crashgames/slots/minigames şimdilik pasif.
                $category = strtolower((string) ($game['type'] ?? 'live'));
                $isLive = $category === 'live';
                $record = CasinoGame::query()->updateOrCreate(
                    ['provider_id' => $provider->id, 'external_id' => (string) $game['id']],
                    [
                        'name' => (string) ($game['name'] ?? $game['id']),
                        'category' => $category,
                        'image_url' => $game['thumbnails']['landscape'] ?? ($game['image'] ?? null),
                        'is_live' => $isLive,
                        // 30.09: canlı casino ve mini oyunlar RomaSpin'den; 1GameX (Sanal Bahis dahil) kullanılmıyor.
                        'is_active' => false,
                        'vendor' => strtolower((string) $brand),
                        'sort_order' => $order++,
                    ],
                );
                $seen[] = $record->id;
            }
        }

        CasinoGame::query()->where('provider_id', $provider->id)->whereNotIn('id', $seen ?: [0])->update(['is_active' => false]);

        return count($seen);
    }

    public function launch(User $user, CasinoGame $game, string $device): string
    {
        $json = $this->request('/OpenGame', [
            'userId' => $this->codes->forUser($user),
            'gameId' => (string) $game->external_id,
        ], [
            'language' => $user->language->value,
            'exitURL' => route('site.live_casino'),
        ]);

        $url = $json['url'] ?? $json['gameUrl'] ?? $json['gameURL'] ?? $json['game_url'] ?? $json['launchUrl'] ?? ($json['data']['url'] ?? null);
        if ((int) ($json['result'] ?? 0) !== 1 || ! is_string($url) || $url === '') {
            Log::warning('casino.onegamex.launch_failed', ['game' => $game->external_id, 'response' => $json]);
            throw new \RuntimeException('onegamex launch failed: '.($json['message'] ?? 'no url'));
        }

        return $url;
    }

    public function handleCallback(Request $request): Response
    {
        $data = $request->all();
        $incoming = (string) ($data['signature'] ?? '');
        $unsigned = $data;
        unset($unsigned['signature']);
        $expected = sha1(json_encode($unsigned, JSON_UNESCAPED_SLASHES).$this->secret());
        $valid = $incoming !== '' && hash_equals($expected, $incoming);

        Log::info('casino.onegamex.callback', ['ip' => $request->ip(), 'signature_valid' => $valid, 'keys' => array_keys($data)]);
        if (! $valid && config('casino.onegamex.verify_signature')) {
            return $this->respond(0, 'Invalid signature.', 0, $incoming);
        }

        $user = $this->codes->resolve((string) ($data['userId'] ?? ''));
        if ($user === null) {
            return $this->respond(0, 'User not found.', 0, $incoming);
        }

        $game = isset($data['gameId'])
            ? CasinoGame::query()->where('external_id', (string) $data['gameId'])->whereHas('provider', fn ($q) => $q->where('code', 'onegamex'))->first()
            : null;
        $tx = (string) ($data['transactionId'] ?? '');
        $round = isset($data['roundId']) ? (string) $data['roundId'] : null;
        $ip = $request->ip();

        if (array_key_exists('bet', $data) || array_key_exists('win', $data)) {
            if ($tx === '') {
                return $this->respond(0, 'transactionId required.', (float) $this->wallet->balance($user), $incoming);
            }
            $bet = $this->money($data['bet'] ?? 0);
            $win = $this->money($data['win'] ?? 0);

            if (bccomp($bet, '0', 2) === 1) {
                if ($this->hierarchy->loginBlocked($user)) {
                    return $this->respond(0, 'User blocked.', (float) $this->wallet->balance($user), $incoming);
                }
                try {
                    $this->wallet->bet($user, 'onegamex', $tx.':bet', $bet, $round, $game, $data, $ip);
                } catch (InsufficientFunds) {
                    return $this->respond(0, 'Insufficient funds.', (float) $this->wallet->balance($user), $incoming);
                }
            }
            $this->wallet->win($user, 'onegamex', $tx.':win', $win, $round, $game, $data, $ip);

            return $this->respond(1, 'OK', (float) $this->wallet->balance($user), $incoming);
        }

        if (array_key_exists('amount', $data)) {
            if ($tx !== '') {
                $this->wallet->refund($user, 'onegamex', $tx.':refund', $tx.':bet', $this->money($data['amount']), $round, $game, $data, $ip);
            }

            return $this->respond(1, 'OK', (float) $this->wallet->balance($user), $incoming);
        }

        return $this->respond(1, 'OK', (float) $this->wallet->balance($user), $incoming);
    }

    private function respond(int $result, string $message, float $balance, string $incoming): Response
    {
        return response()->json([
            'result' => $result,
            'message' => $message,
            'balance' => $balance,
            'signature' => sha1($incoming.$this->secret()),
        ]);
    }

    /** @return array<string, mixed> */
    private function request(string $path, array $fields = [], array $query = []): array
    {
        $payload = array_merge([
            'token' => (string) config('casino.onegamex.token_name'),
            'password' => (string) config('casino.onegamex.password'),
        ], $fields);
        $payload['signature'] = sha1(json_encode($payload, JSON_UNESCAPED_SLASHES).$this->secret());

        $json = Http::acceptJson()->asJson()->timeout(20)->withQueryParameters($query)
            ->post(rtrim((string) config('casino.onegamex.url'), '/').$path, $payload)->json();

        return is_array($json) ? $json : [];
    }

    private function secret(): string
    {
        return (string) config('casino.onegamex.secret_key');
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
