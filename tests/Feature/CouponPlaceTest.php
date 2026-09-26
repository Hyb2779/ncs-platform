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
use App\Services\Sport\CouponException;
use App\Services\Sport\CouponPlacer;
use App\Services\Sport\SportLimitCatalog;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
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
            ['max_stake_general' => '5.00', 'stake' => '10', 'mode' => 'single', 'prices' => ['1.50']],
            ['max_payout_general' => '20.00', 'stake' => '10', 'mode' => 'single', 'prices' => ['5.00']],
            ['stake' => '10', 'mode' => 'combo', 'prices' => ['1.50']],
            ['max_selections' => 2, 'stake' => '10', 'mode' => 'combo', 'prices' => ['1.50', '1.60', '1.70']],
            ['min_coupon_odds' => '3.00', 'stake' => '10', 'mode' => 'combo', 'prices' => ['1.20', '1.30']],
            ['min_odds_prematch' => '2.00', 'stake' => '10', 'mode' => 'single', 'prices' => ['1.40']],
            ['daily_max' => '5.00', 'stake' => '10', 'mode' => 'single', 'prices' => ['1.50']],
        ];

        foreach ($cases as $case) {
            SportLimit::query()->whereNull('user_id')->where('currency', 'TRY')->update([
                'min_stake' => '1.00', 'max_stake_general' => '10000.00', 'max_payout_general' => '100000.00',
                'max_selections' => 20, 'min_coupon_odds' => '1.01', 'min_odds_prematch' => '1.01', 'daily_max' => '50000.00',
                'max_odds_prematch' => '30.00', 'max_coupon_odds' => '500.00',
            ]);
            foreach ($case['prices'] as $price) {
                $this->post('/sport/odds/'.$this->odd($price)->id);
            }
            $overrides = collect($case)->except(['stake', 'mode', 'prices'])->all();
            if ($overrides !== []) {
                SportLimit::query()->whereNull('user_id')->where('currency', 'TRY')->update($overrides);
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

        SportLimit::query()->whereNull('user_id')->where('currency', 'TRY')->update(['cancel_minutes' => 10]);
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
        $this->assertLimitRejected(['max_stake_general' => '5.00'], '10', 'single', ['1.50'], 'sport.errors.max_stake_general', ['amount' => '5.00']);
    }

    public function test_max_win_is_rejected(): void
    {
        $this->assertLimitRejected(['max_payout_general' => '20.00'], '10', 'single', ['5.00'], 'sport.errors.max_payout_general', ['amount' => '20.00']);
    }

    public function test_combo_min_selections_is_rejected(): void
    {
        $this->assertLimitRejected([], '10', 'combo', ['1.50'], 'sport.errors.combo_min', ['count' => 2]);
    }

    public function test_combo_max_selections_is_rejected(): void
    {
        $this->assertLimitRejected(
            ['max_selections' => 2],
            '10',
            'combo',
            ['1.50', '1.60', '1.70'],
            'sport.errors.max_selections',
            ['count' => 2],
        );
    }

    public function test_min_total_odds_is_rejected(): void
    {
        $this->assertLimitRejected(['min_coupon_odds' => '3.00'], '10', 'combo', ['1.20', '1.30'], 'sport.errors.min_coupon_odds', ['odd' => '3.00']);
    }

    public function test_min_single_odd_is_rejected(): void
    {
        $this->assertLimitRejected(['min_odds_prematch' => '2.00'], '10', 'single', ['1.40'], 'sport.errors.min_odds_prematch', ['odd' => '2.00']);
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
                'idempotency_key' => (string) Str::uuid(),
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

    public function test_a_lower_level_cannot_exceed_the_parent_and_the_tightest_value_applies(): void
    {
        [$member, $bayi] = $this->player('100.00');
        $this->actingAs($bayi)->get('/panel/sport/limits')
            ->assertOk()
            ->assertSee('Bahis Limitleri', false)
            ->assertSee('Üst sınır: 10.000 ₺', false)
            ->assertDontSee('unlimited[', false);
        $payload = SportLimitCatalog::for('TRY');
        $payload['currency'] = 'TRY';
        $payload['max_stake_general'] = '50000';
        $payload['unlimited'] = ['max_coupon_odds' => '1'];
        $this->actingAs($bayi)->from('/panel/sport/limits')->put('/panel/sport/limits', $payload)
            ->assertSessionHasErrors(['max_stake_general', 'max_coupon_odds']);

        unset($payload['unlimited']);
        $payload['max_stake_general'] = '40.00';
        $this->actingAs($bayi)->put('/panel/sport/limits', $payload)->assertRedirect();
        $this->assertTrue(ActivityLog::query()->where('action', 'sport.limits.updated')->exists());

        $this->actingAs($member)->post('/sport/odds/'.$this->odd('1.50')->id);
        $this->place('50', 'single')->assertSessionHasErrors('coupon');
        $this->place('10', 'single')->assertRedirect();
        $this->assertSame(1, Coupon::query()->count());
    }

    public function test_a_floor_can_be_raised_but_not_lowered(): void
    {
        [, $bayi] = $this->player('0');
        $superadmin = $bayi->parent;
        $payload = SportLimitCatalog::for('TRY');
        $payload['currency'] = 'TRY';
        $payload['min_coupon_odds'] = '1.00';

        $this->actingAs($superadmin)->from('/panel/sport/limits')->put('/panel/sport/limits', $payload)
            ->assertSessionHasErrors(['min_coupon_odds' => 'En az 1.01 olabilir']);
        $page = $this->followingRedirects()->from('/panel/sport/limits')->put('/panel/sport/limits', $payload);
        $page->assertOk()
            ->assertSee('1 alanda hata var', false)
            ->assertSee('Alt sınır: 1.01', false)
            ->assertSee('Üst hesapta kapalı', false)
            ->assertSee('Üst sınır: 10.000 ₺', false)
            ->assertSee('grid-cols-1', false)
            ->assertSee('lg:grid-cols-2', false)
            ->assertSee('inputmode="decimal"', false)
            ->assertSee('w-[120px]', false)
            ->assertSee('lg:w-[180px]', false)
            ->assertDontSee('Üst hesap sınırlı', false);
        $html = $page->getContent();
        $this->assertMatchesRegularExpression('/<div class="mb-4 rounded-lg bg-red-50[^"]*">\s*<p>1 alanda hata var<\/p>\s*<\/div>/', $html);
        $this->assertMatchesRegularExpression('/name="min_coupon_odds" value="1.00"/', $html);
        $this->assertMatchesRegularExpression('/border-red-600/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="unlimited\[min_coupon_odds\]"/', $html);
        $this->assertMatchesRegularExpression('/name="cash_out_enabled"[^>]*disabled/', $html);
        $this->assertSame(1, preg_match('/data-limit-field="min_coupon_odds"([\s\S]*?)data-limit-field=/', $html, $row));
        $this->assertStringContainsString('En az 1.01 olabilir', $row[1]);
        $this->assertStringNotContainsString('Alt sınır:', $row[1]);

        $payload['min_coupon_odds'] = '1.20';
        $this->actingAs($superadmin)->put('/panel/sport/limits', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('1.20', SportLimit::query()->where('user_id', $superadmin->id)->value('min_coupon_odds'));
    }

    public function test_an_odds_ceiling_hides_the_price_and_rejects_the_slip(): void
    {
        [$user] = $this->player('50.00');
        $odd = $this->odd('9.50');
        SportLimit::query()->whereNull('user_id')->where('currency', 'TRY')->update(['max_odds_prematch' => '2.00']);

        $this->actingAs($user)->get('/sport?when=all')
            ->assertOk()
            ->assertDontSee('/sport/odds/'.$odd->id, false);
        $this->post('/sport/odds/'.$odd->id)->assertStatus(422);

        try {
            app(CouponPlacer::class)->place($user, [
                'selections' => [[
                    'odd_id' => $odd->id,
                    'fixture_id' => $odd->fixture_id,
                    'outcome' => 'home',
                    'shown' => '9.50',
                ]],
                'stake' => '10',
                'accept' => true,
                'mode' => 'single',
            ], (string) Str::uuid(), '127.0.0.1', 'test');
            $this->fail('high odds should be rejected');
        } catch (CouponException $exception) {
            $this->assertSame('sport.errors.max_odds_prematch', $exception->translationKey);
        }
    }

    public function test_coupon_lookup_stays_inside_the_tree(): void
    {
        [$left, $bayiLeft] = $this->player('40.00');
        [, $bayiRight] = $this->player('40.00');
        $this->actingAs($left)->post('/sport/odds/'.$this->odd('1.50')->id);
        $this->place('10', 'single')->assertRedirect();
        $coupon = Coupon::query()->first();

        $this->actingAs($bayiLeft)->get('/panel/coupons/lookup?id='.$coupon->id)
            ->assertRedirect(route('panel.coupons.show', $coupon));
        $this->actingAs($bayiRight)->get('/panel/coupons/lookup?id='.$coupon->id)->assertNotFound();
        $this->actingAs($bayiLeft)->get('/panel/coupons/lookup')->assertOk()->assertSee(__('sport.panel.lookup'), false);
    }

    /**
     * @param  array<string, mixed>  $limits
     * @param  list<string>  $prices
     * @param  array<string, mixed>  $replace
     */
    private function assertLimitRejected(array $limits, string $stake, string $mode, array $prices, string $key, array $replace): void
    {
        [$user] = $this->player('1000.00');
        $this->actingAs($user);
        foreach ($prices as $price) {
            $this->post('/sport/odds/'.$this->odd($price)->id);
        }
        if ($limits !== []) {
            SportLimit::query()->whereNull('user_id')->where('currency', 'TRY')->update($limits);
        }

        $stored = SportLimit::query()->whereNull('user_id')->where('currency', 'TRY')->first();
        $replace = match ($key) {
            'sport.errors.min_stake' => ['amount' => $stored->min_stake],
            'sport.errors.max_stake_general' => ['amount' => $stored->max_stake_general],
            'sport.errors.max_payout_general' => ['amount' => $stored->max_payout_general],
            'sport.errors.combo_min' => ['count' => 2],
            'sport.errors.max_selections' => ['count' => $stored->max_selections],
            'sport.errors.min_coupon_odds' => ['odd' => $stored->min_coupon_odds],
            'sport.errors.min_odds_prematch' => ['odd' => $stored->min_odds_prematch],
            'sport.errors.daily_max' => ['amount' => $stored->daily_max],
            default => $replace,
        };
        app()->setLocale('tr');
        $this->place($stake, $mode)->assertSessionHasErrors(['coupon' => __($key, $replace)]);
        $this->assertSame(0, Coupon::query()->count());
    }

    private function place(string $stake, string $mode, string $accept = '0'): TestResponse
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
