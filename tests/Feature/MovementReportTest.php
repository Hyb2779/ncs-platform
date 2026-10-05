<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\WalletProduct;
use App\Enums\WalletTransactionType;
use App\Models\CasinoGame;
use App\Models\CasinoProvider;
use App\Models\GameRound;
use App\Models\TipoCoupon;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\WalletService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MovementReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_movements_follow_the_tree_and_keep_currencies_apart(): void
    {
        [$owner, $sa, $otherSa, $bayi, $member, $otherMember, $usdMember] = $this->network();
        $wallets = app(WalletService::class);
        $wallets->transfer($owner, $sa, '500.00', 'fund-try', $owner, null, null, Currency::Try);
        $wallets->transfer($owner, $sa, '200.00', 'fund-usd', $owner, null, null, Currency::Usd);
        $wallets->transfer($sa, $member, '100.00', 'load-try', $sa);
        $wallets->transfer($member, $sa, '25.00', 'back-try', $sa);
        $wallets->transfer($sa, $usdMember, '40.00', 'load-usd', $sa);
        $wallets->transfer($owner, $otherSa, '80.00', 'fund-other', $owner, null, null, Currency::Try);
        $wallets->transfer($otherSa, $otherMember, '80.00', 'load-other', $otherSa);

        $page = $this->actingAs($sa)->get('/panel/member-movements');
        $page->assertOk()
            ->assertSee(__('panel.member_movements'))
            ->assertSee($member->username)
            ->assertSee($usdMember->username)
            ->assertSee($sa->username)
            ->assertDontSee($otherMember->username)
            ->assertSee(Money::format('100.00', Currency::Try), false)
            ->assertSee(Money::format('25.00', Currency::Try), false)
            ->assertSee(Money::format('75.00', Currency::Try), false)
            ->assertSee(Money::format('40.00', Currency::Usd), false)
            ->assertSee('TRY', false)
            ->assertSee('USD', false)
            ->assertDontSee(Money::format('140.00', Currency::Try), false)
            ->assertDontSee(Money::format('140.00', Currency::Usd), false);

        $this->actingAs($sa)->get('/panel/member-movements?member='.$member->id)
            ->assertOk()->assertSee(Money::format('100.00', Currency::Try), false)->assertDontSee(Money::format('40.00', Currency::Usd), false);
        $this->actingAs($sa)->get('/panel/member-movements?direction=withdraw')
            ->assertOk()->assertSee(Money::format('25.00', Currency::Try), false)->assertDontSee(Money::format('100.00', Currency::Try), false);
        $this->actingAs($sa)->get('/panel/member-movements?period=yesterday')
            ->assertOk()->assertDontSee(Money::format('100.00', Currency::Try), false);
        $this->actingAs($sa)->get('/panel/member-movements?member='.$otherMember->id)->assertNotFound();

        $this->actingAs($bayi)->get('/panel/member-movements')
            ->assertOk()->assertSee($member->username)->assertDontSee($otherMember->username)->assertDontSee($usdMember->username);
        $this->actingAs($bayi)->get('/panel/member-movements?member='.$usdMember->id)->assertNotFound();

        $this->actingAs($sa)->get('/panel')->assertOk()->assertSee(__('panel.member_movements'), false);
    }

    public function test_sub_owner_sees_only_their_branch(): void
    {
        $root = $this->rawUser('root_owner', UserRole::Owner, null);
        $sub = $this->rawUser('volkan_owner', UserRole::Owner, $root);
        $h = app(HierarchyService::class);
        $base = ['password' => 'password', 'commission_rate' => 0, 'user_limit' => null, 'note' => null, 'language' => 'tr', 'currency' => 'TRY'];
        $rootSa = $h->create($root, ['username' => 'yusuf_sa'] + $base);
        $subSa = $h->create($sub, ['username' => 'volkan_sa'] + $base);
        $rootBayi = $h->create($rootSa, ['username' => 'yusuf_bayi'] + $base);
        $subBayi = $h->create($subSa, ['username' => 'volkan_bayi'] + $base);
        $rootMember = $h->create($rootBayi, ['username' => 'yusuf_uye'] + $base);
        $subMember = $h->create($subBayi, ['username' => 'volkan_uye'] + $base);
        $wallets = app(WalletService::class);
        $wallets->transfer($root, $rootSa, '50.00', 'root-fund', $root, null, null, Currency::Try);
        $wallets->transfer($root, $subSa, '60.00', 'sub-fund', $root, null, null, Currency::Try);
        $wallets->transfer($rootSa, $rootMember, '50.00', 'root-load', $rootSa);
        $wallets->transfer($subSa, $subMember, '60.00', 'sub-load', $subSa);

        $this->actingAs($sub)->get('/panel/member-movements')
            ->assertOk()->assertSee('volkan_uye')->assertDontSee('yusuf_uye')
            ->assertSee(Money::format('60.00', Currency::Try), false)
            ->assertDontSee(Money::format('50.00', Currency::Try), false);
        $this->actingAs($sub)->get('/panel/member-movements?member='.$rootMember->id)->assertNotFound();
        $this->actingAs($root)->get('/panel/member-movements')->assertOk()->assertSee('volkan_uye')->assertSee('yusuf_uye');
    }

    public function test_dealer_ledger_lists_credit_only_and_recalculates_difference(): void
    {
        [$owner, $sa, , $bayi, $member] = $this->network();
        $wallets = app(WalletService::class);
        $wallets->transfer($owner, $sa, '2000.00', 'fund-sa', $owner, null, null, Currency::Try);
        $wallets->transfer($sa, $bayi, '1000.00', 'to-bayi-a', $sa);
        $second = app(HierarchyService::class)->create($sa, $this->payload('bayi_b'));
        $wallets->transfer($sa, $second, '400.00', 'to-bayi-b', $sa);
        $wallets->transfer($second, $member, '50.00', 'bayi-b-down', $second);
        $wallets->debit($member->wallet()->first(), '17.35', WalletTransactionType::Bet, WalletProduct::Sport, 'tipo:game-1', 'tipo:9001', null, 'Aviator', $member);

        $page = $this->actingAs($sa)->get('/panel/transactions');
        $page->assertOk()
            ->assertSee(__('panel.dealer_movements_all'))
            ->assertSee($bayi->username)
            ->assertSee($second->username)
            ->assertSee(Money::format('1400.00', Currency::Try), false)
            ->assertSee(Money::format('50.00', Currency::Try), false)
            ->assertSee(Money::format('1350.00', Currency::Try), false)
            ->assertDontSee(Money::format('17.35', Currency::Try), false)
            ->assertDontSee('Aviator');
        $html = $page->getContent();
        $this->assertDoesNotMatchRegularExpression('/<option[^>]*value="'.$member->id.'"/', $html);
        $mobile = substr($html, (int) strpos($html, 'grid gap-3 md:hidden'));
        preg_match_all('/<article[\s\S]*?<details>/', $mobile, $cards);
        $primary = implode("\n", $cards[0]);
        $this->assertStringContainsString($bayi->username, $primary);
        $this->assertStringContainsString($second->username, $primary);
        $this->assertStringNotContainsString($member->username, $primary);
        $this->actingAs($sa)->get('/panel/transactions?user='.$member->id)->assertNotFound();
        $this->actingAs($sa)->get('/panel/transactions?user='.$bayi->id)->assertOk()->assertSee($bayi->username);
    }

    public function test_player_movements_filter_games_and_link_to_existing_detail(): void
    {
        [$owner, $sa, , $bayi, $member] = $this->network();
        $wallets = app(WalletService::class);
        $wallets->transfer($owner, $sa, '500.00', 'fund', $owner, null, null, Currency::Try);
        $wallets->transfer($sa, $member, '200.00', 'load', $sa);
        $wallet = $member->wallet()->first();

        $coupon = TipoCoupon::query()->create([
            'bet_id' => 9001, 'user_id' => $member->id, 'currency' => 'TRY', 'stake' => '17.35', 'placed_at' => now(),
        ]);
        $wallets->debit($wallet, '17.35', WalletTransactionType::Bet, WalletProduct::Sport, 'tipo:game-1', 'tipo:9001', null, null, $member);
        $wallets->credit($wallet, '6.50', WalletTransactionType::Win, WalletProduct::Sport, 'tipo:game-win', 'tipo:9001', null, null, $member);

        $roma = CasinoProvider::query()->create(['code' => 'romaspin', 'name' => 'RomaSpin', 'status' => 'active', 'is_live' => false]);
        $gold = CasinoProvider::query()->create(['code' => 'goldpalace', 'name' => 'GoldPalace', 'status' => 'active', 'is_live' => false]);
        $slot = CasinoGame::query()->create([
            'provider_id' => $gold->id, 'external_id' => 'sweet', 'name' => 'Sweet', 'vendor' => 'pp',
            'category' => 'slot', 'is_live' => false, 'is_active' => true,
        ]);
        $mini = CasinoGame::query()->create([
            'provider_id' => $roma->id, 'external_id' => 'aviator', 'name' => 'Aviator', 'vendor' => 'mini-spribe',
            'category' => 'mini', 'is_live' => false, 'is_active' => true,
        ]);
        $live = CasinoGame::query()->create([
            'provider_id' => $roma->id, 'external_id' => 'roulette', 'name' => 'Roulette', 'vendor' => 'casino-evolution',
            'category' => 'live', 'is_live' => true, 'is_active' => true,
        ]);
        $this->play($wallets, $member, WalletTransactionType::Bet, WalletProduct::Slot, '8.00', 'goldpalace', 'slot-1', 'round-slot', 'Sweet', $slot);
        $this->play($wallets, $member, WalletTransactionType::Bet, WalletProduct::Slot, '3.00', 'romaspin', 'mini-1', 'round-mini', 'Aviator', $mini);
        $this->play($wallets, $member, WalletTransactionType::Bet, WalletProduct::LiveCasino, '12.00', 'romaspin', 'live-1', 'round-live', 'Roulette', $live);

        $all = $this->actingAs($sa)->get('/panel/player-movements');
        $all->assertOk()
            ->assertSee(__('panel.player_movements'))
            ->assertSee('md:hidden', false)
            ->assertSee(Money::format('17.35', Currency::Try), false)
            ->assertSee('Sweet')->assertSee('Aviator')->assertSee('Roulette')
            ->assertSee('/panel/coupons/tipo/'.$coupon->id, false)
            ->assertSee('/panel/casino/rounds?username='.$member->username, false)
            ->assertDontSee(Money::format('200.00', Currency::Try), false);

        $this->actingAs($sa)->get('/panel/player-movements?product=sport')
            ->assertOk()->assertSee(Money::format('17.35', Currency::Try), false)->assertDontSee('Aviator')->assertDontSee('Roulette')->assertDontSee('Sweet');
        $this->actingAs($sa)->get('/panel/player-movements?product=mini')
            ->assertOk()->assertSee('Aviator')->assertDontSee('Sweet')->assertDontSee('Roulette');
        $this->actingAs($sa)->get('/panel/player-movements?product=slot')
            ->assertOk()->assertSee('Sweet')->assertDontSee('Aviator');
        $this->actingAs($sa)->get('/panel/player-movements?product=casino')
            ->assertOk()->assertSee('Roulette')->assertDontSee('Sweet');
        $this->actingAs($sa)->get('/panel/player-movements?type=win')
            ->assertOk()->assertSee(Money::format('6.50', Currency::Try), false)->assertDontSee(Money::format('17.35', Currency::Try), false);
        $this->actingAs($bayi)->get('/panel/player-movements')->assertOk()->assertSee($member->username);
        $this->actingAs($sa)->get('/panel/player-movements?member='.$member->id)->assertOk();
    }

    private function play(WalletService $wallets, User $member, WalletTransactionType $type, WalletProduct $product, string $amount, string $provider, string $txId, string $round, string $name, CasinoGame $game): void
    {
        $wallet = $member->wallet()->first();
        $tx = $type === WalletTransactionType::Bet
            ? $wallets->debit($wallet, $amount, $type, $product, $provider.':'.$txId, $round, null, $name, $member)
            : $wallets->credit($wallet, $amount, $type, $product, $provider.':'.$txId, $round, null, $name, $member);
        GameRound::query()->create([
            'provider' => $provider, 'provider_transaction_id' => $txId, 'round_id' => $round, 'user_id' => $member->id,
            'game_id' => $game->id, 'bet' => $type === WalletTransactionType::Bet ? $amount : '0.00', 'win' => $type === WalletTransactionType::Bet ? '0.00' : $amount,
            'balance_before' => $tx->balance_before, 'amount' => $tx->amount, 'balance_after' => $tx->balance_after,
            'status' => $type->value, 'payload' => [], 'created_at' => now(),
        ]);
    }

    /** @return array{0: User, 1: User, 2: User, 3: User, 4: User, 5: User, 6: User} */
    private function network(): array
    {
        $owner = $this->rawUser('owner', UserRole::Owner, null);
        $h = app(HierarchyService::class);
        $sa = $h->create($owner, $this->payload('sa_one'));
        $otherSa = $h->create($owner, $this->payload('sa_two'));
        $bayi = $h->create($sa, $this->payload('bayi_one'));
        $usdBayi = $h->create($sa, $this->payload('bayi_usd', 'USD'));
        $otherBayi = $h->create($otherSa, $this->payload('bayi_two'));
        $member = $h->create($bayi, $this->payload('uye_one'));
        $usdMember = $h->create($usdBayi, $this->payload('uye_usd', 'USD'));
        $otherMember = $h->create($otherBayi, $this->payload('uye_two'));

        return [$owner, $sa, $otherSa, $bayi, $member, $otherMember, $usdMember];
    }

    /** @return array<string, mixed> */
    private function payload(string $username, string $currency = 'TRY'): array
    {
        return [
            'username' => $username, 'password' => 'password', 'commission_rate' => 0, 'user_limit' => null,
            'note' => null, 'language' => 'tr', 'currency' => $currency,
        ];
    }

    private function rawUser(string $username, UserRole $role, ?User $parent): User
    {
        $user = User::query()->create([
            'username' => $username, 'password' => 'password', 'role' => $role,
            'parent_id' => $parent?->id, 'path' => '/', 'depth' => $parent ? $parent->depth + 1 : 0,
            'superadmin_id' => null, 'language' => Language::Tr, 'currency' => Currency::Try,
            'timezone' => 'Europe/Istanbul', 'commission_rate' => 0, 'status' => UserStatus::Active,
        ]);
        $user->path = ($parent?->path ?? '/').$user->id.'/';
        $user->save();

        return $user->refresh();
    }
}
