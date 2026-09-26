<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CasinoGame;
use App\Models\Coupon;
use App\Models\CouponSelection;
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
use App\Services\Sport\FootballBudget;
use App\Services\WalletService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
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

    public function test_settle_check_does_not_request_scores_for_closed_coupons(): void
    {
        [$member, $bayi] = $this->player('50.00');
        $closedIds = [];
        foreach (['cancelled', 'void', 'refunded'] as $status) {
            $coupon = $this->placeCombo($member, [
                $this->pricedOdd('2.00', now()->addMinutes(5)),
                $this->pricedOdd('1.50', now()->addMinutes(5)),
            ]);
            if ($status === 'cancelled') {
                app(CouponCanceller::class)->cancel($bayi, $coupon, 'panel', '127.0.0.1');
            } else {
                $coupon->status = $status;
                $coupon->save();
            }
            $coupon->load('selections.fixture');
            foreach ($coupon->selections as $selection) {
                $closedIds[] = (string) $selection->fixture->api_id;
                $this->assertSame('pending', $selection->status);
            }
        }

        $pending = $this->placeCombo($member, [
            $this->pricedOdd('2.00', now()->addMinutes(5)),
            $this->pricedOdd('1.50', now()->addMinutes(5)),
        ]);
        $pending->load('selections.fixture');
        $pendingIds = $pending->selections->map(fn ($selection) => (string) $selection->fixture->api_id)->sort()->values()->all();

        Carbon::setTestNow(now()->addHours(3));
        Http::swap(new Factory);
        Http::fake(function ($request) use ($pendingIds, $closedIds) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $ids = collect(explode('-', (string) ($query['ids'] ?? '')))->filter()->sort()->values()->all();
            $this->assertSame($pendingIds, $ids);
            foreach ($closedIds as $apiId) {
                $this->assertNotContains($apiId, $ids);
            }

            return Http::response(['response' => []]);
        });

        $this->artisan('sport:settle-check');
        Http::assertSentCount(1);
        $this->assertSame(3, Coupon::query()->whereIn('status', ['cancelled', 'void', 'refunded'])->count());
        $this->assertSame(6, CouponSelection::query()->whereHas('coupon', fn ($query) => $query->whereIn('status', ['cancelled', 'void', 'refunded']))->where('status', 'pending')->count());
    }

    public function test_cancelled_coupon_shows_cancel_badge_and_hides_the_ancestor(): void
    {
        [$member, $bayi] = $this->player('50.00');
        $coupon = $this->placeCombo($member, [
            $this->pricedOdd('2.00', now()->addMinutes(30)),
            $this->pricedOdd('1.50', now()->addMinutes(30)),
        ]);
        app(CouponCanceller::class)->cancel($bayi, $coupon, 'panel', '127.0.0.1');
        $coupon->refresh();

        $this->assertSame('pending', $coupon->selections()->first()->status);
        $this->actingAs($member)->get(route('site.coupons.show', $coupon))
            ->assertOk()
            ->assertSee(__('sport.selection.cancelled'), false)
            ->assertDontSee(__('sport.selection.pending'), false)
            ->assertSee(__('sport.coupon.stake'), false)
            ->assertSee(__('sport.coupon.total_odds'), false)
            ->assertSee(__('sport.coupon.potential_win'), false)
            ->assertSee(__('sport.coupon.refund_amount'), false)
            ->assertSee(Money::format('10.00', Currency::Try), false)
            ->assertSee('panel', false)
            ->assertSee(__('wallet.upper_account'), false)
            ->assertSee(__('sport.coupon.cancelled_by'), false)
            ->assertSee(__('sport.coupon.cancelled_at'), false)
            ->assertDontSee($bayi->username, false);

        $this->actingAs($bayi)->get(route('panel.coupons.show', $coupon))
            ->assertOk()
            ->assertSee(__('sport.selection.cancelled'), false)
            ->assertDontSee(__('sport.selection.pending'), false)
            ->assertSee($bayi->username, false)
            ->assertSee(__('sport.coupon.refund_amount'), false);
    }

    public function test_placement_writes_the_pre_match_snapshot(): void
    {
        [$member] = $this->player('50.00');
        $coupon = $this->placeCombo($member, [
            $this->pricedOdd('2.00', now()->addHour()),
            $this->pricedOdd('1.50', now()->addHour()),
        ]);
        $selection = $coupon->selections()->first();

        $this->assertNotNull($coupon->placed_at);
        $this->assertSame('NS', $selection->placed_status);
        $this->assertNull($selection->placed_minute);
        $this->assertNull($selection->placed_home);
        $this->assertNull($selection->placed_away);
    }

    public function test_live_sync_does_not_call_the_api_without_a_due_fixture(): void
    {
        [$member] = $this->player('50.00');
        $first = $this->pricedOdd('2.00', now()->addHour());
        $this->placeCombo($member, [$first, $this->pricedOdd('1.50', now()->addHour())]);

        $this->artisan('sport:live-sync');
        Http::assertNothingSent();

        $first->fixture->selections()->update(['kickoff_at' => now()->subMinute()]);
        CouponSelection::query()->update(['kickoff_at' => now()->subMinute()]);
        Http::swap(new Factory);
        Http::fake(function ($request) use ($first) {
            $this->assertStringContainsString('live=all', urldecode($request->url()));

            return Http::response([
                'errors' => null,
                'response' => [[
                    'fixture' => [
                        'id' => $first->fixture->api_id,
                        'status' => ['short' => '1H', 'elapsed' => 34],
                    ],
                    'goals' => ['home' => 1, 'away' => 0],
                    'score' => ['halftime' => ['home' => null, 'away' => null]],
                ]],
            ]);
        });

        $this->artisan('sport:live-sync');
        $first->fixture->refresh();
        $this->assertSame('1H', $first->fixture->status);
        $this->assertSame(34, $first->fixture->elapsed);
        $this->assertSame('1', (string) $first->fixture->score_home);
        $this->assertSame(1, app(FootballBudget::class)->usedChannel('live-sync'));
    }

    public function test_a_fixture_that_drops_off_live_is_fetched_once_and_settled(): void
    {
        [$member] = $this->player('50.00');
        $finished = $this->pricedOdd('2.00', now()->addMinutes(5));
        $stillOpen = $this->pricedOdd('1.50', now()->addMinutes(5));
        $coupon = $this->placeCombo($member, [$finished, $stillOpen]);
        $coupon->selections()->update(['kickoff_at' => now()->subMinute()]);
        $finished->fixture->update(['status' => '2H', 'elapsed' => 80, 'score_home' => 0, 'score_away' => 0]);
        $started = now()->subMinutes(90);

        Http::swap(new Factory);
        Http::fake(function ($request) use ($finished, $stillOpen, $started) {
            $url = urldecode($request->url());
            if (str_contains($url, 'live=all')) {
                return Http::response(['errors' => null, 'response' => []]);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->assertSame([(string) $finished->fixture->api_id], explode('-', (string) ($query['ids'] ?? '')));
            $this->assertStringNotContainsString((string) $stillOpen->fixture->api_id, (string) ($query['ids'] ?? ''));

            return Http::response([
                'errors' => null,
                'response' => [$this->apiRow($finished->fixture, $started, 0, 1)],
            ]);
        });

        $this->artisan('sport:live-sync');

        $finished->fixture->refresh();
        $stillOpen->fixture->refresh();
        $coupon->refresh();
        $this->assertSame('FT', $finished->fixture->status);
        $this->assertSame(0, $finished->fixture->ft_home);
        $this->assertSame(1, $finished->fixture->ft_away);
        $this->assertSame($started->timestamp, $finished->fixture->played_at->timestamp);
        $this->assertSame('NS', $stillOpen->fixture->status);
        $this->assertSame('lost', $coupon->status);
        $this->assertSame('lost', $coupon->selections()->where('fixture_id', $finished->fixture_id)->value('status'));
        $this->assertSame('40.00', $member->wallet()->first()->fresh()->balance);
        $this->assertSame(2, app(FootballBudget::class)->usedChannel('live-sync'));
        Http::assertSentCount(2);
        $this->artisan('wallet:verify')->assertOk();
    }

    public function test_pending_selection_on_a_finished_fixture_waits_for_the_result(): void
    {
        [$member] = $this->player('50.00');
        $coupon = $this->placeCombo($member, [
            $this->pricedOdd('2.00', now()->addHour()),
            $this->pricedOdd('1.50', now()->addHour()),
        ]);
        $selection = $coupon->selections()->first();
        $selection->fixture->update(['status' => 'FT', 'ft_home' => 2, 'ft_away' => 1]);

        $this->actingAs($member)->get(route('site.coupons.show', $coupon))
            ->assertOk()
            ->assertSee('Maç bitti, sonuç bekleniyor', false);
        $this->actingAs($member)->getJson(route('site.coupons.live', ['ids' => $coupon->id]))
            ->assertOk()
            ->assertJsonFragment(['text' => 'Maç bitti, sonuç bekleniyor', 'live' => false]);

        foreach ([
            'tr' => 'Maç bitti, sonuç bekleniyor',
            'en' => 'Match finished, waiting for the result',
            'de' => 'Spiel beendet, Ergebnis ausstehend',
            'ar' => 'انتهت المباراة، بانتظار النتيجة',
        ] as $locale => $text) {
            app()->setLocale($locale);
            $this->assertSame($text, __('sport.live.awaiting_result'));
        }
    }

    public function test_live_endpoint_does_not_return_another_members_coupon(): void
    {
        [$member] = $this->player('50.00');
        $coupon = $this->placeCombo($member, [
            $this->pricedOdd('2.00', now()->addHour()),
            $this->pricedOdd('1.50', now()->addHour()),
        ]);
        [$other] = $this->player('50.00');

        $this->actingAs($other)->getJson(route('site.coupons.live', ['ids' => $coupon->id]))->assertNotFound();
        $this->actingAs($member)->getJson(route('site.coupons.live', ['ids' => $coupon->id]))
            ->assertOk()
            ->assertJsonPath('selections.0.id', $coupon->selections()->first()->id);
    }

    public function test_finish_after_the_void_window_voids_even_if_the_deadline_run_was_missed(): void
    {
        [$member] = $this->player('50.00');
        $first = $this->pricedOdd('2.00', now()->addMinutes(5));
        $second = $this->pricedOdd('1.50', now()->addMinutes(5));
        $coupon = $this->placeCombo($member, [$first, $second]);
        $kickoff = $coupon->selections()->first()->kickoff_at->copy();
        $first->fixture->update(['status' => 'PST']);
        $second->fixture->update(['status' => 'PST']);
        $started = $kickoff->copy()->addHours(60);

        Carbon::setTestNow($started);
        $this->fakeFinalFixtures([
            $this->apiRow($first->fixture, $started, 2, 0),
            $this->apiRow($second->fixture, $started, 1, 0),
        ]);
        $this->artisan('sport:settle-check');

        $coupon->refresh();
        $first->fixture->refresh();
        $this->assertSame('FT', $first->fixture->status);
        $this->assertSame($started->timestamp, $first->fixture->played_at->timestamp);
        $this->assertSame('void', $coupon->status);
        $this->assertTrue($coupon->selections->every(fn ($selection) => $selection->status === 'void'));
        $this->assertSame(0, WalletTransaction::query()->where('type', 'win')->count());
        $this->assertSame('50.00', $member->wallet()->first()->fresh()->balance);
        $this->artisan('wallet:verify')->assertOk();
    }

    public function test_finish_inside_the_void_window_settles_from_the_actual_start(): void
    {
        [$member] = $this->player('50.00');
        $first = $this->pricedOdd('2.00', now()->addMinutes(5));
        $second = $this->pricedOdd('1.50', now()->addMinutes(5));
        $coupon = $this->placeCombo($member, [$first, $second]);
        $kickoff = $coupon->selections()->first()->kickoff_at->copy();
        $first->fixture->update(['status' => 'PST']);
        $second->fixture->update(['status' => 'PST']);
        $started = $kickoff->copy()->addHours(20);

        Carbon::setTestNow($started);
        $this->fakeFinalFixtures([
            $this->apiRow($first->fixture, $started, 2, 0),
            $this->apiRow($second->fixture, $started, 1, 0),
        ]);
        $this->artisan('sport:settle-check');

        $coupon->refresh();
        $this->assertSame($started->timestamp, $first->fixture->fresh()->played_at->timestamp);
        $this->assertSame('won', $coupon->status);
        $this->assertSame('won', $coupon->selections()->where('fixture_id', $first->fixture_id)->value('status'));
        $this->assertSame('70.00', $member->wallet()->first()->fresh()->balance);
        $this->artisan('wallet:verify')->assertOk();
    }

    public function test_manual_played_at_outside_the_window_voids_the_selection(): void
    {
        [$member, , $owner] = $this->player('50.00');
        $coupon = $this->combo($member, [
            ['outcome' => 'home', 'odd' => '2.00', 'ft' => [2, 0]],
            ['outcome' => 'home', 'odd' => '1.50', 'ft' => [1, 0]],
        ]);
        $this->travelAndSettle();
        $fixture = $coupon->selections()->first()->fixture;
        $kickoff = $coupon->selections()->first()->kickoff_at;

        $this->actingAs($owner)->post(route('panel.sport.fixtures.score', $fixture), [
            'ht_home' => 1, 'ht_away' => 0, 'ft_home' => 3, 'ft_away' => 1,
        ])->assertSessionHasErrors('played_at');

        $this->actingAs($owner)->post(route('panel.sport.fixtures.score', $fixture), [
            'ht_home' => 1, 'ht_away' => 0, 'ft_home' => 3, 'ft_away' => 1,
            'played_at' => $this->playedAtInput($kickoff->copy()->addHours(60)),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $fixture->refresh();
        $this->assertSame('manual', $fixture->score_source);
        $this->assertNull($fixture->score_home);
        $this->assertSame($kickoff->copy()->addHours(60)->timezone('Europe/Istanbul')->startOfMinute()->utc()->timestamp, $fixture->played_at->timestamp);
        $this->assertSame('void', $coupon->selections()->where('fixture_id', $fixture->id)->value('status'));
        $this->assertSame('won', $coupon->selections()->where('fixture_id', '!=', $fixture->id)->value('status'));
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
            'played_at' => $this->playedAtInput($fixture->starts_at),
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

        Carbon::setTestNow(now()->addSecond());
        app(WalletService::class)->transfer($member, $bayi, '50.00', 'withdraw-win', $bayi);
        $this->assertSame('0.00', $member->wallet()->first()->fresh()->balance);

        $fixture = $coupon->selections()->first()->fixture;
        $this->actingAs($owner)->post(route('panel.sport.fixtures.score', $fixture), [
            'ht_home' => 0, 'ht_away' => 1, 'ft_home' => 0, 'ft_away' => 1,
            'played_at' => $this->playedAtInput($fixture->starts_at),
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

        Carbon::setTestNow(now()->addSeconds(5));
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

    private function playedAtInput(Carbon $moment): string
    {
        return $moment->copy()->timezone('Europe/Istanbul')->format('Y-m-d\TH:i');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function fakeFinalFixtures(array $rows): void
    {
        Http::swap(new Factory);
        Http::fake(function () use ($rows) {
            return Http::response(['response' => $rows, 'errors' => null]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function apiRow(SportFixture $fixture, Carbon $started, int $home, int $away): array
    {
        return [
            'fixture' => [
                'id' => $fixture->api_id,
                'timestamp' => $started->timestamp,
                'status' => ['short' => 'FT'],
            ],
            'goals' => ['home' => $home, 'away' => $away],
            'score' => [
                'halftime' => ['home' => min($home, 1), 'away' => min($away, 1)],
                'fulltime' => ['home' => $home, 'away' => $away],
            ],
        ];
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
