<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ActivityLog;
use App\Models\Coupon;
use App\Models\SportCountry;
use App\Models\SportFixture;
use App\Models\SportLeague;
use App\Models\SportLimit;
use App\Models\SportMarket;
use App\Models\SportOdd;
use App\Models\SportTeam;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\HierarchyService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CouponPlaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_price_is_used_and_closed_matches_are_rejected(): void
    {
        [$user] = $this->player('20.00');
        $odd = $this->odd('1.50');
        $this->actingAs($user)->post('/sport/odds/'.$odd->id);
        $key = (string) Str::uuid();

        $this->post('/sport/coupon/place', [
            'stake' => '10', 'mode' => 'single', 'accept' => '0', 'idempotency_key' => $key, 'shown' => '9.99',
        ])->assertRedirect();

        $coupon = Coupon::query()->first();
        $this->assertSame('1.50', $coupon->selections()->first()->odds);
        $this->assertSame(1, Coupon::query()->count());

        $started = $this->odd('1.80');
        $started->fixture->update(['status' => '1H', 'starts_at' => now()->subHour()]);
        $this->post('/sport/odds/'.$started->id);
        $this->post('/sport/coupon/place', [
            'stake' => '10', 'mode' => 'single', 'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('coupon');
        $this->assertSame(1, Coupon::query()->count());

        $suspended = $this->odd('1.70');
        $suspended->update(['suspended' => true]);
        $this->post('/sport/odds/'.$suspended->id);
        $this->post('/sport/coupon/place', [
            'stake' => '10', 'mode' => 'single', 'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('coupon');
    }

    public function test_odds_change_depends_on_the_accept_flag(): void
    {
        [$user] = $this->player('50.00');
        $odd = $this->odd('1.50');
        $this->actingAs($user)->post('/sport/odds/'.$odd->id);
        $odd->update(['raw_odd' => '2.00', 'shown_odd' => '2.00']);

        $this->post('/sport/coupon/place', [
            'stake' => '10', 'mode' => 'single', 'accept' => '0', 'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('coupon');
        $this->assertSame(0, Coupon::query()->count());

        $this->post('/sport/coupon/place', [
            'stake' => '10', 'mode' => 'single', 'accept' => '1', 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $this->assertSame('2.00', Coupon::query()->first()->selections()->first()->odds);
    }

    public function test_each_limit_rejects_the_slip(): void
    {
        [$user] = $this->player('1000.00');
        $this->actingAs($user);
        $cases = [
            ['min_stake' => '50.00', 'stake' => '10', 'mode' => 'single', 'prices' => ['1.50']],
            ['max_stake' => '5.00', 'stake' => '10', 'mode' => 'single', 'prices' => ['1.50']],
            ['max_win' => '20.00', 'stake' => '10', 'mode' => 'single', 'prices' => ['5.00']],
            ['combo_min' => 2, 'stake' => '10', 'mode' => 'combo', 'prices' => ['1.50']],
            ['combo_max' => 2, 'combo_min' => 2, 'stake' => '10', 'mode' => 'combo', 'prices' => ['1.50', '1.60', '1.70']],
            ['min_total_odds' => '3.00', 'stake' => '10', 'mode' => 'combo', 'prices' => ['1.20', '1.30']],
            ['min_odd' => '2.00', 'stake' => '10', 'mode' => 'single', 'prices' => ['1.40']],
            ['daily_max' => '5.00', 'stake' => '10', 'mode' => 'single', 'prices' => ['1.50']],
        ];

        foreach ($cases as $case) {
            SportLimit::query()->whereNull('superadmin_id')->update([
                'min_stake' => '1.00', 'max_stake' => '10000.00', 'max_win' => '100000.00',
                'combo_min' => 2, 'combo_max' => 20, 'min_total_odds' => '1.01', 'min_odd' => '1.01', 'daily_max' => '50000.00',
            ]);
            SportLimit::query()->whereNull('superadmin_id')->update(collect($case)->except(['stake', 'mode', 'prices'])->all());
            foreach ($case['prices'] as $price) {
                $this->post('/sport/odds/'.$this->odd($price)->id);
            }
            $this->post('/sport/coupon/place', [
                'stake' => $case['stake'], 'mode' => $case['mode'], 'idempotency_key' => (string) Str::uuid(),
            ])->assertSessionHasErrors('coupon');
            $this->post('/sport/coupon/clear');
        }

        $this->assertSame(0, Coupon::query()->count());
    }

    public function test_insufficient_balance_writes_nothing_and_repeat_key_is_one_coupon(): void
    {
        [$user] = $this->player('10.00');
        $odd = $this->odd('1.50');
        $this->actingAs($user)->post('/sport/odds/'.$odd->id);
        $this->post('/sport/coupon/place', [
            'stake' => '50', 'mode' => 'single', 'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('coupon');
        $this->assertSame(0, Coupon::query()->count());
        $this->assertSame(0, WalletTransaction::query()->where('type', 'bet')->count());

        $key = (string) Str::uuid();
        $this->post('/sport/coupon/place', [
            'stake' => '10', 'mode' => 'single', 'idempotency_key' => $key,
        ])->assertRedirect();
        $this->post('/sport/odds/'.$odd->id);
        $this->post('/sport/coupon/place', [
            'stake' => '10', 'mode' => 'single', 'idempotency_key' => $key,
        ])->assertRedirect();
        $this->assertSame(1, Coupon::query()->count());
        $this->assertSame(1, WalletTransaction::query()->where('type', 'bet')->count());
    }

    public function test_single_mode_creates_one_coupon_per_selection(): void
    {
        [$user] = $this->player('100.00');
        $this->actingAs($user);
        $this->post('/sport/odds/'.$this->odd('1.50')->id);
        $this->post('/sport/odds/'.$this->odd('2.00')->id);
        $this->post('/sport/coupon/place', [
            'stake' => '10', 'mode' => 'single', 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();

        $this->assertSame(2, Coupon::query()->count());
        $this->assertSame('-20.00', number_format((float) WalletTransaction::query()->where('type', 'bet')->sum('amount'), 2, '.', ''));
        $this->artisan('wallet:verify')->assertOk();
    }

    public function test_cancel_refunds_only_inside_the_subtree(): void
    {
        [$left, $bayiLeft] = $this->player('40.00');
        [$right] = $this->player('40.00');
        $odd = $this->odd('1.50');
        $this->actingAs($left)->post('/sport/odds/'.$odd->id);
        $this->post('/sport/coupon/place', [
            'stake' => '10', 'mode' => 'single', 'idempotency_key' => (string) Str::uuid(),
        ]);
        $coupon = Coupon::query()->first();

        $this->actingAs($right)->post('/account/coupons/'.$coupon->id.'/cancel', ['reason' => 'no'])->assertNotFound();
        $this->actingAs($bayiLeft)->get('/panel/coupons')->assertOk()->assertSee($coupon->coupon_no, false);
        $this->actingAs($right->parent->parent)->get('/panel/coupons')->assertOk()->assertDontSee($coupon->coupon_no, false);

        $this->actingAs($left)->post('/account/coupons/'.$coupon->id.'/cancel', ['reason' => 'too late'])->assertSessionHasErrors('coupon');
        $this->assertSame('pending', $coupon->fresh()->status);

        SportLimit::query()->whereNull('superadmin_id')->update(['cancel_minutes' => 10]);
        $this->actingAs($bayiLeft)->post('/panel/coupons/'.$coupon->id.'/cancel', ['reason' => 'customer request'])->assertRedirect();
        $this->assertSame('cancelled', $coupon->fresh()->status);
        $this->assertSame('10.00', WalletTransaction::query()->where('type', 'refund')->first()->amount);
        $this->assertTrue(ActivityLog::query()->where('action', 'coupon.cancelled')->exists());
        $this->artisan('wallet:verify')->assertOk();
    }

    public function test_started_match_is_rejected(): void
    {
        [$user] = $this->player('40.00');
        $odd = $this->odd('1.80');
        $odd->fixture->update(['status' => '1H', 'starts_at' => now()->subHour()]);
        $this->actingAs($user)->post('/sport/odds/'.$odd->id);

        app()->setLocale('tr');
        $this->place('10', 'single')->assertSessionHasErrors(['coupon' => __('sport.errors.started')]);
        $this->assertSame(0, Coupon::query()->count());
    }

    public function test_suspended_odd_is_rejected(): void
    {
        [$user] = $this->player('40.00');
        $odd = $this->odd('1.70');
        $this->actingAs($user)->post('/sport/odds/'.$odd->id);
        $odd->update(['suspended' => true]);

        app()->setLocale('tr');
        $this->place('10', 'single')->assertSessionHasErrors(['coupon' => __('sport.errors.suspended')]);
        $this->assertSame(0, Coupon::query()->count());
    }

    public function test_changed_odds_are_rejected_when_accept_is_off(): void
    {
        [$user] = $this->player('40.00');
        $odd = $this->odd('1.50');
        $this->actingAs($user)->post('/sport/odds/'.$odd->id);
        $odd->update(['raw_odd' => '2.00', 'shown_odd' => '2.00']);

        app()->setLocale('tr');
        $this->place('10', 'single', '0')->assertSessionHasErrors(['coupon' => __('sport.errors.odds_changed')]);
        $this->assertSame(0, Coupon::query()->count());
    }

    public function test_changed_odds_are_kept_when_accept_is_on(): void
    {
        [$user] = $this->player('40.00');
        $odd = $this->odd('1.50');
        $this->actingAs($user)->post('/sport/odds/'.$odd->id);
        $odd->update(['raw_odd' => '2.00', 'shown_odd' => '2.00']);

        $this->place('10', 'single', '1')->assertRedirect();

        $this->assertSame('2.00', Coupon::query()->first()->selections()->first()->odds);
    }

    public function test_min_stake_is_rejected(): void
    {
        $this->assertLimitRejected(['min_stake' => '50.00'], '10', 'single', ['1.50'], 'sport.errors.min_stake', ['amount' => '50.00']);
    }

    public function test_max_stake_is_rejected(): void
    {
        $this->assertLimitRejected(['max_stake' => '5.00'], '10', 'single', ['1.50'], 'sport.errors.max_stake', ['amount' => '5.00']);
    }

    public function test_max_win_is_rejected(): void
    {
        $this->assertLimitRejected(['max_win' => '20.00'], '10', 'single', ['5.00'], 'sport.errors.max_win', ['amount' => '20.00']);
    }

    public function test_combo_min_selections_is_rejected(): void
    {
        $this->assertLimitRejected(['combo_min' => 2], '10', 'combo', ['1.50'], 'sport.errors.combo_min', ['count' => 2]);
    }

    public function test_combo_max_selections_is_rejected(): void
    {
        $this->assertLimitRejected(
            ['combo_min' => 2, 'combo_max' => 2],
            '10',
            'combo',
            ['1.50', '1.60', '1.70'],
            'sport.errors.combo_max',
            ['count' => 2],
        );
    }

    public function test_min_total_odds_is_rejected(): void
    {
        $this->assertLimitRejected(['min_total_odds' => '3.00'], '10', 'combo', ['1.20', '1.30'], 'sport.errors.min_total', ['odd' => '3.00']);
    }

    public function test_min_single_odd_is_rejected(): void
    {
        $this->assertLimitRejected(['min_odd' => '2.00'], '10', 'single', ['1.40'], 'sport.errors.min_odd', ['odd' => '2.00']);
    }

    public function test_rejected_place_shows_the_error_on_the_slip(): void
    {
        [$user] = $this->player('100.00');
        $this->actingAs($user)->post('/sport/odds/'.$this->odd('1.80')->id);
        app()->setLocale('tr');

        $this->actingAs($user)
            ->followingRedirects()
            ->from('/sport')
            ->post('/sport/coupon/place', [
                'stake' => '',
                'mode' => 'combo',
                'accept' => '0',
                'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            ])
            ->assertOk()
            ->assertSee(__('sport.errors.stake'), false)
            ->assertSee(__('sport.coupon.confirm'), false)
            ->assertSee('name="idempotency_key"', false);
    }

    public function test_daily_stake_cap_is_rejected(): void
    {
        $this->assertLimitRejected(['daily_max' => '5.00'], '10', 'single', ['1.50'], 'sport.errors.daily_max', ['amount' => '5.00']);
    }

    /**
     * @param  array<string, mixed>  $limits
     * @param  list<string>  $prices
     * @param  array<string, mixed>  $replace
     */
    private function assertLimitRejected(array $limits, string $stake, string $mode, array $prices, string $key, array $replace): void
    {
        [$user] = $this->player('1000.00');
        SportLimit::query()->whereNull('superadmin_id')->update($limits);
        $this->actingAs($user);
        foreach ($prices as $price) {
            $this->post('/sport/odds/'.$this->odd($price)->id);
        }

        $stored = SportLimit::query()->whereNull('superadmin_id')->first();
        $replace = match ($key) {
            'sport.errors.min_stake' => ['amount' => $stored->min_stake],
            'sport.errors.max_stake' => ['amount' => $stored->max_stake],
            'sport.errors.max_win' => ['amount' => $stored->max_win],
            'sport.errors.combo_min' => ['count' => $stored->combo_min],
            'sport.errors.combo_max' => ['count' => $stored->combo_max],
            'sport.errors.min_total' => ['odd' => $stored->min_total_odds],
            'sport.errors.min_odd' => ['odd' => $stored->min_odd],
            'sport.errors.daily_max' => ['amount' => $stored->daily_max],
            default => $replace,
        };
        app()->setLocale('tr');
        $this->place($stake, $mode)->assertSessionHasErrors(['coupon' => __($key, $replace)]);
        $this->assertSame(0, Coupon::query()->count());
    }

    private function place(string $stake, string $mode, string $accept = '0'): \Illuminate\Testing\TestResponse
    {
        return $this->post('/sport/coupon/place', [
            'stake' => $stake,
            'mode' => $mode,
            'accept' => $accept,
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    /**
     * @return array{0: User, 1: User}
     */
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
        if (bccomp($amount, '0', 2) === 1) {
            app(WalletService::class)->transfer($owner, $member, $amount, 'fund-'.uniqid(), $owner);
        }

        return [$member->refresh(), $bayi->refresh()];
    }

    private function odd(string $price): SportOdd
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
        $market = SportMarket::query()->where('code', '1X2')->first();

        return SportOdd::query()->create([
            'fixture_id' => $fixture->id, 'market_id' => $market->id, 'outcome' => 'home', 'raw_odd' => $price, 'shown_odd' => $price,
        ]);
    }
}
