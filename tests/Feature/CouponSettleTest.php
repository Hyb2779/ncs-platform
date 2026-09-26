<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CasinoGame;
use App\Models\Coupon;
use App\Models\SportCountry;
use App\Models\SportFixture;
use App\Models\SportLeague;
use App\Models\SportMarket;
use App\Models\SportOdd;
use App\Models\SportTeam;
use App\Models\SportWarning;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Casino\DemoProvider;
use App\Services\HierarchyService;
use App\Services\Sport\CouponCanceller;
use App\Services\Sport\CouponPlacer;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CouponSettleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Carbon::setTestNow('2026-09-26 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_winning_combo_pays_once_and_verify_passes(): void
    {
        [$member] = $this->player('50.00');
        $coupon = $this->combo($member, [
            ['outcome' => 'home', 'odd' => '2.00', 'ft' => [2, 0]],
            ['outcome' => 'home', 'odd' => '1.50', 'ft' => [1, 0]],
        ]);

        $this->travelAndSettle();
        $coupon->refresh();

        $this->assertSame('won', $coupon->status);
        $this->assertSame('3.00', bcadd((string) $coupon->total_odds, '0', 2));
        $this->assertSame('30.00', bcadd((string) $coupon->potential_win, '0', 2));
        $this->assertSame('70.00', $member->wallet()->first()->fresh()->balance);
        $this->assertSame(1, WalletTransaction::query()->where('idempotency_key', 'coupon:'.$coupon->id.':settle:0')->count());

        $this->artisan('sport:settle-check');
        $this->assertSame(1, WalletTransaction::query()->where('type', 'win')->count());
        $this->assertSame('70.00', $member->wallet()->first()->fresh()->balance);
        $this->artisan('wallet:verify')->assertOk();
    }

    public function test_one_lost_leg_settles_the_combo_early(): void
    {
        [$member] = $this->player('50.00');
        $first = $this->pricedOdd('2.00', now()->addMinutes(5));
        $second = $this->pricedOdd('1.80', now()->addDay());
        $coupon = $this->placeCombo($member, [$first, $second]);

        $first->fixture->update(['status' => 'FT', 'ft_home' => 0, 'ft_away' => 1, 'ht_home' => 0, 'ht_away' => 0, 'settled_at' => now()]);
        Carbon::setTestNow(now()->addMinutes(120));
        $this->artisan('sport:settle-check');

        $coupon->refresh();
        $this->assertSame('lost', $coupon->status);
        $this->assertSame('pending', $coupon->selections()->where('fixture_id', $second->fixture_id)->value('status'));
        $this->assertSame(0, WalletTransaction::query()->where('type', 'win')->count());
        $this->assertSame('40.00', $member->wallet()->first()->fresh()->balance);
        $this->artisan('wallet:verify')->assertOk();
    }

    public function test_void_leg_recomputes_combo_odds(): void
    {
        [$member] = $this->player('50.00');
        $keep = $this->pricedOdd('2.00', now()->addMinutes(5));
        $voided = $this->pricedOdd('3.00', now()->addMinutes(5));
        $coupon = $this->placeCombo($member, [$keep, $voided]);

        $keep->fixture->update(['status' => 'FT', 'ft_home' => 2, 'ft_away' => 0, 'ht_home' => 1, 'ht_away' => 0, 'settled_at' => now()]);
        $voided->fixture->update(['status' => 'PST']);
        Carbon::setTestNow($coupon->selections()->first()->kickoff_at->copy()->addHours(48)->addMinute());
        $this->artisan('sport:settle-check');

        $coupon->refresh();
        $this->assertSame('void', $coupon->selections()->where('fixture_id', $voided->fixture_id)->value('status'));
        $this->assertSame('won', $coupon->status);
        $this->assertSame('2.00', bcadd((string) $coupon->total_odds, '0', 2));
        $this->assertSame('20.00', bcadd((string) $coupon->potential_win, '0', 2));
        $this->assertSame('60.00', $member->wallet()->first()->fresh()->balance);
        $this->artisan('wallet:verify')->assertOk();
    }

    public function test_all_void_refunds_the_stake(): void
    {
        [$member] = $this->player('50.00');
        $coupon = $this->combo($member, [
            ['outcome' => 'home', 'odd' => '2.00', 'status' => 'CANC'],
            ['outcome' => 'home', 'odd' => '1.80', 'status' => 'WO'],
        ]);

        Carbon::setTestNow($coupon->selections()->min('kickoff_at'));
        Carbon::setTestNow(now()->addHours(48)->addMinute());
        $this->artisan('sport:settle-check');

        $coupon->refresh();
        $this->assertSame('void', $coupon->status);
        $this->assertSame('50.00', $member->wallet()->first()->fresh()->balance);
        $this->assertSame('refund', WalletTransaction::query()->where('idempotency_key', 'coupon:'.$coupon->id.':settle:0')->value('type')?->value);
        $this->artisan('wallet:verify')->assertOk();
    }

    public function test_cancelled_coupon_is_left_untouched(): void
    {
        [$member, $bayi] = $this->player('50.00');
        $coupon = $this->combo($member, [
            ['outcome' => 'home', 'odd' => '2.00', 'ft' => [2, 0]],
            ['outcome' => 'home', 'odd' => '1.50', 'ft' => [1, 0]],
        ]);
        app(CouponCanceller::class)->cancel($bayi, $coupon, 'panel', '127.0.0.1');

        $this->travelAndSettle();
        $coupon->refresh();
        $this->assertSame('cancelled', $coupon->status);
        $this->assertSame(0, WalletTransaction::query()->where('idempotency_key', 'like', 'coupon:'.$coupon->id.':settle:%')->count());
        $this->artisan('wallet:verify')->assertOk();
    }

    public function test_manual_correction_reverses_and_repays(): void
    {
        [$member, , $owner] = $this->player('50.00');
        $coupon = $this->combo($member, [
            ['outcome' => 'home', 'odd' => '2.00', 'ft' => [2, 0]],
            ['outcome' => 'home', 'odd' => '1.50', 'ft' => [1, 0]],
        ]);
        $this->travelAndSettle();
        $this->assertSame('70.00', $member->wallet()->first()->fresh()->balance);

        $fixture = $coupon->selections()->first()->fixture;
        $this->actingAs($owner)->post(route('panel.sport.fixtures.score', $fixture), [
            'ht_home' => 1, 'ht_away' => 0, 'ft_home' => 3, 'ft_away' => 1,
        ])->assertRedirect();

        $fixture->refresh();
        $this->assertSame('manual', $fixture->score_source);
        $this->assertSame(3, $fixture->ft_home);
        $this->assertSame(1, $fixture->ft_away);
        $this->assertNull($fixture->score_home);

        $coupon->refresh();
        $this->assertSame(1, $coupon->settlement_revision);
        $this->assertSame('won', $coupon->status);
        $this->assertSame(1, WalletTransaction::query()->where('idempotency_key', 'coupon:'.$coupon->id.':reverse:0')->count());
        $this->assertSame(1, WalletTransaction::query()->where('idempotency_key', 'coupon:'.$coupon->id.':settle:1')->count());
        $this->assertSame('70.00', $member->wallet()->first()->fresh()->balance);
        $this->artisan('wallet:verify')->assertOk();
    }

    public function test_postponed_twenty_hours_then_finished_settles_from_kickoff_snapshot(): void
    {
        [$member] = $this->player('50.00');
        $first = $this->pricedOdd('2.00', now()->addMinutes(5));
        $second = $this->pricedOdd('1.50', now()->addMinutes(5));
        $coupon = $this->placeCombo($member, [$first, $second]);
        $kickoff = $coupon->selections()->first()->kickoff_at->copy();

        $first->fixture->update(['status' => 'PST', 'starts_at' => $kickoff->copy()->addHours(20)]);
        $second->fixture->update(['status' => 'FT', 'ft_home' => 1, 'ft_away' => 0, 'ht_home' => 0, 'ht_away' => 0]);

        Carbon::setTestNow($kickoff->copy()->addHours(20)->addMinutes(5));
        $first->fixture->update([
            'status' => 'FT',
            'ft_home' => 2,
            'ft_away' => 0,
            'ht_home' => 1,
            'ht_away' => 0,
            'settled_at' => now(),
        ]);
        $this->artisan('sport:settle-check');

        $coupon->refresh();
        $this->assertSame('won', $coupon->status);
        $this->assertSame('won', $coupon->selections()->where('fixture_id', $first->fixture_id)->value('status'));
        $this->artisan('wallet:verify')->assertOk();
    }

    public function test_postponed_sixty_hours_voids_at_forty_eight_from_kickoff(): void
    {
        [$member] = $this->player('50.00');
        $first = $this->pricedOdd('2.00', now()->addMinutes(5));
        $second = $this->pricedOdd('1.50', now()->addMinutes(5));
        $coupon = $this->placeCombo($member, [$first, $second]);
        $kickoff = $coupon->selections()->first()->kickoff_at->copy();

        $first->fixture->update(['status' => 'PST', 'starts_at' => $kickoff->copy()->addHours(60)]);
        $second->fixture->update(['status' => 'PST', 'starts_at' => $kickoff->copy()->addHours(60)]);

        Carbon::setTestNow($kickoff->copy()->addHours(48)->addMinute());
        $this->artisan('sport:settle-check');

        $coupon->refresh();
        $this->assertSame('void', $coupon->status);
        $this->assertTrue($coupon->selections->every(fn ($selection) => $selection->status === 'void'));
        $this->assertSame('50.00', $member->wallet()->first()->fresh()->balance);
        $this->artisan('wallet:verify')->assertOk();
    }

    public function test_stale_live_status_creates_manual_warning(): void
    {
        [$member, , $owner] = $this->player('50.00');
        $first = $this->pricedOdd('2.00', now()->addMinutes(5));
        $second = $this->pricedOdd('1.50', now()->addMinutes(5));
        $this->placeCombo($member, [$first, $second]);
        $kickoff = $first->fixture->starts_at->copy();
        $first->fixture->update(['status' => '1H']);

        Carbon::setTestNow($kickoff->copy()->addHours(6)->addMinute());
        $this->artisan('sport:settle-check');

        $this->assertSame('pending', Coupon::query()->first()->status);
        $this->assertTrue(SportWarning::query()->where('type', SportWarning::Stale)->where('fixture_id', $first->fixture_id)->whereNull('resolved_at')->exists());
        $this->actingAs($owner)->get(route('panel.sport.status'))
            ->assertOk()
            ->assertSee(__('sport.panel.manual_settle'), false);
    }

    public function test_correction_after_withdrawn_win_goes_negative_then_clears_on_load(): void
    {
        [$member, $bayi, $owner] = $this->player('20.00');
        $coupon = $this->combo($member, [
            ['outcome' => 'home', 'odd' => '2.00', 'ft' => [2, 0]],
            ['outcome' => 'home', 'odd' => '2.00', 'ft' => [1, 0]],
        ]);
        $this->travelAndSettle();
        $this->assertSame('50.00', $member->wallet()->first()->fresh()->balance);

        app(WalletService::class)->transfer($member, $bayi, '50.00', 'withdraw-win', $bayi);
        $this->assertSame('0.00', $member->wallet()->first()->fresh()->balance);

        $fixture = $coupon->selections()->first()->fixture;
        $this->actingAs($owner)->post(route('panel.sport.fixtures.score', $fixture), [
            'ht_home' => 0, 'ht_away' => 1, 'ft_home' => 0, 'ft_away' => 1,
        ])->assertRedirect();

        $wallet = $member->wallet()->first()->fresh();
        $this->assertSame('-40.00', $wallet->balance);
        $this->assertSame('40.00', $wallet->settlement_overdraft_amount);
        $this->assertTrue(SportWarning::query()->where('type', SportWarning::Overdraft)->where('user_id', $member->id)->whereNull('resolved_at')->exists());

        $open = $this->pricedOdd('1.50', now()->addDay());
        $this->actingAs($member)->post('/sport/odds/'.$open->id);
        $this->post('/sport/coupon/place', [
            'stake' => '1', 'mode' => 'single', 'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('coupon');

        app(DemoProvider::class)->syncGames();
        $game = CasinoGame::query()->first();
        $this->actingAs($member)->get('/play/'.$game->id)->assertSessionHasErrors('game');

        $this->actingAs($bayi)->post('/panel/users/'.$member->id.'/balance', [
            'direction' => 'remove',
            'amount' => '1.00',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('amount');
        $this->assertSame('-40.00', $member->wallet()->first()->fresh()->balance);

        $this->actingAs($owner)->get(route('panel.sport.overdrafts'))->assertOk()->assertSee(__('sport.panel.overdraft'), false)->assertSee($member->username, false);
        $this->actingAs($bayi)->get(route('panel.sport.overdrafts'))->assertOk()->assertSee($member->username, false);
        $outsider = $this->otherBayi($owner);
        $this->actingAs($outsider)->get(route('panel.sport.overdrafts'))->assertOk()->assertDontSee($member->username, false);

        Carbon::setTestNow(now()->addSeconds(2));
        app(WalletService::class)->transfer($bayi, $member, '50.00', 'cover-debt', $bayi);
        $wallet = $member->wallet()->first()->fresh();
        $this->assertSame('10.00', $wallet->balance);
        $this->assertSame('0.00', $wallet->settlement_overdraft_amount);
        $this->assertFalse(SportWarning::query()->where('type', SportWarning::Overdraft)->where('user_id', $member->id)->whereNull('resolved_at')->exists());
        $this->artisan('wallet:verify')->assertOk();
    }

    /**
     * @param  list<array{outcome: string, odd: string, ft?: array{0: int, 1: int}, status?: string}>  $legs
     */
    private function combo(User $member, array $legs): Coupon
    {
        $odds = [];
        foreach ($legs as $leg) {
            $odd = $this->pricedOdd($leg['odd'], now()->addMinutes(5), $leg['outcome']);
            $odds[] = [$odd, $leg];
        }

        $coupon = $this->placeCombo($member, array_column($odds, 0));
        foreach ($odds as [$odd, $leg]) {
            if (isset($leg['ft'])) {
                $odd->fixture->update([
                    'status' => $leg['status'] ?? 'FT',
                    'ft_home' => $leg['ft'][0],
                    'ft_away' => $leg['ft'][1],
                    'ht_home' => $leg['ft'][0] > 0 ? 1 : 0,
                    'ht_away' => $leg['ft'][1] > 0 ? 1 : 0,
                    'settled_at' => now(),
                ]);
            } elseif (isset($leg['status'])) {
                $odd->fixture->update(['status' => $leg['status']]);
            }
        }

        return $coupon;
    }

    /**
     * @param  list<SportOdd>  $odds
     */
    private function placeCombo(User $member, array $odds): Coupon
    {
        $selections = [];
        foreach ($odds as $odd) {
            $selections[] = [
                'odd_id' => $odd->id,
                'fixture_id' => $odd->fixture_id,
                'outcome' => $odd->outcome,
                'shown' => (string) $odd->shown_odd,
            ];
        }

        $coupons = app(CouponPlacer::class)->place($member, [
            'selections' => $selections,
            'stake' => '10.00',
            'accept' => true,
            'mode' => 'combo',
        ], (string) Str::uuid(), '127.0.0.1', 'test');

        return $coupons[0];
    }

    private function travelAndSettle(): void
    {
        Carbon::setTestNow(now()->addHours(3));
        $this->artisan('sport:settle-check');
    }

    private function pricedOdd(string $price, Carbon $kickoff, string $outcome = 'home'): SportOdd
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
            'starts_at' => $kickoff,
            'status' => 'NS',
            'bulletin_code' => random_int(100000, 999999),
        ]);
        $market = SportMarket::query()->where('code', '1X2')->first();

        return SportOdd::query()->create([
            'fixture_id' => $fixture->id, 'market_id' => $market->id, 'outcome' => $outcome, 'raw_odd' => $price, 'shown_odd' => $price,
        ]);
    }

    /**
     * @return array{0: User, 1: User, 2: User}
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

        return [$member->refresh(), $bayi->refresh(), $owner->refresh()];
    }

    private function otherBayi(User $owner): User
    {
        $fields = [
            'password' => 'password', 'commission_rate' => 0, 'user_limit' => null, 'note' => null,
            'language' => 'tr', 'currency' => 'TRY', 'timezone' => 'Europe/Istanbul',
        ];
        $superadmin = app(HierarchyService::class)->create($owner, ['username' => 'sa2-'.uniqid()] + $fields);

        return app(HierarchyService::class)->create($superadmin, ['username' => 'bayi2-'.uniqid()] + $fields);
    }
}
