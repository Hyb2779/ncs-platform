<?php

namespace App\Services\Sport;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Wegas -> NCS VIP imzali kopru (HMAC-SHA256 zaman.govde). Hata durumunda null. */
class NcsBridge
{
    public function post(string $path, array $payload): ?array
    {
        $raw = json_encode($payload);
        $ts = (string) time();

        try {
            $response = Http::withHeaders([
                'X-Bridge-Timestamp' => $ts,
                'X-Bridge-Signature' => hash_hmac('sha256', $ts.'.'.$raw, (string) config('services.ncs_bridge.secret')),
                'Accept' => 'application/json',
            ])->timeout(25)->withBody($raw, 'application/json')
                ->post(rtrim((string) config('services.ncs_bridge.url'), '/').$path);
        } catch (\Throwable $e) {
            Log::warning('ncs_bridge_failed', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful() || ! $response->json('success')) {
            Log::warning('ncs_bridge_failed', ['path' => $path, 'status' => $response->status()]);

            return null;
        }

        return $response->json();
    }

    /** @param  list<int>  $userIds  en fazla 80 */
    public function coupons(array $userIds, int $page = 1, int $limit = 100): ?array
    {
        return $this->post('/callback/wegas-coupons', ['user_ids' => array_values($userIds), 'page' => $page, 'limit' => $limit]);
    }

    public function coupon(int $userId, int $betId): ?array
    {
        $result = $this->post('/callback/wegas-coupon', ['user_id' => $userId, 'bet_id' => $betId]);

        return is_array($result['coupon'] ?? null) ? $result['coupon'] : null;
    }
}
