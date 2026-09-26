<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\WalletProduct;
use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\Stats\DailyStatWriter;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OwnerDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_try_tab_sums_superadmin_rows_and_not_bayi_rows(): void
    {
        [$owner, $superadmin, $bayi, $member] = $this->tree();
        $wallets = app(WalletService::class);
        $at = Carbon::parse('2026-06-01 12:00:00', 'Europe/Istanbul');
        $wallets->debit($wallets->walletFor($owner, Currency::Try), '1000.00', WalletTransactionType::TransferOut, WalletProduct::Transfer, 'fund-out', null, $member->id, null, $owner, null, null, $at->copy()->subHour());
        $wallets->credit($wallets->walletFor($member, Currency::Try), '1000.00', WalletTransactionType::TransferIn, WalletProduct::Transfer, 'fund-in', null, $owner->id, null, $owner, null, null, $at->copy()->subMinutes(30));
        $wallets->debit($wallets->walletFor($member, Currency::Try), '123.45', WalletTransactionType::Bet, WalletProduct::Sport, 'bet', null, null, null, null, null, null, $at);
        app(DailyStatWriter::class)->rewriteTree($superadmin, '2026-06-01');

        $page = $this->actingAs($owner)->get(route('panel.dashboard', [
            'currency' => 'TRY',
            'from' => '2026-06-01',
            'to' => '2026-06-01',
        ]));

        $page->assertOk();
        $page->assertSee('123,45 ₺');
        $page->assertDontSee('246,90 ₺');
        $page->assertSee($superadmin->username);
        $page->assertSee('data-chart', false);

        $this->actingAs($owner)->get(route('panel.dashboard', [
            'currency' => 'USD',
            'from' => '2026-06-01',
            'to' => '2026-06-01',
        ]))->assertOk()->assertDontSee('123,45 ₺');

        $this->actingAs($owner)->get(route('panel.network.show', ['user' => $superadmin, 'from' => '2026-06-01', 'to' => '2026-06-01']))->assertOk()->assertSee('123,45 ₺');
        $this->actingAs($bayi)->get(route('panel.network.show', $superadmin))->assertNotFound();
    }

    /**
     * @return array{0: User, 1: User, 2: User, 3: User}
     */
    private function tree(): array
    {
        $owner = User::query()->create([
            'username' => 'owner',
            'password' => 'password',
            'role' => UserRole::Owner,
            'parent_id' => null,
            'path' => '/',
            'depth' => 0,
            'superadmin_id' => null,
            'language' => Language::Tr,
            'currency' => Currency::Try,
            'timezone' => 'UTC',
            'commission_rate' => 0,
            'status' => UserStatus::Active,
        ]);
        $owner->path = '/'.$owner->id.'/';
        $owner->save();
        $hierarchy = app(HierarchyService::class);
        $superadmin = $hierarchy->create($owner, [
            'username' => 'demo-tr',
            'password' => 'password',
            'commission_rate' => 0,
            'user_limit' => null,
            'note' => null,
            'language' => 'tr',
            'currency' => 'TRY',
            'timezone' => 'Europe/Istanbul',
        ]);
        $bayi = $hierarchy->create($superadmin, [
            'username' => 'bayi',
            'password' => 'password',
            'commission_rate' => 0,
            'user_limit' => null,
            'note' => null,
        ]);
        $member = $hierarchy->create($bayi, [
            'username' => 'uye',
            'password' => 'password',
            'commission_rate' => 0,
            'user_limit' => null,
            'note' => null,
        ]);

        return [$owner->refresh(), $superadmin, $bayi, $member];
    }
}
