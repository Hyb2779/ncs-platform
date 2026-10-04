<?php

namespace App\Services\Sport;

use App\Models\TipoCoupon;
use Carbon\Carbon;

/** Tipo kuponlarini NCS koprusunden ceker ve tipo_coupons'a yazar (komut + site anlik yenileme). */
class TipoCouponSync
{
    public function __construct(private readonly NcsBridge $bridge) {}

    /** @param  list<int>  $userIds  @return int|null  null = kopru hatasi */
    public function syncUsers(array $userIds): ?int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), fn ($id) => $id > 0)));
        $count = 0;

        foreach (array_chunk($ids, 80) as $chunk) {
            for ($page = 1; $page <= 50; $page++) {
                $result = $this->bridge->coupons($chunk, $page, 100);
                if ($result === null) {
                    return null;
                }
                $rows = (array) ($result['coupons'] ?? []);
                foreach ($rows as $row) {
                    if (is_array($row) && $this->store($row, $chunk)) {
                        $count++;
                    }
                }
                if (count($rows) < 100 || $page * 100 >= (int) ($result['total'] ?? 0)) {
                    break;
                }
            }
        }

        return $count;
    }

    /**
     * Detay tazeleme (Bahis Yogunlugu icin): acik kuponlar 5 dk'dan eskiyse, yeni sonuclanan (detayi sifirlanmis) kuponlar bir kez.
     * Tur basina en fazla $limit kupon. Doner: tazelenen adet.
     */
    public function refreshDetails(int $limit = 100): int
    {
        $due = TipoCoupon::query()
            ->where(function ($q) {
                $q->where(fn ($open) => $open
                    ->where(fn ($s) => $s->whereNull('status_label')->orWhereNotIn('status_label', TipoCoupon::SETTLED))
                    ->where(fn ($t) => $t->whereNull('detail_fetched_at')->orWhere('detail_fetched_at', '<', now()->subMinutes(5))))
                  ->orWhere(fn ($settled) => $settled->whereNull('detail')->where('placed_at', '>=', now()->subDays(3)));
            })
            ->orderByDesc('placed_at')->limit($limit)->get();

        $done = 0;
        foreach ($due as $coupon) {
            $detail = $this->bridge->coupon((int) $coupon->user_id, (int) $coupon->bet_id);
            if ($detail !== null) {
                $coupon->storeDetail($detail);
                $done++;
            }
        }

        return $done;
    }

    /** @param  list<int>  $allowed  bu istekte sorulan uyeler */
    public function store(array $row, array $allowed): bool
    {
        if (! preg_match('/^wegas:(\d+)$/', (string) ($row['player_id'] ?? ''), $m) || ! in_array((int) $m[1], $allowed, true)) {
            return false;
        }
        $betId = (int) ($row['bet_id'] ?? 0);
        if ($betId <= 0) {
            return false;
        }

        $placed = isset($row['created_ts']) && is_numeric($row['created_ts'])
            ? Carbon::createFromTimestamp((int) $row['created_ts'])
            : (isset($row['created_at']) ? Carbon::parse((string) $row['created_at'], 'Europe/Istanbul')->utc() : null);

        $coupon = TipoCoupon::query()->firstOrNew(['bet_id' => $betId]);
        $coupon->fill([
            'user_id' => (int) $m[1],
            'currency' => (string) ($row['currency'] ?? 'TRY'),
            'type' => $row['type'] ?? null,
            'live' => (bool) ($row['live'] ?? false),
            'status' => isset($row['status']) ? (int) $row['status'] : null,
            'status_label' => isset($row['status_label']) ? (string) $row['status_label'] : null,
            'stake' => (float) ($row['total_stake'] ?? $row['stake'] ?? 0),
            'total_odds' => (float) ($row['total_odds'] ?? 0),
            'potential_win' => (float) ($row['potential_win'] ?? 0),
            'payout' => (float) ($row['payout'] ?? 0),
            'selection_count' => (int) ($row['selection_count'] ?? 0),
            'won_count' => (int) ($row['won_count'] ?? 0),
            'placed_at' => $placed,
        ]);
        if ($coupon->exists && $coupon->isDirty(['status', 'status_label', 'payout', 'won_count'])) {
            $coupon->detail = null;
            $coupon->detail_fetched_at = null;
        }
        $coupon->synced_at = now();
        $coupon->save();

        return true;
    }
}
