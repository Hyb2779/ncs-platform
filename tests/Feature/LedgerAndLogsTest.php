<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\HierarchyService;
use App\Services\WalletProvisioner;
use App\Support\LedgerDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Faz 5: hareket açıklaması (kupon / oyun + round / transfer) ve İşlem/Giriş logu ekranları. */
class LedgerAndLogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ledger_detail_describes_sport_casino_and_falls_back_to_provider(): void
    {
        $sport = new WalletTransaction(['type' => WalletTransactionType::Bet, 'product' => 'sport', 'reference' => 'tipo:742263', 'idempotency_key' => 'tipo:debit-bet-742263']);
        $this->assertSame(__('wallet.detail_coupon', ['no' => '742263']), LedgerDetail::for($sport));

        $named = new WalletTransaction(['type' => WalletTransactionType::Bet, 'product' => 'live_casino', 'reference' => '18daa6c3ced27eb40028aef8', 'idempotency_key' => 'romaspin:1', 'note' => 'Aviator']);
        $this->assertStringStartsWith('Aviator · ', LedgerDetail::for($named));

        $old = new WalletTransaction(['type' => WalletTransactionType::Win, 'product' => 'slot', 'reference' => '2969713510023', 'idempotency_key' => 'goldpalace:x-1', 'note' => null]);
        $this->assertStringStartsWith('GoldPalace · ', LedgerDetail::for($old));
    }

    public function test_transactions_page_shows_detail_and_by_columns(): void
    {
        [$owner, $sa1] = $this->world();
        app(\App\Services\WalletService::class)->transfer($owner, $sa1, '250.00', 'fz5-1', $owner, null, null, Currency::Try);

        $this->actingAs($owner)->get('http://panel.test/panel/transactions?user='.$sa1->id)->assertOk()
            ->assertSee(__('wallet.detail'))->assertSee(__('wallet.by'))
            ->assertSee(__('wallet.detail_load'));
    }

    public function test_action_log_is_scoped_to_tree(): void
    {
        [$owner, $sa1, $sa2, $bayi1] = $this->world();

        $this->actingAs($owner)->get('http://panel.test/panel/logs')->assertOk()
            ->assertSee(__('panel.log_action_user_created'))->assertSee('bayi_one')->assertSee('sa_two');
        $this->actingAs($sa2)->get('http://panel.test/panel/logs')->assertOk()
            ->assertDontSee('bayi_one'); // başka süperadminin ağacı
        $this->actingAs($sa1)->get('http://panel.test/panel/logs')->assertOk()
            ->assertSee('bayi_one');
        $this->actingAs($bayi1)->get('http://panel.test/panel/logs')->assertNotFound();
    }

    public function test_login_log_shows_device_and_unknown_attempts_only_to_root(): void
    {
        [$owner, $sa1, $sa2] = $this->world();
        $ua = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36';

        $this->withHeader('User-Agent', $ua)->post('http://panel.test/login', ['username' => 'sa_one', 'password' => 'password'])->assertSessionHasNoErrors();
        auth()->logout();
        $this->withHeader('User-Agent', $ua)->post('http://panel.test/login', ['username' => 'kimse_yok', 'password' => 'x']);

        $this->actingAs($owner)->get('http://panel.test/panel/logs/logins')->assertOk()
            ->assertSee('Android · Chrome')->assertSee(__('panel.log_action_auth_login'))->assertSee('kimse_yok (?)');
        $this->actingAs($sa1)->get('http://panel.test/panel/logs/logins')->assertOk()
            ->assertSee('Android · Chrome')->assertDontSee('kimse_yok');
        $this->actingAs($sa2)->get('http://panel.test/panel/logs/logins')->assertOk()
            ->assertDontSee('Android · Chrome');
    }

    /** @return array{0: User, 1: User, 2: User, 3: User} */
    private function world(): array
    {
        $owner = User::query()->create([
            'username' => 'owner', 'password' => 'password', 'role' => UserRole::Owner, 'parent_id' => null,
            'path' => '/', 'depth' => 0, 'superadmin_id' => null, 'language' => Language::Tr, 'currency' => Currency::Try,
            'timezone' => 'Europe/Istanbul', 'commission_rate' => 0, 'status' => UserStatus::Active,
        ]);
        $owner->path = '/'.$owner->id.'/';
        $owner->save();
        app(WalletProvisioner::class)->openFor($owner->refresh());

        $h = app(HierarchyService::class);
        $base = ['password' => 'password', 'commission_rate' => 0, 'user_limit' => null, 'note' => null, 'language' => 'tr', 'currency' => 'TRY'];
        $sa1 = $h->create($owner, ['username' => 'sa_one'] + $base);
        $sa2 = $h->create($owner, ['username' => 'sa_two'] + $base);
        $bayi1 = $h->create($sa1, ['username' => 'bayi_one'] + $base);

        return [$owner->refresh(), $sa1->refresh(), $sa2->refresh(), $bayi1->refresh()];
    }
}
