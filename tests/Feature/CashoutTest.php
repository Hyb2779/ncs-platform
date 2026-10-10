<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Coupon;
use App\Models\SportCountry;
use App\Models\SportFixture;
use App\Models\SportLeague;
use App\Models\SportMarket;
use App\Models\SportOdd;
use App\Models\SportTeam;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\HierarchyService;
use App\Services\Sport\CashoutQuote;
use App\Services\Sport\CouponSettler;
use App\Services\Stats\PeriodReport;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashout_pays_the_fresh_price_and_blocks_a_second_settlement(): void
    {
        [$member] = $this->player('40.00');
        $odd = $this->odd('2.00');
        $odd->forceFill(['quoted_at' => now()])->save();
        $this->actingAs($member)->post('/sport/odds/'.$odd->id);
        $this->post('/sport/coupon/place', [
            'stake' => '10', 'mode' => 'single', 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();

        $odd->forceFill(['shown_odd' => '1.50', 'quoted_at' => now()])->save();
        $coupon = Coupon::query()->first();
        $this->assertSame('12.00', app(CashoutQuote::class)->quote($coupon));

        $this->actingAs($member)->get('/account/coupons/'.$coupon->id)
            ->assertOk()
            ->assertSee('coupon-cashout', false)
            ->assertSee('value="12.00"', false);

        $this->post('/account/coupons/'.$coupon->id.'/cashout', ['amount' => '12.00'])->assertRedirect();
        $this->assertSame('cashed_out', $coupon->fresh()->status);
        $this->assertSame('12.00', WalletTransaction::query()->where('type', 'cashout')->first()->amount);
        $this->assertSame('42.00', number_format((float) $member->wallet()->first()->balance, 2, '.', ''));

        $this->post('/account/coupons/'.$coupon->id.'/cashout', ['amount' => '12.00'])->assertSessionHasErrors('coupon');
        $this->assertSame(1, WalletTransaction::query()->where('type', 'cashout')->count());

        $odd->fixture->forceFill(['status' => 'FT', 'ft_home' => 2, 'ft_away' => 0, 'score_home' => 2, 'score_away' => 0])->save();
        app(CouponSettler::class)->settle($coupon->fresh());
        $this->assertSame('cashed_out', $coupon->fresh()->status);
        $this->assertSame(0, WalletTransaction::query()->where('type', 'win')->count());

        $report = app(PeriodReport::class)->build($member->parent->parent, now()->subDay(), now()->addDay());
        $this->assertSame('12.00', $report['totals']['TRY']['sport']['payout']);
        $this->assertSame(0, $report['totals']['TRY']['sport']['win_count']);
        $this->artisan('wallet:verify')->assertOk();
    }

    public function test_a_moved_price_is_refused_and_a_small_move_pays_the_new_amount(): void
    {
        [$member] = $this->player('40.00');
        $odd = $this->odd('2.00');
        $odd->forceFill(['quoted_at' => now()])->save();
        $this->actingAs($member)->post('/sport/odds/'.$odd->id);
        $this->post('/sport/coupon/place', [
            'stake' => '10', 'mode' => 'single', 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $coupon = Coupon::query()->first();

        $odd->forceFill(['shown_odd' => '3.00', 'quoted_at' => now()])->save();
        $this->actingAs($member)->post('/account/coupons/'.$coupon->id.'/cashout', ['amount' => '12.00'])
            ->assertSessionHasErrors('coupon');
        $this->assertSame('pending', $coupon->fresh()->status);
        $this->assertSame('6.00', app(CashoutQuote::class)->quote($coupon->fresh()));

        $odd->forceFill(['shown_odd' => '1.48', 'quoted_at' => now()])->save();
        $fresh = app(CashoutQuote::class)->quote($coupon->fresh());
        $this->assertSame('12.16', $fresh);
        $this->post('/account/coupons/'.$coupon->id.'/cashout', ['amount' => '12.00'])->assertRedirect();
        $this->assertSame('cashed_out', $coupon->fresh()->status);
        $this->assertSame('12.16', WalletTransaction::query()->where('type', 'cashout')->first()->amount);
    }

    public function test_stale_suspended_and_already_lost_legs_have_no_offer(): void
    {
        [$member, , $other] = $this->player('40.00');
        $odd = $this->odd('2.00');
        $odd->forceFill(['quoted_at' => now()->subMinutes(11)])->save();
        $this->actingAs($member)->post('/sport/odds/'.$odd->id);
        $this->post('/sport/coupon/place', [
            'stake' => '10', 'mode' => 'single', 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $coupon = Coupon::query()->first();
        $this->assertNull(app(CashoutQuote::class)->quote($coupon));

        $odd->forceFill(['quoted_at' => now(), 'suspended' => true])->save();
        $this->assertNull(app(CashoutQuote::class)->quote($coupon->fresh()));

        $odd->forceFill(['suspended' => false, 'quoted_at' => now()->subMinutes(2)])->save();
        $odd->fixture->forceFill(['status' => '1H', 'score_home' => 1, 'score_away' => 0])->save();
        $this->assertNull(app(CashoutQuote::class)->quote($coupon->fresh()));

        $odd->forceFill(['quoted_at' => now()])->save();
        $this->assertSame('9.00', app(CashoutQuote::class)->quote($coupon->fresh()));

        $this->actingAs($other)->post('/account/coupons/'.$coupon->id.'/cashout', ['amount' => '12.00'])->assertNotFound();
    }

    public function test_a_live_under_that_is_already_dead_is_not_offered_and_a_won_leg_drops_out(): void
    {
        [$member] = $this->player('40.00');
        $under = $this->odd('1.90', 'OU25', 'under');
        $under->fixture->forceFill(['status' => 'NS'])->save();
        $under->forceFill(['quoted_at' => now()])->save();
        $this->actingAs($member)->post('/sport/odds/'.$under->id);
        $this->post('/sport/coupon/place', [
            'stake' => '10', 'mode' => 'single', 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $under->fixture->forceFill(['status' => '1H', 'score_home' => 3, 'score_away' => 0])->save();
        $this->assertNull(app(CashoutQuote::class)->quote(Coupon::query()->first()));

        $over = $this->odd('1.80', 'OU15', 'over');
        $home = $this->odd('2.00');
        $over->forceFill(['quoted_at' => now()])->save();
        $home->forceFill(['quoted_at' => now()])->save();
        $this->post('/sport/coupon/clear');
        $this->post('/sport/odds/'.$over->id);
        $this->post('/sport/odds/'.$home->id);
        $this->post('/sport/coupon/place', [
            'stake' => '10', 'mode' => 'combo', 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $over->fixture->forceFill(['status' => '1H', 'score_home' => 2, 'score_away' => 0])->save();
        $home->forceFill(['shown_odd' => '1.50', 'quoted_at' => now()])->save();

        $combo = Coupon::query()->where('type', 'combo')->first();
        $this->assertSame('21.60', app(CashoutQuote::class)->quote($combo));
    }

    public function test_late_minutes_and_near_over_lines_close_the_offer_and_the_live_price(): void
    {
        [$member] = $this->player('40.00');
        $odd = $this->odd('2.00');
        $odd->forceFill(['quoted_at' => now()])->save();
        $this->actingAs($member)->post('/sport/odds/'.$odd->id);
        $this->post('/sport/coupon/place', [
            'stake' => '10', 'mode' => 'single', 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $coupon = Coupon::query()->first();

        $odd->fixture->forceFill(['status' => '1H', 'elapsed' => 40, 'score_home' => 0, 'score_away' => 0])->save();
        $this->assertSame('9.00', app(CashoutQuote::class)->quote($coupon->fresh()));
        $odd->fixture->forceFill(['elapsed' => 41])->save();
        $this->assertNull(app(CashoutQuote::class)->quote($coupon->fresh()));
        $this->actingAs($member)->post('/sport/odds/'.$odd->id)->assertStatus(422);
        $this->actingAs($member)->get('/sport/fixtures/'.$odd->fixture_id)->assertOk()->assertDontSee('data-odd="2.00"', false);

        $odd->fixture->forceFill(['status' => '2H', 'elapsed' => 85])->save();
        $this->assertSame('9.00', app(CashoutQuote::class)->quote($coupon->fresh()));
        $odd->fixture->forceFill(['elapsed' => 86])->save();
        $this->assertNull(app(CashoutQuote::class)->quote($coupon->fresh()));

        $over15 = $this->odd('1.70', 'OU15', 'over');
        $over15->forceFill(['quoted_at' => now()])->save();
        $over15->fixture->forceFill(['status' => '1H', 'elapsed' => 20, 'score_home' => 0, 'score_away' => 0])->save();
        $this->assertFalse(sport_offer_closed($over15->fixture, $over15));
        $over15->fixture->forceFill(['score_home' => 1])->save();
        $this->assertTrue(sport_offer_closed($over15->fixture->fresh(), $over15));

        $over25 = $this->odd('1.60', 'OU25', 'over');
        $over25->forceFill(['quoted_at' => now(), 'group_name' => 'Toplam Alt/Üst', 'selection_name' => 'Üst', 'handicap' => '2.5'])->save();
        $over25->fixture->forceFill(['status' => '1H', 'elapsed' => 20, 'score_home' => 1, 'score_away' => 0])->save();
        $this->assertFalse(sport_over_goal_closed($over25->fixture, $over25));
        $over25->fixture->forceFill(['score_home' => 2])->save();
        $this->assertTrue(sport_over_goal_closed($over25->fixture->fresh(), $over25));
    }

    /** @return array{0: User, 1: User, 2: User} */
    private function player(string $amount): array
    {
        $owner = User::query()->create([
            'username' => 'owner-'.uniqid(),
            'password' => 'password',
            'role' => UserRole::Owner,
            'parent_id' => null,
            'path' => '/',
            'depth' => 0,
            'language' => Language::Tr,
            'currency' => Currency::Try,
            'timezone' => 'Europe/Istanbul',
            'commission_rate' => 0,
            'status' => UserStatus::Active,
        ]);
        $owner->path = '/'.$owner->id.'/';
        $owner->save();
        $hierarchy = app(HierarchyService::class);
        $fields = [
            'password' => 'password', 'commission_rate' => 0, 'user_limit' => null, 'note' => null,
            'language' => 'tr', 'currency' => 'TRY', 'timezone' => 'Europe/Istanbul',
        ];
        $superadmin = $hierarchy->create($owner, ['username' => 'sa-'.uniqid()] + $fields);
        $bayi = $hierarchy->create($superadmin, ['username' => 'bayi-'.uniqid()] + $fields);
        $member = $hierarchy->create($bayi, ['username' => 'uye-'.uniqid()] + $fields);
        $other = $hierarchy->create($bayi, ['username' => 'diger-'.uniqid()] + $fields);
        if (bccomp($amount, '0', 2) === 1) {
            app(WalletService::class)->transfer($owner, $member, $amount, 'fund-'.uniqid(), $owner);
        }

        return [$member->refresh(), $bayi->refresh(), $other->refresh()];
    }

    private function odd(string $price, string $market = '1X2', string $outcome = 'home'): SportOdd
    {
        $country = SportCountry::query()->firstOrCreate(['name' => 'England'], ['code' => 'EN']);
        $league = SportLeague::query()->create([
            'api_id' => random_int(1000, 999999), 'country_id' => $country->id, 'name' => 'League '.uniqid(),
            'season' => 2026, 'is_active' => true,
        ]);
        $home = SportTeam::query()->create(['api_id' => random_int(1000, 999999), 'name' => 'Home '.uniqid()]);
        $away = SportTeam::query()->create(['api_id' => random_int(1000, 999999), 'name' => 'Away '.uniqid()]);
        $fixture = SportFixture::query()->create([
            'api_id' => random_int(1000, 999999),
            'league_id' => $league->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'starts_at' => now()->addDay(),
            'status' => 'NS',
            'bulletin_code' => random_int(1000, 99999),
        ]);

        return SportOdd::query()->create([
            'fixture_id' => $fixture->id,
            'market_id' => SportMarket::query()->where('code', $market)->first()->id,
            'outcome' => $outcome,
            'raw_odd' => $price,
            'shown_odd' => $price,
        ]);
    }
}
