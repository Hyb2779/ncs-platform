<?php

namespace Tests\Feature;

use App\Jobs\TranslateSportNames;
use App\Models\SportCountry;
use App\Models\SportFixture;
use App\Models\SportLeague;
use App\Models\SportMarket;
use App\Models\SportOdd;
use App\Models\SportTeam;
use App\Models\SportTranslation;
use App\Services\Sport\SportTranslator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SportTranslationTest extends TestCase
{
    use RefreshDatabase;

    public function test_translated_name_is_shown_and_english_is_the_fallback(): void
    {
        $fixture = $this->fixture();
        SportTranslation::query()->create([
            'entity_type' => 'team',
            'entity_id' => $fixture->home_team_id,
            'locale' => 'ar',
            'name' => 'بولندا',
            'source' => 'auto',
        ]);

        app()->setLocale('ar');
        $this->assertSame('بولندا', sport_name($fixture->home));
        $this->assertSame('Away', sport_name($fixture->away));

        $this->get('/sport/fixtures/'.$fixture->id.'?lang=ar')
            ->assertOk()
            ->assertSee('بولندا', false)
            ->assertSee('Away', false);
    }

    public function test_manual_translation_is_not_replaced(): void
    {
        $team = SportTeam::query()->create(['api_id' => 501, 'name' => 'Poland']);
        SportTranslation::query()->create([
            'entity_type' => 'team', 'entity_id' => $team->id, 'locale' => 'ar', 'name' => 'بولندا', 'source' => 'manual',
        ]);
        config(['services.anthropic.key' => 'test-key']);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'text' => json_encode(['items' => [[
                        'id' => 'team:'.$team->id,
                        'tr' => 'Polonya',
                        'de' => 'Polen',
                        'ar' => 'بولندا الجديدة',
                    ]]]),
                ]],
            ]),
        ]);

        app(SportTranslator::class)->translate();

        $this->assertSame('بولندا', SportTranslation::query()->where('locale', 'ar')->first()->name);
        $this->assertSame('manual', SportTranslation::query()->where('locale', 'ar')->first()->source);
        $this->assertSame('auto', SportTranslation::query()->where('locale', 'tr')->first()->source);
        $this->assertSame('Polonya', SportTranslation::query()->where('locale', 'tr')->first()->name);
    }

    public function test_job_skips_when_the_api_key_is_missing(): void
    {
        SportTeam::query()->create(['api_id' => 502, 'name' => 'Latvia']);
        config(['services.anthropic.key' => null]);
        Http::fake();

        (new TranslateSportNames)->handle(app(SportTranslator::class));

        Http::assertNothingSent();
        $this->assertSame(0, SportTranslation::query()->count());
    }

    public function test_arabic_page_keeps_western_odds_and_uses_an_arabic_month(): void
    {
        $fixture = $this->fixture();
        $market = SportMarket::query()->where('code', '1X2')->first();
        SportOdd::query()->create([
            'fixture_id' => $fixture->id, 'market_id' => $market->id, 'outcome' => 'home', 'raw_odd' => '1.85', 'shown_odd' => '1.85',
        ]);

        $page = $this->get('/sport/fixtures/'.$fixture->id.'?lang=ar')->assertOk();
        $page->assertSee('1.85', false);
        $page->assertDontSee('١٫٨٥', false);
        $page->assertSee('سبتمبر', false);
    }

    private function fixture(): SportFixture
    {
        $country = SportCountry::query()->create(['name' => 'England']);
        $league = SportLeague::query()->create([
            'api_id' => 3901, 'country_id' => $country->id, 'name' => 'Premier League', 'season' => 2026, 'is_active' => true,
        ]);
        $home = SportTeam::query()->create(['api_id' => 11, 'name' => 'Home']);
        $away = SportTeam::query()->create(['api_id' => 12, 'name' => 'Away']);

        return SportFixture::query()->create([
            'api_id' => 9001,
            'league_id' => $league->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'starts_at' => now()->addDay(),
            'status' => 'NS',
            'bulletin_code' => 1001,
        ]);
    }
}
