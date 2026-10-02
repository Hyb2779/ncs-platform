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
use App\Services\Sport\SportLimitFields;
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
        $this->actingAs($right->parent)->post('/panel/coupons/'.$coupon->id.'/cancel', ['reason' => 'other branch'])->assertNotFound();
        $this->actingAs($right->parent->parent)->post('/panel/coupons/'.$coupon->id.'/cancel', ['reason' => 'other branch'])->assertNotFound();
        $this->actingAs($bayiLeft)->get('/panel/coupons/'.$coupon->id)->assertOk()->assertDontSee('coupon-cancel', false);
        $this->actingAs($bayiLeft)->post('/panel/coupons/'.$coupon->id.'/cancel', ['reason' => 'bayi cannot'])->assertForbidden();
        $this->assertSame('pending', $coupon->fresh()->status);

        $this->actingAs($bayiLeft->parent)->post('/panel/coupons/'.$coupon->id.'/cancel', ['reason' => 'customer request'])->assertRedirect();
        $this->assertSame('cancelled', $coupon->fresh()->status);
        $this->assertSame('10.00', WalletTransaction::query()->where('type', 'refund')->first()->amount);

        $this->actingAs($bayiLeft->parent)->post('/panel/coupons/'.$coupon->id.'/cancel', ['reason' => 'again']);
        $this->assertSame(1, WalletTransaction::query()->where('type', 'refund')->count());
        $this->assertSame('40.00', number_format((float) \DB::table('wallets')->where('user_id', $left->id)->where('currency', 'TRY')->value('balance'), 2, '.', ''));
        $this->assertTrue(ActivityLog::query()->where('action', 'coupon.cancelled')->exists());
        $this->artisan('wallet:verify')->assertOk();
    }

    public function test_superadmin_adjusts_members_in_its_tree_from_its_own_wallet(): void
    {
        [$member, $bayi] = $this->player('40.00');
        [$other] = $this->player('40.00');
        $superadmin = $bayi->parent;
        $balance = fn (User $user) => number_format((float) \DB::table('wallets')->where('user_id', $user->id)->where('currency', 'TRY')->value('balance'), 2, '.', '');
        $send = fn (User $actor, string $direction, string $amount) => $this->actingAs($actor)->post('/panel/users/'.$member->id.'/balance', [
            'direction' => $direction, 'amount' => $amount, 'note' => 'test', 'idempotency_key' => (string) Str::uuid(),
        ]);
        $owner = User::query()->where('role', 'owner')->firstOrFail();
        app(WalletService::class)->transfer($owner, $superadmin, '100.00', (string) Str::uuid(), $owner);
        $bayiBefore = $balance($bayi);
        $superBefore = $balance($superadmin);

        $send($superadmin, 'add', '25.00')->assertSessionHasNoErrors();
        $this->assertSame('65.00', $balance($member));
        $this->assertSame($bayiBefore, $balance($bayi));
        $this->assertSame(number_format((float) $superBefore - 25, 2, '.', ''), $balance($superadmin));

        $send($superadmin, 'remove', '100.00')->assertSessionHasErrors();
        $this->assertSame('65.00', $balance($member));

        $send($other->parent->parent, 'add', '5.00')->assertNotFound();
        $send(User::query()->where('role', 'owner')->firstOrFail(), 'add', '5.00')->assertNotFound();
        $this->actingAs($superadmin)->get('/panel/users?parent='.$bayi->id)->assertOk()->assertSee('openAdjust', false);
        $this->artisan('wallet:verify')->assertOk();
    }

    public function test_quick_status_and_password_reset_stay_inside_the_tree(): void
    {
        [$member, $bayi] = $this->player('10.00');
        [$other] = $this->player('10.00');
        $url = fn (User $user, string $action) => '/panel/users/'.$user->id.'/'.$action;

        $this->actingAs($bayi)->post($url($member, 'status'))->assertRedirect();
        $this->assertSame('passive', $member->fresh()->status->value);
        $this->actingAs($member->fresh())->get('/account')->assertRedirect();
        $this->assertGuest();

        $this->actingAs($bayi)->post($url($member, 'status'))->assertRedirect();
        $this->assertSame('active', $member->fresh()->status->value);
        $this->actingAs($other->parent)->post($url($member, 'status'))->assertNotFound();

        $old = $member->fresh()->password;
        $this->actingAs($bayi)->post($url($member, 'password'))->assertSessionHas('reset_password');
        $this->assertNotSame($old, $member->fresh()->password);
        $this->actingAs($bayi)->post($url($member, 'password'), ['password' => 'yeniSifre1'])->assertSessionHasNoErrors();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('yeniSifre1', $member->fresh()->password));
        $this->actingAs($bayi)->post($url($member, 'password'), ['password' => 'abc'])->assertSessionHasErrors('password');

        $this->actingAs($bayi->parent)->post($url($member, 'password'))->assertSessionHas('reset_password');
        $this->actingAs($bayi)->post($url($bayi->parent, 'password'))->assertNotFound();
        $this->actingAs($other->parent)->post($url($member, 'password'))->assertNotFound();
        $this->assertTrue(ActivityLog::query()->where('action', 'user.password_reset')->exists());
    }

    public function test_theme_follows_player_then_superadmin_then_classic(): void
    {
        [$member, $bayi] = $this->player('10.00');
        [$other] = $this->player('10.00');
        $superadmin = $bayi->parent;

        $this->get('/sport')->assertOk()->assertSee('data-theme="classic"', false);
        $this->actingAs($member)->get('/sport')->assertSee('data-theme="classic"', false);

        $this->actingAs($bayi)->get('/panel/theme')->assertNotFound();
        $this->actingAs($bayi)->post('/panel/theme', ['theme' => 'neon'])->assertNotFound();
        $this->actingAs($superadmin)->get('/panel/theme')->assertOk();
        $this->actingAs($superadmin)->post('/panel/theme', ['theme' => 'neon'])->assertSessionHasNoErrors();
        $this->actingAs($member)->get('/sport')->assertSee('data-theme="neon"', false);
        $this->actingAs($other)->get('/sport')->assertSee('data-theme="classic"', false);

        $this->actingAs($member)->post('/account/theme', ['theme' => 'desert'])->assertSessionHasNoErrors();
        $this->actingAs($member)->get('/sport')->assertSee('data-theme="desert"', false);
        $this->actingAs($member)->post('/account/theme', ['theme' => 'pink'])->assertSessionHasErrors('theme');
        $this->actingAs($member)->get('/account')->assertOk()->assertSee('Desert Night', false);
        $this->actingAs($member)->post('/account/theme', ['theme' => ''])->assertSessionHasNoErrors();
        $this->actingAs($member)->get('/sport')->assertSee('data-theme="neon"', false);
    }

    public function test_tipo_bridge_moves_the_wegas_wallet(): void
    {
        [$member] = $this->player('100.00');
        config(['services.ncs_bridge.secret' => 'test-secret', 'services.ncs_bridge.allowed_ips' => '']);
        $call = function (array $body, string $secret = 'test-secret') {
            $raw = json_encode($body);
            $ts = (string) time();

            return $this->call('POST', '/api/bridge/tipo', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_BRIDGE_TIMESTAMP' => $ts,
                'HTTP_X_BRIDGE_SIGNATURE' => hash_hmac('sha256', $ts.'.'.$raw, $secret),
            ], $raw);
        };
        $pid = 'wegas:'.$member->id;

        $call(['action' => 'getBalance', 'player_id' => $pid], 'wrong')->assertStatus(401);
        $call(['action' => 'getBalance', 'player_id' => $pid])->assertOk()->assertJson(['success' => true, 'balance' => 100, 'currency' => 'TRY']);
        $call(['action' => 'debit', 'player_id' => $pid, 'tx_id' => 't1', 'amount' => 30, 'bet_id' => 5])->assertOk()->assertJson(['balance' => 70, 'tx_id' => 't1']);
        $call(['action' => 'debit', 'player_id' => $pid, 'tx_id' => 't1', 'amount' => 30])->assertOk()->assertJson(['balance' => 70]);
        $call(['action' => 'debit', 'player_id' => $pid, 'tx_id' => 't2', 'amount' => 500])->assertStatus(422)->assertJson(['error' => 'Yetersiz bakiye.']);
        $call(['action' => 'credit', 'player_id' => $pid, 'tx_id' => 't3', 'amount' => 45])->assertOk()->assertJson(['balance' => 115]);
        $call(['action' => 'rollback', 'player_id' => $pid, 'tx_id' => 't1'])->assertOk()->assertJson(['balance' => 145, 'tx_id' => 'rollback:t1']);
        $call(['action' => 'rollback', 'player_id' => $pid, 'tx_id' => 't1'])->assertOk()->assertJson(['balance' => 145]);
        $call(['action' => 'getBalance', 'player_id' => 'wegas:999999'])->assertStatus(422);
        $call(['action' => 'getBalance', 'player_id' => (string) $member->id])->assertStatus(422);
        $this->artisan('wallet:verify')->assertOk();
    }

    public function test_wegas_sport_page_opens_the_bridge_iframe(): void
    {
        [$member] = $this->player('10.00');
        config(['services.ncs_bridge.secret' => 'test-secret', 'services.ncs_bridge.url' => 'https://ncs.test']);
        \Illuminate\Support\Facades\Http::fake(['https://ncs.test/callback/wegas-session' => \Illuminate\Support\Facades\Http::response(['success' => true, 'url' => 'https://sports.test/play?t=1'])]);

        $this->actingAs($member)->get('/wegas-spor')->assertOk()->assertSee('https://sports.test/play?t=1', false);
        \Illuminate\Support\Facades\Http::assertSent(fn ($request) => $request->url() === 'https://ncs.test/callback/wegas-session'
            && $request->hasHeader('X-Bridge-Signature')
            && json_decode($request->body(), true)['user_id'] === $member->id);
        $this->actingAs($member)->get('/sport')->assertSee(route('site.wegas_sport'), false);

        $member->forceFill(['language' => 'ar'])->save();
        $this->actingAs($member->fresh())->get('/wegas-spor')->assertNotFound();
    }

    public function test_onegamex_sync_and_wallet_callbacks(): void
    {
        [$member] = $this->player('100.00');
        config(['casino.onegamex.url' => 'https://gx.test', 'casino.onegamex.token_name' => 'tok', 'casino.onegamex.password' => 'pw', 'casino.onegamex.secret_key' => 'sk', 'casino.onegamex.verify_signature' => false]);
        \Illuminate\Support\Facades\Http::fake(['https://gx.test/GameList' => \Illuminate\Support\Facades\Http::response(['result' => 1, 'games' => [
            'evolution' => [['id' => 501, 'name' => 'Lightning Roulette', 'type' => 'live', 'image' => 'https://cdn.test/501.png']],
        ]])]);

        $this->assertSame(1, app(\App\Services\Casino\OneGameXProvider::class)->syncGames());
        $game = \App\Models\CasinoGame::query()->where('external_id', '501')->firstOrFail();
        $this->assertSame('evolution', $game->vendor);
        $this->assertTrue((bool) $game->is_live);

        $code = app(\App\Services\Casino\CasinoUserCode::class)->forUser($member);
        $call = fn (array $body) => $this->postJson('/api/casino/onegamex/callback', $body + ['userId' => $code, 'signature' => 'in-sig']);

        $call([])->assertOk()->assertJson(['result' => 1, 'balance' => 100, 'signature' => sha1('in-sig'.'sk')]);
        $call(['transactionId' => 't1', 'gameId' => '501', 'bet' => 30, 'win' => 0])->assertJson(['result' => 1, 'balance' => 70]);
        $call(['transactionId' => 't1', 'gameId' => '501', 'bet' => 30, 'win' => 0])->assertJson(['result' => 1, 'balance' => 70]);
        $call(['transactionId' => 't2', 'gameId' => '501', 'bet' => 10, 'win' => 25])->assertJson(['result' => 1, 'balance' => 85]);
        $call(['transactionId' => 't3', 'gameId' => '501', 'bet' => 500, 'win' => 0])->assertJson(['result' => 0, 'balance' => 85]);
        $call(['transactionId' => 't1', 'gameId' => '501', 'amount' => 30])->assertJson(['result' => 1, 'balance' => 115]);
        $call(['transactionId' => 't1', 'gameId' => '501', 'amount' => 30])->assertJson(['result' => 1, 'balance' => 115]);
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
        $this->actingAs($bayi)->get('/panel/sport/limits')->assertNotFound();
        $this->actingAs($bayi)->put('/panel/sport/limits', ['currency' => 'TRY'])->assertNotFound();
        $this->actingAs($bayi)->post('/panel/sport/limits/restore', ['currency' => 'TRY'])->assertNotFound();
        $this->actingAs($bayi)->get('/panel/coupons/risky')->assertNotFound();
        $this->actingAs($bayi)->get('/panel/sport/overdrafts')->assertNotFound();
        $this->actingAs($bayi)->get('/panel/coupons')->assertOk()->assertDontSee(route('panel.sport.limits'), false);

        $superadmin = $bayi->parent;
        $this->actingAs($superadmin)->get('/panel/sport/limits')
            ->assertOk()
            ->assertSee('Bahis Limitleri', false)
            ->assertSee('Üst sınır: 10.000 ₺', false)
            ->assertDontSee('unlimited[', false);
        $payload = SportLimitCatalog::for('TRY');
        $payload['currency'] = 'TRY';
        $payload['max_stake_general'] = '50000';
        $payload['unlimited'] = ['max_coupon_odds' => '1'];
        $this->actingAs($superadmin)->from('/panel/sport/limits')->put('/panel/sport/limits', $payload)
            ->assertSessionHasErrors(['max_stake_general', 'max_coupon_odds']);

        unset($payload['unlimited']);
        $payload['max_stake_general'] = '40.00';
        $this->actingAs($superadmin)->put('/panel/sport/limits', $payload)->assertRedirect();
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
            ->assertSee(__('sport.panel.limit_fields.cancel_minutes'), false)
            ->assertDontSee(__('sport.panel.limit_fields.cancel_minutes').' (dk)', false)
            ->assertSee('value="10.000"', false)
            ->assertDontSee('value="10.000,00"', false)
            ->assertSee('uppercase tracking-wide', false)
            ->assertDontSee('hidden text-sm text-slate-500 sm:inline', false)
            ->assertSee('inputmode="decimal"', false)
            ->assertSee('w-[120px]', false)
            ->assertSee('lg:w-[180px]', false)
            ->assertDontSee('Üst hesap sınırlı', false);
        $html = $page->getContent();
        preg_match_all('/<summary[^>]*>\s*<span>([^<]+)<\/span>/', $html, $groupTitles);
        $this->assertSame(['Kupon', 'Kazanç Limitleri', 'Bahis Limitleri', 'Oran Koruması'], $groupTitles[1]);
        $this->assertSame('10.000', SportLimitFields::formatInput('max_stake_general', '10000.00', ',', '.'));
        $this->assertSame('10,50', SportLimitFields::formatInput('max_stake_general', '10.50', ',', '.'));
        $this->assertSame('1.01', SportLimitFields::formatInput('min_coupon_odds', '1.01', ',', '.'));
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
