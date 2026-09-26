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

class SuperadminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_superadmin_cannot_open_another_trees_agent(): void
    {
        $owner = $this->owner();
        $hierarchy = app(HierarchyService::class);
        $first = $hierarchy->create($owner, $this->payload('demo-tr', [
            'language' => 'tr',
            'currency' => 'TRY',
            'timezone' => 'Europe/Istanbul',
        ]));
        $second = $hierarchy->create($owner, $this->payload('demo-us', [
            'language' => 'en',
            'currency' => 'USD',
            'timezone' => 'America/New_York',
        ]));
        $bayi = $hierarchy->create($first, $this->payload('demo-tr-bayi'));
        $member = $hierarchy->create($bayi, $this->payload('demo-tr-uye'));
        $wallets = app(WalletService::class);
        $at = Carbon::parse('2026-06-01 12:00:00', 'Europe/Istanbul');
        $wallets->credit($wallets->walletFor($member, Currency::Try), '100.00', WalletTransactionType::TransferIn, WalletProduct::Transfer, 'in', null, $owner->id, null, $owner, null, null, $at->copy()->subHour());
        $wallets->debit($wallets->walletFor($owner, Currency::Try), '100.00', WalletTransactionType::TransferOut, WalletProduct::Transfer, 'out', null, $member->id, null, $owner, null, null, $at->copy()->subHours(2));
        $wallets->debit($wallets->walletFor($member, Currency::Try), '40.00', WalletTransactionType::Bet, WalletProduct::Sport, 'bet', null, null, null, null, null, null, $at);
        app(DailyStatWriter::class)->rewriteTree($first, '2026-06-01');

        $this->actingAs($second)->get(route('panel.network.show', $bayi))->assertNotFound();
        $this->actingAs($first)->get(route('panel.network.show', [
            'user' => $bayi,
            'from' => '2026-06-01',
            'to' => '2026-06-01',
        ]))->assertOk()->assertSee('40,00 ₺');

        $home = $this->actingAs($first)->get(route('panel.dashboard', [
            'from' => '2026-06-01',
            'to' => '2026-06-01',
        ]));
        $home->assertOk();
        $home->assertSee($bayi->username);
        $home->assertSee(__('panel.subtree_balance'));
        $home->assertSee(__('panel.new_players'));
    }

    public function test_the_arabic_dashboard_is_rtl_and_uses_arabic_labels(): void
    {
        $owner = $this->owner();
        $superadmin = app(HierarchyService::class)->create($owner, $this->payload('demo-ar', [
            'language' => 'ar',
            'currency' => 'USD',
            'timezone' => 'Asia/Riyadh',
        ]));

        $page = $this->actingAs($superadmin)->get(route('panel.dashboard'));

        $page->assertOk();
        $page->assertSee('dir="rtl"', false);
        $page->assertSee(__('panel.overview'));
        $page->assertSee(__('site.sport'));
        $page->assertSee('data-chart', false);
    }

    public function test_a_bayi_sees_the_skeleton_without_the_agent_ranking(): void
    {
        $owner = $this->owner();
        $hierarchy = app(HierarchyService::class);
        $superadmin = $hierarchy->create($owner, $this->payload('sa', [
            'language' => 'tr',
            'currency' => 'TRY',
            'timezone' => 'Europe/Istanbul',
        ]));
        $bayi = $hierarchy->create($superadmin, $this->payload('bayi'));

        $page = $this->actingAs($bayi)->get(route('panel.dashboard'));

        $page->assertOk();
        $page->assertSee(__('panel.period_turnover'));
        $page->assertDontSee(__('panel.chart_ggr_rank'));
    }

    private function owner(): User
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

        return $owner->refresh();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(string $username, array $extra = []): array
    {
        return array_merge([
            'username' => $username,
            'password' => 'password',
            'commission_rate' => 0,
            'user_limit' => null,
            'note' => null,
        ], $extra);
    }
}
