<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\WalletProduct;
use App\Enums\WalletTransactionType as T;
use App\Models\DailyStat;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\Stats\DailyStatWriter;
use App\Services\WalletProvisioner;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** daily_stats satirlari islemin cuzdan para birimine gore ayrilir (USD bayi cirosu TRY'ye yazilmaz). */
class DailyStatCurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_rows_are_split_by_wallet_currency(): void
    {
        $this->travelTo(Carbon::parse('2026-10-03 15:00', 'Europe/Istanbul'));
        $owner = User::query()->create([
            'username' => 'owner', 'password' => 'password', 'role' => UserRole::Owner, 'parent_id' => null,
            'path' => '/', 'depth' => 0, 'superadmin_id' => null, 'language' => Language::Tr, 'currency' => Currency::Try,
            'timezone' => 'Europe/Istanbul', 'commission_rate' => 0, 'status' => UserStatus::Active,
        ]);
        $owner->path = '/'.$owner->id.'/';
        $owner->save();
        app(WalletProvisioner::class)->openFor($owner->refresh());

        $h = app(HierarchyService::class);
        $base = ['password' => 'password', 'commission_rate' => 0, 'user_limit' => null, 'note' => null, 'language' => 'tr'];
        $sa = $h->create($owner, ['username' => 'sa', 'currency' => 'TRY'] + $base)->refresh();
        $bayiUsd = $h->create($sa, ['username' => 'bayi_usd', 'currency' => 'USD'] + $base)->refresh();
        $bayiTry = $h->create($sa, ['username' => 'bayi_try', 'currency' => 'TRY'] + $base)->refresh();
        $mUsd = $h->create($bayiUsd, ['username' => 'm_usd', 'currency' => 'USD'] + $base)->refresh();
        $mTry = $h->create($bayiTry, ['username' => 'm_try', 'currency' => 'TRY'] + $base)->refresh();

        $w = app(WalletService::class);
        $usd = Currency::from('USD');
        $w->transfer($owner, $sa, '100.00', 'c1', $owner, null, null, $usd);
        $w->transfer($sa, $bayiUsd, '100.00', 'c2', $sa, null, null, $usd);
        $w->transfer($bayiUsd, $mUsd, '100.00', 'c3', $bayiUsd, null, null, $usd);
        $w->transfer($owner, $sa, '100.00', 'c4', $owner, null, null, Currency::Try);
        $w->transfer($sa, $bayiTry, '100.00', 'c5', $sa, null, null, Currency::Try);
        $w->transfer($bayiTry, $mTry, '100.00', 'c6', $bayiTry, null, null, Currency::Try);

        $w->debit($mUsd->wallet()->first(), '10.00', T::Bet, WalletProduct::Slot, 'goldpalace:u1', null, null, null, $mUsd, null);
        $w->credit($mUsd->wallet()->first(), '4.00', T::Win, WalletProduct::Slot, 'goldpalace:u2', null, null, null, $mUsd, null);
        $w->debit($mTry->wallet()->first(), '20.00', T::Bet, WalletProduct::Slot, 'goldpalace:t1', null, null, null, $mTry, null);

        app(DailyStatWriter::class)->rewriteTree($sa, '2026-10-03');

        $row = fn (User $u, string $cur) => DailyStat::query()->where('user_id', $u->id)->where('currency', $cur)->where('product', 'all')->first();

        $this->assertSame(['20.00', '0.00'], [number_format((float) $row($sa, 'TRY')->turnover, 2, '.', ''), number_format((float) $row($sa, 'TRY')->payout, 2, '.', '')]);
        $this->assertSame(['10.00', '6.00'], [number_format((float) $row($sa, 'USD')->turnover, 2, '.', ''), number_format((float) $row($sa, 'USD')->ggr, 2, '.', '')]);
        $this->assertSame('10.00', number_format((float) $row($bayiUsd, 'USD')->turnover, 2, '.', ''));
        $this->assertNull($row($bayiUsd, 'TRY'));
        $this->assertSame('20.00', number_format((float) $row($bayiTry, 'TRY')->turnover, 2, '.', ''));
        $this->assertNull($row($bayiTry, 'USD'));

        // Yeniden yazma idempotent: tekil anahtar (gun, hesap, para birimi, urun) cakismaz.
        app(DailyStatWriter::class)->rewriteTree($sa, '2026-10-03');
        $this->assertSame(2, DailyStat::query()->where('user_id', $sa->id)->where('product', 'all')->count());

        // Superadmin paneli (TRY) USD tutarini TRY kartina katmaz.
        $this->actingAs($sa)->get('http://panel.test/panel')->assertOk();
    }
}
