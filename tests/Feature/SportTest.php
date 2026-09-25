<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Models\SportCountry;
use App\Models\SportFixture;
use App\Models\SportLeague;
use App\Models\SportMargin;
use App\Models\SportMarket;
use App\Models\SportOdd;
use App\Models\SportTeam;
use App\Services\Sport\CouponCalculator;
use App\Services\Sport\FootballBudget;
use App\Services\Sport\MarginEngine;
use App\Services\Sport\OddsMapper;
use App\Services\Sport\SportSync;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SportTest extends TestCase
{
    use RefreshDatabase;

    public function test_sample_payloads_map_fixtures_and_odds(): void
    {
        $country = SportCountry::query()->create(['name' => 'England']);
        $league = SportLeague::query()->create([
            'api_id' => 39, 'country_id' => $country->id, 'name' => 'Premier League', 'season' => 2026, 'is_active' => true,
        ]);
        $sync = app(SportSync::class);
        $fixture = $sync->storeFixture($league, [
            'fixture' => ['id' => 10, 'date' => '2026-09-26T19:00:00+00:00', 'status' => ['short' => 'NS']],
            'teams' => [
                'home' => ['id' => 1, 'name' => 'Home FC', 'logo' => null],
                'away' => ['id' => 2, 'name' => 'Away FC', 'logo' => null],
            ],
            'goals' => ['home' => null, 'away' => null],
            'score' => ['halftime' => ['home' => null, 'away' => null]],
        ]);
        $count = $sync->storeOdds([
            'fixture' => ['id' => 10],
            'update' => '2026-09-25T12:00:00+00:00',
            'bookmakers' => [[
                'id' => 8,
                'bets' => [
                    ['id' => 1, 'values' => [['value' => 'Home', 'odd' => '1.50'], ['value' => 'Draw', 'odd' => '3.40'], ['value' => 'Away', 'odd' => '5.00']]],
                    ['id' => 5, 'values' => [['value' => 'Over 2.5', 'odd' => '1.85'], ['value' => 'Under 2.5', 'odd' => '1.95'], ['value' => 'Over 4.5', 'odd' => '9.00']]],
                    ['id' => 8, 'values' => [['value' => 'Yes', 'odd' => '1.40'], ['value' => 'No', 'odd' => '2.75']]],
                ],
            ]],
        ], false);

        $this->assertSame(1001, $fixture->bulletin_code);
        $this->assertSame(7, $count);
        $this->assertSame('1.50', SportOdd::query()->where('outcome', 'home')->first()->raw_odd);
        $this->assertSame([], app(OddsMapper::class)->map(['id' => 5, 'values' => [['value' => 'Over 4.5', 'odd' => '9.00']]]));
    }

    public function test_margin_rounds_to_two_decimals_and_respects_the_floor(): void
    {
        $fixture = $this->fixture();
        SportMargin::query()->where('layer', 'global')->update(['margin' => '0.0750', 'max_odd' => '3.00']);
        $engine = app(MarginEngine::class);

        $this->assertSame('1.86', $engine->show('2.00', $fixture, '1X2', null));
        $this->assertSame('1.01', $engine->show('1.02', $fixture, '1X2', null));
        $this->assertSame('3.00', $engine->show('10.00', $fixture, '1X2', null));
    }

    public function test_budget_stops_non_critical_calls(): void
    {
        Cache::put('football:requests:'.now()->utc()->toDateString(), 6500);
        $budget = app(FootballBudget::class);

        $this->assertFalse($budget->allows(false));
        $this->assertTrue($budget->allows(true));
    }

    public function test_started_match_odds_are_suspended(): void
    {
        $fixture = $this->fixture();
        $fixture->starts_at = now()->subHour();
        $fixture->status = '1H';
        $fixture->save();
        $market = SportMarket::query()->where('code', '1X2')->first();
        SportOdd::query()->create([
            'fixture_id' => $fixture->id, 'market_id' => $market->id, 'outcome' => 'home',
            'raw_odd' => '1.50', 'shown_odd' => '1.50', 'suspended' => false,
        ]);

        app(SportSync::class)->suspendStarted();

        $this->assertTrue(SportOdd::query()->first()->suspended);
    }

    public function test_bulletin_renders_in_four_languages(): void
    {
        foreach (['tr', 'en', 'de'] as $locale) {
            $this->get('/sport?lang='.$locale)->assertOk()->assertSee('dir="ltr"', false);
        }
        $this->get('/sport?lang=ar')->assertOk()->assertSee('dir="rtl"', false)->assertSee(__('sport.all', [], 'ar'), false);
    }

    public function test_bulletin_and_detail_show_the_same_outcome_price(): void
    {
        $fixture = $this->fixture();
        $markets = SportMarket::query()->whereIn('code', ['1X2', 'DC'])->get()->keyBy('code');
        foreach (['away' => '5.50', 'draw' => '4.10', 'home' => '1.57'] as $outcome => $price) {
            SportOdd::query()->create([
                'fixture_id' => $fixture->id, 'market_id' => $markets['1X2']->id, 'outcome' => $outcome, 'raw_odd' => $price, 'shown_odd' => $price,
            ]);
        }
        foreach (['draw_away' => '1.20', 'home_away' => '1.30', 'home_draw' => '1.10'] as $outcome => $price) {
            SportOdd::query()->create([
                'fixture_id' => $fixture->id, 'market_id' => $markets['DC']->id, 'outcome' => $outcome, 'raw_odd' => $price, 'shown_odd' => $price,
            ]);
        }

        foreach (['/sport', '/sport/fixtures/'.$fixture->id] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/data-outcome="home".*?1\.57.*?data-outcome="draw".*?4\.10.*?data-outcome="away".*?5\.50/s', $html);
        }
        $detail = $this->get('/sport/fixtures/'.$fixture->id)->getContent();
        $this->assertMatchesRegularExpression('/data-outcome="home_draw".*?1\.10.*?data-outcome="home_away".*?1\.30.*?data-outcome="draw_away".*?1\.20/s', $detail);

        $home = SportOdd::query()->where('outcome', 'home')->first();
        $this->post('/sport/odds/'.$home->id);
        $selection = session('sport.coupon')['selections'][0];
        $this->assertSame('home', $selection['outcome']);
        $this->assertSame('1.57', $selection['shown']);
    }

    public function test_combo_payout_uses_the_rounded_total_odd(): void
    {
        $calculator = app(CouponCalculator::class);

        $this->assertSame('2.64', $calculator->total('combo', ['1.44', '1.83']));
        $this->assertSame('264.00', $calculator->payout('combo', '100', ['1.44', '1.83']));
        $this->assertSame('327.00', $calculator->payout('single', '100', ['1.44', '1.83']));

        $first = $this->pricedFixture('1.44');
        $second = $this->pricedFixture('1.83');
        $this->post('/sport/odds/'.$first->id);
        $this->post('/sport/odds/'.$second->id);
        $this->post('/sport/coupon', ['stake' => '100', 'mode' => 'combo']);

        $this->get('/sport?lang=tr')
            ->assertOk()
            ->assertSee('2.64', false)
            ->assertSee(Money::format('264.00', Currency::Try), false);
    }

    public function test_empty_today_bulletin_falls_back_to_all_and_hides_empty_leagues(): void
    {
        $visible = $this->fixture();
        $market = SportMarket::query()->where('code', '1X2')->first();
        SportOdd::query()->create([
            'fixture_id' => $visible->id, 'market_id' => $market->id, 'outcome' => 'home', 'raw_odd' => '1.50', 'shown_odd' => '1.50',
        ]);
        $empty = SportLeague::query()->create([
            'api_id' => random_int(1000, 9999), 'country_id' => $visible->league->country_id, 'name' => 'Empty League', 'season' => 2026, 'is_active' => true,
        ]);

        $page = $this->get('/sport')->assertOk();
        $page->assertSee($visible->league->name, false);
        $page->assertDontSee($empty->name, false);
        $page->assertSee($visible->home->name, false);
        $page->assertSee($visible->away->name, false);
        $page->assertDontSee('title="'.$visible->home->name.' – '.$visible->away->name.'"', false);
    }

    public function test_live_and_results_pages_have_their_own_urls(): void
    {
        $live = $this->fixture();
        $live->update(['status' => '2H', 'starts_at' => now()->subHour(), 'score_home' => 1, 'score_away' => 0]);
        $done = $this->fixture();
        $done->update([
            'status' => 'FT',
            'starts_at' => now()->subDay(),
            'score_home' => 2,
            'score_away' => 1,
            'ht_home' => 1,
            'ht_away' => 0,
        ]);

        $this->get('/sport/live')->assertOk()
            ->assertSee(__('site.live'), false)
            ->assertSee($live->home->name, false)
            ->assertSee(__('sport.statuses.2H'), false)
            ->assertDontSee('>2H<', false)
            ->assertSee(__('sport.live_odds_soon'), false);
        $this->get('/sport/results')->assertOk()
            ->assertSee(__('site.results'), false)
            ->assertSee($done->home->name, false)
            ->assertSee(__('sport.half_time'), false);
        $this->get('/live-casino')->assertOk();
        $this->get('/live')->assertRedirect('/live-casino');

        $html = $this->get('/sport?lang=tr')->assertOk()->getContent();
        $this->assertStringContainsString(__('site.live', [], 'tr'), $html);
        $this->assertStringContainsString('/sport/live', $html);
        $this->assertStringContainsString('/live-casino', $html);
        $this->assertStringContainsString('/sport/results', $html);
        $this->assertStringNotContainsString('href="'.url('/live').'"', $html);

        $this->assertStringNotContainsString('truncate', file_get_contents(resource_path('views/site/sport/_row.blade.php')));
        $this->assertStringNotContainsString('truncate', file_get_contents(resource_path('views/site/sport/_card.blade.php')));
        $this->assertStringNotContainsString('truncate', file_get_contents(resource_path('views/site/sport/_coupon.blade.php')));
        $this->assertStringNotContainsString('truncate', file_get_contents(resource_path('views/site/sport/_live.blade.php')));
        $this->assertStringNotContainsString('truncate', file_get_contents(resource_path('views/site/sport/show.blade.php')));
    }

    public function test_second_selection_from_the_same_match_replaces_the_first(): void
    {
        $fixture = $this->fixture();
        $market = SportMarket::query()->where('code', '1X2')->first();
        $home = SportOdd::query()->create([
            'fixture_id' => $fixture->id, 'market_id' => $market->id, 'outcome' => 'home', 'raw_odd' => '1.50', 'shown_odd' => '1.50',
        ]);
        $draw = SportOdd::query()->create([
            'fixture_id' => $fixture->id, 'market_id' => $market->id, 'outcome' => 'draw', 'raw_odd' => '3.20', 'shown_odd' => '3.20',
        ]);

        $this->post('/sport/odds/'.$home->id)->assertRedirect();
        $this->post('/sport/odds/'.$draw->id)->assertRedirect();
        $coupon = session('sport.coupon');
        $this->assertCount(1, $coupon['selections']);
        $this->assertSame($draw->id, $coupon['selections'][0]['odd_id']);
    }

    private function pricedFixture(string $price): SportOdd
    {
        $fixture = $this->fixture();
        $market = SportMarket::query()->where('code', '1X2')->first();

        return SportOdd::query()->create([
            'fixture_id' => $fixture->id, 'market_id' => $market->id, 'outcome' => 'home', 'raw_odd' => $price, 'shown_odd' => $price,
        ]);
    }

    private function fixture(): SportFixture
    {
        $country = SportCountry::query()->create(['name' => 'England '.uniqid()]);
        $league = SportLeague::query()->create([
            'api_id' => random_int(1000, 9999), 'country_id' => $country->id, 'name' => 'League', 'season' => 2026, 'is_active' => true,
        ]);
        $home = SportTeam::query()->create(['api_id' => random_int(1000, 9999), 'name' => 'Home']);
        $away = SportTeam::query()->create(['api_id' => random_int(1000, 9999), 'name' => 'Away']);

        return SportFixture::query()->create([
            'api_id' => random_int(1000, 9999),
            'league_id' => $league->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'starts_at' => now()->addDay(),
            'status' => 'NS',
            'bulletin_code' => random_int(1000, 99999),
        ]);
    }
}
