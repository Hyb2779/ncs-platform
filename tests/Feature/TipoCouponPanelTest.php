<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\TipoCoupon;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\Sport\NcsBridge;
use App\Services\WalletProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Wegas Spor (Tipo) kuponlari panelde: agac kapsami, detay, sorgulama. */
class TipoCouponPanelTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'http://panel.test/panel/coupons';

    protected function setUp(): void
    {
        parent::setUp();
        config(['sport.own_book_enabled' => false]);
    }

    public function test_list_is_scoped_to_the_viewers_tree(): void
    {
        [$owner, $bayi, $uye, $otherUye] = $this->world();
        $this->coupon($uye, 900001, 'lost');
        $this->coupon($otherUye, 900002, 'won');

        $this->actingAs($bayi)->get(self::BASE)->assertOk()->assertSee('900001')->assertDontSee('900002');
        $this->actingAs($owner)->get(self::BASE)->assertOk()->assertSee('900001')->assertSee('900002');
    }

    public function test_detail_shows_selections_and_other_tree_is_404(): void
    {
        [$owner, $bayi, $uye, $otherUye] = $this->world();
        $mine = $this->coupon($uye, 900003, 'lost', [
            'selections' => [[
                'home_name' => 'Ev Takimi', 'away_name' => 'Deplasman', 'market_name' => 'Mac Sonucu',
                'selection_name' => 'X', 'handicap' => '0', 'odds' => 1.15, 'status_label' => 'won',
                'country_name' => 'Ulke', 'competition_name' => 'Lig',
                'live_snapshot' => ['minutes' => '67', 'home_score' => 1, 'away_score' => 1],
                'score' => ['home' => 2, 'away' => 1],
            ]],
        ]);
        $other = $this->coupon($otherUye, 900004, 'lost');

        $this->actingAs($bayi)->get(self::BASE.'/tipo/'.$mine->id)->assertOk()
            ->assertSee('Ev Takimi - Deplasman')->assertSee("67' 1 - 1")->assertSee('2 - 1');
        $this->actingAs($bayi)->get(self::BASE.'/tipo/'.$other->id)->assertNotFound();
    }

    public function test_pending_coupon_detail_is_fetched_through_the_bridge(): void
    {
        [$owner, $bayi, $uye] = $this->world();
        $open = $this->coupon($uye, 900005, 'open');
        $this->mock(NcsBridge::class, function ($mock) use ($uye) {
            $mock->shouldReceive('coupon')->once()->with($uye->id, 900005)->andReturn([
                'player_id' => 'wegas:'.$uye->id, 'selections' => [['home_name' => 'Canli A', 'away_name' => 'Canli B']],
            ]);
        });

        $this->actingAs($bayi)->get(self::BASE.'/tipo/'.$open->id)->assertOk()->assertSee('Canli A - Canli B');
        $this->assertNotNull($open->fresh()->detail_fetched_at);
    }

    public function test_lookup_by_id_or_member_name_without_leaking_other_trees(): void
    {
        [$owner, $bayi, $uye, $otherUye] = $this->world();
        $mine = $this->coupon($uye, 900006, 'lost');
        $this->coupon($otherUye, 900007, 'lost');

        $this->actingAs($bayi)->get(self::BASE.'/lookup?q=900006')->assertRedirect(route('panel.coupons.tipo', $mine));
        $this->actingAs($bayi)->get(self::BASE.'/lookup?q='.$uye->username)->assertRedirect(route('panel.coupons.index', ['user' => $uye->username]));
        $this->actingAs($bayi)->get(self::BASE.'/lookup?q=900007')->assertOk()->assertSee(__('panel.tipo_lookup_not_found'));
        $this->actingAs($bayi)->get(self::BASE.'/lookup?q='.$otherUye->username)->assertOk()->assertSee(__('panel.tipo_lookup_not_found'));
    }

    public function test_dashboard_counts_lost_today_and_open_coupons_in_tree(): void
    {
        [$owner, $bayi, $uye, $otherUye] = $this->world();
        $this->coupon($uye, 900010, 'lost');
        $this->coupon($uye, 900011, 'open');
        $this->coupon($uye, 900012, 'won');
        $this->coupon($otherUye, 900013, 'open');
        $old = $this->coupon($uye, 900014, 'lost');
        $old->forceFill(['placed_at' => now()->subDays(3)])->save();

        $sa = User::query()->where('username', 'tc_sa')->firstOrFail();
        $mine = app(\App\Services\Stats\TodaySummary::class)->for($sa);
        $this->assertSame(1, $mine['sport_lost']['count']);
        $this->assertSame('50.00', $mine['sport_lost']['amount']);
        $this->assertSame(1, $mine['sport_pending']['count']);

        $all = app(\App\Services\Stats\TodaySummary::class)->for($owner);
        $this->assertSame(2, $all['sport_pending']['count']);
        $this->assertSame('100.00', $all['sport_pending']['amount']);
    }

    /** @return array{0: User, 1: User, 2: User, 3: User} */
    private function world(): array
    {
        $owner = User::query()->create([
            'username' => 'tc_owner', 'password' => 'password', 'role' => UserRole::Owner,
            'parent_id' => null, 'path' => '/', 'depth' => 0, 'superadmin_id' => null,
            'language' => Language::Tr, 'currency' => Currency::Try, 'timezone' => 'Europe/Istanbul',
            'commission_rate' => 0, 'status' => UserStatus::Active,
        ]);
        $owner->path = '/'.$owner->id.'/';
        $owner->save();
        app(WalletProvisioner::class)->openFor($owner->refresh());

        $h = app(HierarchyService::class);
        $data = fn (string $name) => [
            'username' => $name, 'password' => 'password', 'commission_rate' => 0,
            'user_limit' => null, 'note' => null, 'language' => 'tr', 'currency' => 'TRY',
        ];
        $sa = $h->create($owner, $data('tc_sa'));
        $bayi = $h->create($sa, $data('tc_bayi'));
        $uye = $h->create($bayi, $data('tc_uye'));
        $sa2 = $h->create($owner, $data('tc_sa2'));
        $bayi2 = $h->create($sa2, $data('tc_bayi2'));
        $other = $h->create($bayi2, $data('tc_baska'));

        return [$owner->refresh(), $bayi, $uye, $other];
    }

    private function coupon(User $user, int $betId, string $label, ?array $detail = null): TipoCoupon
    {
        return TipoCoupon::query()->create([
            'bet_id' => $betId, 'user_id' => $user->id, 'currency' => 'TRY', 'type' => 'combo', 'live' => true,
            'status' => $label === 'lost' ? 2 : 0, 'status_label' => $label, 'stake' => 50, 'total_odds' => 2.5,
            'potential_win' => 125, 'payout' => $label === 'won' ? 125 : 0, 'selection_count' => 1, 'won_count' => 0,
            'placed_at' => now()->subHour(), 'detail' => $detail, 'detail_fetched_at' => $detail ? now() : null,
        ]);
    }
}
