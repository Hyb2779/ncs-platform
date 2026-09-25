<?php

namespace Tests\Feature;

use App\Models\SportCountry;
use App\Models\SportFixture;
use App\Models\SportLeague;
use App\Models\SportMarket;
use App\Models\SportOdd;
use App\Models\SportTeam;
use App\Services\Sport\FootballBudget;
use App\Services\Sport\MarginEngine;
use App\Services\Sport\OddsMapper;
use App\Services\Sport\SportSync;
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
        \App\Models\SportMargin::query()->where('layer', 'global')->update(['margin' => '0.0750', 'max_odd' => '3.00']);
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
