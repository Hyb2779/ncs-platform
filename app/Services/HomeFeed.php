<?php

namespace App\Services;

use App\Enums\Currency;
use App\Models\CasinoGame;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Ana sayfa (vitrin) verisi. */
class HomeFeed
{
    public function build(?User $viewer): array
    {
        $availability = app(\App\Services\Casino\GameAvailability::class);

        return [
            'quick' => [
                'slots' => $availability->apply(CasinoGame::query()->where('is_active', true)->where('is_live', false)
                    ->where(fn ($q) => $q->whereNull('category')->orWhereNotIn('category', ['mini', 'virtual'])), $viewer)->count(),
                'mini' => $availability->apply(CasinoGame::query()->where('is_active', true)->where('category', 'mini'), $viewer)->count(),
                'casino' => $availability->apply(CasinoGame::query()->where('is_active', true)->where('is_live', true), $viewer)->count(),
            ],
            'winners' => $this->winners($viewer),
        ];
    }

    private function winners(?User $viewer): array
    {
        if ($viewer === null || $viewer->superadmin_id === null) {
            return [];
        }

        return DB::table('wallet_transactions as t')
            ->join('wallets as w', 'w.id', '=', 't.wallet_id')
            ->join('users as u', 'u.id', '=', 'w.user_id')
            ->where('t.type', 'win')
            ->whereIn('t.product', ['slot', 'sport', 'live_casino'])
            ->where('u.superadmin_id', $viewer->superadmin_id)
            ->where('u.role', 'uye')
            ->where('t.created_at', '>=', now()->subDays(2))
            ->orderByDesc('t.amount')
            ->limit(6)
            ->get(['u.username', 't.amount', 't.product', 'w.currency'])
            ->map(fn ($r) => [
                'user' => mb_substr($r->username, 0, 2).'***'.mb_substr($r->username, -2),
                'product' => __('home.product_'.$r->product),
                'amount' => \App\Support\Money::format((string) $r->amount, Currency::from($r->currency)),
            ])->all();
    }
}
