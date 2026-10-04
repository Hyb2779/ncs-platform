<?php

namespace App\Console\Commands;

use App\Models\TipoCoupon;
use App\Models\WalletTransaction;
use App\Services\Sport\NcsBridge;
use Carbon\Carbon;
use Illuminate\Console\Command;

class TipoSyncCoupons extends Command
{
    protected $signature = 'tipo:sync-coupons';

    protected $description = 'Wegas Spor (Tipo) kuponlarini NCS koprusunden ceker (tipo_coupons).';

    public function handle(NcsBridge $bridge): int
    {
        $userIds = WalletTransaction::query()->where('reference', 'like', 'tipo:%')
            ->distinct()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $count = 0;

        foreach (array_chunk($userIds, 80) as $chunk) {
            for ($page = 1; $page <= 50; $page++) {
                $result = $bridge->coupons($chunk, $page, 100);
                if ($result === null) {
                    $this->error('Kopru hatasi; senkron yarida kaldi.');

                    return self::FAILURE;
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

        $this->info($count.' kupon senkronlandi.');

        return self::SUCCESS;
    }

    /** @param  list<int>  $allowed  bu istekte sorulan uyeler */
    private function store(array $row, array $allowed): bool
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
