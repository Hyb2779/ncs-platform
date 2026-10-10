<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\WalletProduct;
use App\Enums\WalletTransactionType;
use App\Models\ActivityLog;
use App\Models\CasinoGame;
use App\Models\CasinoProvider;
use App\Models\Coupon;
use App\Models\CouponSelection;
use App\Models\GameBlock;
use App\Models\GameRound;
use App\Models\GameSession;
use App\Models\SportCountry;
use App\Models\SportFixture;
use App\Models\SportLeague;
use App\Models\SportLimit;
use App\Models\SportTeam;
use App\Models\SportWarning;
use App\Models\TipoCoupon;
use App\Models\User;
use App\Services\Casino\GameAvailability;
use App\Services\HierarchyService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Alt owner (ör. Volkan): owner yetkileri var ama sadece kendi ağacını görür.
 * Kök owner (üstü yok) her şeyi görür.
 */
class SubOwnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_sub_owner_sees_only_own_tree_root_sees_all(): void
    {
        [$root, $sub, $rootSa, $subSa] = $this->world();

        $this->assertTrue($root->isRootOwner());
        $this->assertFalse($sub->isRootOwner());

        $subSees = User::query()->subtreeOf($sub)->pluck('username')->all();
        $this->assertContains('volkan_sa', $subSees);
        $this->assertNotContains('yusuf_sa', $subSees);

        $rootSees = User::query()->subtreeOf($root)->pluck('username')->all();
        $this->assertContains('volkan_sa', $rootSees);
        $this->assertContains('yusuf_sa', $rootSees);

        $this->assertFalse($rootSa->isInSubtreeOf($sub));
        $this->assertTrue($subSa->isInSubtreeOf($sub));
    }

    public function test_sub_owner_panel_pages_hide_root_tree(): void
    {
        [$root, $sub, $rootSa, $subSa] = $this->world();

        $this->actingAs($sub)->get('http://panel.test/panel')->assertOk()
            ->assertSee('volkan_sa')->assertDontSee('yusuf_sa');
        $this->actingAs($sub)->get('http://panel.test/panel/users?tab=dealers')->assertOk()
            ->assertSee('volkan_sa')->assertDontSee('yusuf_sa')->assertDontSee('yusuf_bayi');
        $this->actingAs($sub)->get('http://panel.test/panel/users')->assertOk()
            ->assertSee('volkan_uye')->assertDontSee('yusuf_uye');
        $this->actingAs($sub)->get('http://panel.test/panel/network/'.$rootSa->id)->assertNotFound();
        $this->actingAs($sub)->get('http://panel.test/panel/network/'.$subSa->id)->assertOk();

        $this->actingAs($root)->get('http://panel.test/panel')->assertOk()
            ->assertSee('volkan_sa')->assertSee('yusuf_sa');
        $this->actingAs($root)->get('http://panel.test/panel/network/'.$subSa->id)->assertOk();
    }

    public function test_sub_owner_game_block_applies_only_to_own_tree(): void
    {
        [$root, $sub, $rootSa, $subSa, $rootMember, $subMember] = $this->world();

        GameBlock::query()->create(['superadmin_id' => $sub->id, 'scope' => 'game', 'value' => '777', 'created_by' => $sub->id]);
        GameBlock::query()->create(['superadmin_id' => null, 'scope' => 'game', 'value' => '888', 'created_by' => $root->id]);
        app(GameAvailability::class)->flush();
        $ga = app(GameAvailability::class);

        $forSubMember = $ga->blocked(GameAvailability::scopeIdsFor($subMember))['game'];
        $this->assertContains('777', $forSubMember);
        $this->assertContains('888', $forSubMember); // kök owner engeli herkese

        $forRootMember = $ga->blocked(GameAvailability::scopeIdsFor($rootMember))['game'];
        $this->assertNotContains('777', $forRootMember); // Volkan'ın engeli Yusuf'un ağacına değmez
        $this->assertContains('888', $forRootMember);

        $this->assertNotContains('777', $ga->blocked(GameAvailability::scopeIdsFor(null))['game']); // ziyaretçi
    }

    public function test_sub_owner_toggle_writes_own_scope(): void
    {
        [$root, $sub, , , $rootMember, $subMember] = $this->world();

        $this->actingAs($sub)->post('http://panel.test/panel/games/block', [
            'scope' => 'category', 'value' => ['live'], 'blocked' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('game_blocks', ['superadmin_id' => $sub->id, 'scope' => 'category', 'value' => 'live']);
        $this->assertDatabaseMissing('game_blocks', ['superadmin_id' => null, 'scope' => 'category', 'value' => 'live']);
        $this->assertNotContains('live', app(GameAvailability::class)->blocked(GameAvailability::scopeIdsFor($rootMember))['category']);
        $this->assertContains('live', app(GameAvailability::class)->blocked(GameAvailability::scopeIdsFor($subMember))['category']);

        $this->actingAs($sub)->post('http://panel.test/panel/games/block', [
            'scope' => 'category', 'value' => ['live'], 'blocked' => 0,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('game_blocks', ['superadmin_id' => $sub->id, 'scope' => 'category', 'value' => 'live']);
    }

    public function test_lists_hide_the_root_tree_and_the_root_still_sees_both(): void
    {
        [$root, $sub, , , $rootMember, $subMember] = $this->world();
        $wallets = app(WalletService::class);
        $wallets->transfer($root, $rootMember, '77.41', 'root-load', $root);
        $wallets->transfer($root, $subMember, '66.41', 'sub-load', $root);
        $wallets->debit($rootMember->wallet()->first(), '19.19', WalletTransactionType::Bet, WalletProduct::Slot, 'root-round', 'root-ref', null, 'YusufSlot', $rootMember);
        $wallets->debit($subMember->wallet()->first(), '18.18', WalletTransactionType::Bet, WalletProduct::Slot, 'sub-round', 'sub-ref', null, 'VolkanSlot', $subMember);

        Coupon::query()->create([
            'coupon_no' => 'YUSUF-880', 'user_id' => $rootMember->id, 'client_key' => 'yusuf-880', 'type' => 'single',
            'stake' => 10, 'total_odds' => 1.5, 'potential_win' => 15, 'status' => 'pending', 'placed_at' => now(),
        ]);
        Coupon::query()->create([
            'coupon_no' => 'VOLKAN-880', 'user_id' => $subMember->id, 'client_key' => 'volkan-880', 'type' => 'single',
            'stake' => 10, 'total_odds' => 1.5, 'potential_win' => 15, 'status' => 'pending', 'placed_at' => now(),
        ]);

        $provider = CasinoProvider::query()->create(['code' => 'iso', 'name' => 'Iso', 'status' => 'active']);
        $game = CasinoGame::query()->create(['provider_id' => $provider->id, 'external_id' => '1', 'name' => 'Iso Game']);
        GameRound::query()->create([
            'provider' => 'iso', 'provider_transaction_id' => 'root-tx', 'user_id' => $rootMember->id, 'game_id' => $game->id,
            'bet' => '31.31', 'win' => '0', 'status' => 'settled', 'payload' => [], 'created_at' => now(),
        ]);
        GameRound::query()->create([
            'provider' => 'iso', 'provider_transaction_id' => 'sub-tx', 'user_id' => $subMember->id, 'game_id' => $game->id,
            'bet' => '32.32', 'win' => '0', 'status' => 'settled', 'payload' => [], 'created_at' => now(),
        ]);
        GameSession::query()->create([
            'user_id' => $rootMember->id, 'game_id' => $game->id, 'provider' => 'iso', 'token' => 'root-token',
            'opened_at' => now(), 'device' => 'desktop',
        ]);
        GameSession::query()->create([
            'user_id' => $subMember->id, 'game_id' => $game->id, 'provider' => 'iso', 'token' => 'sub-token',
            'opened_at' => now(), 'device' => 'desktop',
        ]);
        ActivityLog::query()->create([
            'actor_id' => $root->id, 'action' => 'user.updated', 'target_type' => $rootMember->getMorphClass(),
            'target_id' => $rootMember->id, 'payload' => ['username' => 'yusuf_uye'], 'created_at' => now(),
        ]);
        Cache::put('presence:'.$rootMember->id, ['at' => now()->getTimestamp(), 'area' => 'site', 'ip' => '10.1.1.1', 'ua' => 'Linux Chrome/1'], 300);
        Cache::put('presence:'.$subMember->id, ['at' => now()->getTimestamp(), 'area' => 'site', 'ip' => '10.2.2.2', 'ua' => 'Linux Chrome/1'], 300);
        SportWarning::query()->create(['type' => SportWarning::Overdraft, 'user_id' => $rootMember->id, 'amount' => '999.99']);

        $base = 'http://panel.test/panel';
        $hidden = ['yusuf_sa', 'yusuf_bayi', 'yusuf_uye', 'YUSUF-880', 'YusufSlot'];
        foreach ([
            '/users',
            '/coupons',
            '/casino/rounds',
            '/casino/sessions',
            '/transactions',
            '/reports',
            '/member-movements',
            '/player-movements',
            '/online',
            '/logs',
            '/sport/status',
            '/sport/overdrafts',
        ] as $path) {
            $page = $this->actingAs($sub)->get($base.$path)->assertOk();
            foreach ($hidden as $needle) {
                $page->assertDontSee($needle, false);
            }
        }

        $this->actingAs($sub)->get($base.'/users')->assertSee('volkan_uye', false);
        $this->actingAs($sub)->get($base.'/coupons')->assertSee('VOLKAN-880', false);
        $this->actingAs($sub)->get($base.'/player-movements')->assertSee('VolkanSlot', false);
        $this->actingAs($sub)->get($base.'/online')->assertSee('volkan_uye', false);
        $this->actingAs($root)->get($base.'/users')->assertOk()->assertSee('yusuf_uye', false)->assertSee('volkan_uye', false);
        $this->actingAs($root)->get($base.'/coupons')->assertOk()->assertSee('YUSUF-880', false)->assertSee('VOLKAN-880', false);
        $this->actingAs($root)->get($base.'/casino/rounds')->assertOk()->assertSee('yusuf_uye', false);
        $this->actingAs($root)->get($base.'/sport/status')->assertOk()->assertSee('yusuf_uye', false);
    }

    public function test_direct_ids_and_mutations_on_the_root_tree_are_forbidden(): void
    {
        [$root, $sub, $rootSa, , $rootMember] = $this->world();
        $base = 'http://panel.test/panel';
        $coupon = Coupon::query()->create([
            'coupon_no' => 'ISO-1', 'user_id' => $rootMember->id, 'superadmin_id' => $rootSa->id,
            'client_key' => 'iso-1', 'type' => 'single', 'stake' => 10, 'total_odds' => 1.5,
            'potential_win' => 15, 'status' => 'pending', 'placed_at' => now(),
        ]);
        $tipo = TipoCoupon::query()->create([
            'bet_id' => 880010, 'user_id' => $rootMember->id, 'currency' => 'TRY', 'type' => 'single', 'live' => false,
            'status' => 0, 'status_label' => 'open', 'stake' => 10, 'total_odds' => 1.5, 'potential_win' => 15,
            'payout' => 0, 'selection_count' => 1, 'won_count' => 0, 'placed_at' => now(),
        ]);
        $before = $rootMember->wallet()->first()->balance;
        $password = $rootMember->password;

        $this->actingAs($sub)->get($base.'/users/'.$rootMember->id)->assertNotFound();
        $this->actingAs($sub)->get($base.'/users/'.$rootMember->id.'/edit')->assertNotFound();
        $this->actingAs($sub)->get($base.'/users/99999999')->assertNotFound();
        $this->actingAs($sub)->get($base.'/network/'.$rootSa->id)->assertNotFound();
        $this->actingAs($sub)->get($base.'/coupons/'.$coupon->id)->assertNotFound();
        $this->actingAs($sub)->post($base.'/coupons/'.$coupon->id.'/cancel', ['reason' => 'no'])->assertNotFound();
        $this->actingAs($sub)->get($base.'/coupons/lookup?id='.$coupon->id)->assertNotFound();
        $this->actingAs($sub)->get($base.'/coupons/tipo/'.$tipo->id)->assertNotFound();
        config(['sport.own_book_enabled' => false]);
        $this->actingAs($sub)->get($base.'/coupons/lookup?q=880010')->assertNotFound();
        $this->actingAs($sub)->get($base.'/coupons/lookup?q=yusuf_uye')->assertNotFound();
        $this->actingAs($sub)->get($base.'/reports?user='.$rootSa->id)->assertNotFound();
        $this->actingAs($sub)->get($base.'/member-movements?member='.$rootMember->id)->assertNotFound();
        $this->actingAs($sub)->get($base.'/transactions?user='.$rootSa->id)->assertNotFound();
        $this->actingAs($sub)->post($base.'/games/block', [
            'scope' => 'category', 'value' => ['slot'], 'blocked' => 1, 'target' => $rootSa->id,
        ])->assertNotFound();

        $this->actingAs($sub)->post($base.'/users/'.$rootMember->id.'/balance', [
            'direction' => 'add', 'amount' => '5.00', 'note' => 'no', 'idempotency_key' => (string) Str::uuid(),
        ])->assertNotFound();
        $this->actingAs($sub)->post($base.'/users/'.$rootMember->id.'/password', ['password' => 'YeniSifre1'])->assertNotFound();
        $this->actingAs($sub)->post($base.'/users/'.$rootMember->id.'/status')->assertNotFound();

        $rootMember->refresh();
        $this->assertSame((string) $before, (string) $rootMember->wallet()->first()->balance);
        $this->assertTrue(Hash::check('password', $password) || $rootMember->password === $password);
        $this->assertSame(UserStatus::Active, $rootMember->status);
        $this->assertSame('pending', $coupon->fresh()->status);

        $this->actingAs($sub)->get($base.'/sport/limits')->assertOk();
        $this->actingAs($sub)->get($base.'/sport/leagues')->assertOk();
        $this->actingAs($sub)->get($base.'/sport/margins')->assertOk();
        $this->actingAs($sub)->get($base.'/sport/translations')->assertOk();
        $this->actingAs($sub)->get($base.'/casino/providers')->assertOk();
        $this->actingAs($sub)->get($base.'/home-slides')->assertOk();
        $this->actingAs($root)->get($base.'/users/'.$rootMember->id)->assertOk()->assertSee('yusuf_uye', false);
        $this->actingAs($root)->post($base.'/users/'.$rootMember->id.'/status')->assertRedirect();
        $this->assertSame(UserStatus::Passive, $rootMember->fresh()->status);
    }

    public function test_score_settlement_does_not_touch_the_other_trees_coupon(): void
    {
        [$root, $sub, $rootSa, , $rootMember] = $this->world();
        $country = SportCountry::query()->create(['name' => 'Iso']);
        $league = SportLeague::query()->create(['api_id' => 1, 'country_id' => $country->id, 'name' => 'Iso Lig']);
        $home = SportTeam::query()->create(['api_id' => 1, 'name' => 'Ev']);
        $away = SportTeam::query()->create(['api_id' => 2, 'name' => 'Dep']);
        $fixture = SportFixture::query()->create([
            'api_id' => 1, 'league_id' => $league->id, 'home_team_id' => $home->id, 'away_team_id' => $away->id,
            'starts_at' => now()->subHours(3), 'status' => 'NS', 'bulletin_code' => 1001,
        ]);
        $coupon = Coupon::query()->create([
            'coupon_no' => 'ISO-SCORE', 'user_id' => $rootMember->id, 'superadmin_id' => $rootSa->id,
            'client_key' => 'iso-score', 'type' => 'single', 'stake' => 10, 'total_odds' => 1.5,
            'potential_win' => 15, 'status' => 'pending', 'placed_at' => now()->subHours(3),
        ]);
        CouponSelection::query()->create([
            'coupon_id' => $coupon->id, 'fixture_id' => $fixture->id, 'market_code' => '1x2', 'outcome' => 'home',
            'odds' => 1.5, 'raw_odds' => 1.5, 'kickoff' => now()->subHours(3), 'kickoff_at' => now()->subHours(3), 'status' => 'pending',
        ]);
        CouponSelection::query()->where('coupon_id', $coupon->id)->update(['market_code' => '1X2']);

        $url = 'http://panel.test/panel/sport/fixtures/'.$fixture->id;
        $this->actingAs($sub)->get($url)->assertOk()->assertDontSee('ISO-SCORE', false)->assertDontSee('yusuf_uye', false);
        $this->actingAs($sub)->post($url.'/score', [
            'ht_home' => 0, 'ht_away' => 0, 'ft_home' => 1, 'ft_away' => 0, 'played_at' => now()->toDateString(),
        ])->assertRedirect();
        $this->assertSame('won', $coupon->fresh()->status);
        $this->actingAs($root)->get($url)->assertOk()->assertSee('ISO-SCORE', false)->assertSee('yusuf_uye', false);
    }

    public function test_second_owner_cannot_open_the_root_account_or_change_a_credit_issue(): void
    {
        [$root, $sub, , , $rootMember, $subMember] = $this->world();
        $base = 'http://panel.test/panel';
        \Illuminate\Support\Facades\DB::table('credit_issues')->insert([
            'user_id' => $sub->id, 'currency' => 'TRY', 'amount' => '25.00', 'created_at' => now(),
        ]);

        $this->actingAs($sub)->get($base.'/users/'.$root->id)->assertNotFound();
        $this->actingAs($sub)->get($base.'/users/'.$root->id.'/edit')->assertNotFound();
        $this->actingAs($sub)->post($base.'/users/'.$root->id.'/password')->assertNotFound();
        $this->actingAs($sub)->post($base.'/users/'.$root->id.'/status')->assertNotFound();
        $this->actingAs($sub)->post($base.'/users/'.$root->id.'/balance', [
            'direction' => 'add', 'amount' => '1.00', 'idempotency_key' => (string) Str::uuid(),
        ])->assertNotFound();
        $this->actingAs($sub)->post($base.'/users', [
            'role' => 'owner', 'username' => 'third_owner', 'password' => 'password', 'parent' => $sub->id,
        ])->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('users', ['username' => 'third_owner']);

        $issue = \Illuminate\Support\Facades\DB::table('credit_issues')->first();
        $this->assertNotNull($issue);
        $this->assertSame('25.00', number_format((float) $issue->amount, 2, '.', ''));
        try {
            \Illuminate\Support\Facades\DB::table('credit_issues')->where('id', $issue->id)->delete();
            $this->fail('credit issue delete should be rejected');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        $log = ActivityLog::query()->create([
            'actor_id' => $root->id, 'action' => 'user.updated', 'target_type' => $rootMember->getMorphClass(),
            'target_id' => $rootMember->id, 'payload' => ['username' => 'yusuf_uye'], 'created_at' => now(),
        ]);
        try {
            $log->delete();
            $this->fail('activity log delete should be rejected');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        $sa = \App\Models\User::query()->where('username', 'yusuf_sa')->firstOrFail();
        $bayi = \App\Models\User::query()->where('username', 'yusuf_bayi')->firstOrFail();
        $this->actingAs($sa)->get($base)->assertOk()->assertDontSee(__('panel.period_ggr'), false)->assertDontSee(__('panel.today_sport_ggr'), false);
        $this->actingAs($bayi)->get($base)->assertOk()->assertDontSee(__('panel.period_ggr'), false);
        $this->actingAs($sa)->get($base.'/reports')->assertOk()->assertDontSee(__('panel.rep_hint'), false);
        $this->actingAs($bayi)->get($base.'/reports')->assertOk()->assertDontSee(__('panel.rep_hint'), false);
        $this->actingAs($sub)->get($base)->assertOk()->assertSee(__('panel.period_ggr'), false)->assertDontSee('yusuf_uye', false);
    }

    /** @return array{0: User, 1: User, 2: User, 3: User, 4: User, 5: User} */
    private function world(): array
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

        return [$root->refresh(), $sub->refresh(), $rootSa, $subSa, $rootMember, $subMember];
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
