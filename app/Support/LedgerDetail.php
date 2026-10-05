<?php

namespace App\Support;

use App\Enums\WalletTransactionType;
use App\Models\WalletTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hareket satırı için tek satırlık açıklama: spor → kupon no, casino → oyun adı (note) veya sağlayıcı + round,
 * transfer → yükleme / geri alma. Geçmiş casino kayıtlarında oyun adı yok (03.10'dan önce note boş).
 */
class LedgerDetail
{
    private const PROVIDERS = ['goldpalace' => 'GoldPalace', 'romaspin' => 'RomaSpin', '1gamex' => '1GameX', 'tipo' => 'Wegas Spor'];

    public static function for(WalletTransaction $row): string
    {
        $product = $row->product instanceof \BackedEnum ? $row->product->value : (string) $row->product;
        $ref = (string) $row->reference;
        $prefix = strtolower(strstr((string) $row->idempotency_key, ':', true) ?: '');

        if (in_array($row->type, [WalletTransactionType::TransferIn, WalletTransactionType::TransferOut], true)) {
            return '';
        }

        if ($product === 'sport') {
            $no = str_contains($ref, ':') ? substr($ref, strpos($ref, ':') + 1) : $ref;

            return $no !== '' ? __('wallet.detail_coupon', ['no' => $no]) : (string) $row->note;
        }

        if (in_array($product, ['slot', 'live_casino', 'mini', 'virtual'], true)) {
            $title = trim((string) $row->note) ?: (self::PROVIDERS[$prefix] ?? ucfirst($prefix));
            $round = $ref !== '' ? __('wallet.detail_round', ['round' => mb_strimwidth($ref, 0, 12, '…')]) : '';

            return trim($title.($round !== '' ? ' · '.$round : ''), ' ·');
        }

        return (string) $row->note;
    }

    /**
     * Eski casino satırlarında note boşsa oyun adını yalnızca ekran için doldurur.
     *
     * @param  Collection<int, WalletTransaction>  $rows
     * @return Collection<int, WalletTransaction>
     */
    public static function withGameNames(Collection $rows): Collection
    {
        $keys = $rows->filter(fn (WalletTransaction $row) => ($row->note === null || $row->note === '')
            && str_contains((string) $row->idempotency_key, ':')
            && ! in_array($row->type, [WalletTransactionType::TransferIn, WalletTransactionType::TransferOut], true))
            ->pluck('idempotency_key');
        if ($keys->isEmpty()) {
            return $rows;
        }
        $txIds = $keys->map(fn ($key) => substr((string) $key, strpos((string) $key, ':') + 1))->unique()->values()->all();
        $names = DB::table('game_rounds')
            ->join('casino_games', 'casino_games.id', '=', 'game_rounds.game_id')
            ->whereIn('game_rounds.provider_transaction_id', $txIds)
            ->get(['game_rounds.provider', 'game_rounds.provider_transaction_id', 'casino_games.name'])
            ->mapWithKeys(fn ($r) => [$r->provider.':'.$r->provider_transaction_id => $r->name]);
        foreach ($rows as $row) {
            if (($row->note === null || $row->note === '') && isset($names[$row->idempotency_key])) {
                $row->setAttribute('note', $names[$row->idempotency_key]);
            }
        }

        return $rows;
    }
}
