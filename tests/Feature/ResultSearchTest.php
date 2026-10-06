<?php

namespace Tests\Feature;

use App\Models\SportCountry;
use App\Models\SportFixture;
use App\Models\SportLeague;
use App\Models\SportTeam;
use App\Models\SportTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResultSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_and_league_filters_search_the_whole_window_and_stay_in_the_url(): void
    {
        $turkey = SportCountry::query()->create(['name' => 'Türkiye']);
        $england = SportCountry::query()->create(['name' => 'England']);
        $super = SportLeague::query()->create([
            'api_id' => 1, 'country_id' => $turkey->id, 'name' => 'Süper Lig', 'season' => 2026, 'is_active' => true,
        ]);
        $premier = SportLeague::query()->create([
            'api_id' => 2, 'country_id' => $england->id, 'name' => 'Premier League', 'season' => 2026, 'is_active' => true,
        ]);
        $this->finished($super, 'Fenerbahçe', 'Galatasaray', now()->subDay());
        $this->finished($premier, 'Beşiktaş', 'Trabzonspor', now()->subDay());

        $this->get('/sport/results?takim=fenerbahce')
            ->assertOk()
            ->assertSee('Fenerbahçe', false)
            ->assertDontSee('Beşiktaş', false)
            ->assertSee('name="takim"', false);

        $this->get('/sport/results?takim=GALATASARAY')
            ->assertOk()
            ->assertSee('Galatasaray', false)
            ->assertDontSee('Trabzonspor', false);

        $this->get('/sport/results?takim=f')
            ->assertOk()
            ->assertSee('Fenerbahçe', false)
            ->assertSee('Beşiktaş', false);

        $this->get('/sport/results?lig='.$super->id)
            ->assertOk()
            ->assertSee('Süper Lig', false)
            ->assertSee('Fenerbahçe', false)
            ->assertDontSee('Trabzonspor', false);

        $this->get('/sport/results?takim=besiktas&lig='.$super->id)
            ->assertOk()
            ->assertSee(__('sport.results_none'), false)
            ->assertDontSee('Beşiktaş', false);

        $this->get('/sport/results?takim=besiktas&lig='.$premier->id)
            ->assertOk()
            ->assertSee('Beşiktaş', false)
            ->assertSee('value="besiktas"', false)
            ->assertSee('value="'.$premier->id.'"', false);

        $day = now()->timezone(display_timezone())->subDays(2)->toDateString();
        $this->get('/sport/results?tarih='.$day)
            ->assertOk()
            ->assertSee(__('sport.results_none'), false);

        $this->get('/sport/results?lang=ar&takim=fenerbahce')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee(__('sport.results_team', [], 'ar'), false)
            ->assertSee(__('sport.results_clear', [], 'ar'), false);
    }

    public function test_translated_team_names_match_the_folded_query(): void
    {
        $country = SportCountry::query()->create(['name' => 'Türkiye']);
        $league = SportLeague::query()->create([
            'api_id' => 3, 'country_id' => $country->id, 'name' => '1. Lig', 'season' => 2026, 'is_active' => true,
        ]);
        $fixture = $this->finished($league, 'Home Alias', 'Away FC', now()->subHours(5));
        SportTranslation::query()->create([
            'entity_type' => 'team',
            'entity_id' => $fixture->home_team_id,
            'locale' => 'tr',
            'name' => 'Çaykur Rizespor',
            'source' => 'manual',
        ]);

        $this->get('/sport/results?lang=tr&takim=caykur')
            ->assertOk()
            ->assertSee('Çaykur Rizespor', false);
    }

    public function test_filter_labels_follow_the_locale(): void
    {
        foreach (['en', 'de', 'ar'] as $locale) {
            $page = $this->get('/sport/results?lang='.$locale);
            $page->assertOk()
                ->assertSee(__('sport.results_filter', [], $locale), false)
                ->assertSee(__('sport.results_team', [], $locale), false)
                ->assertSee(__('sport.results_all_leagues', [], $locale), false)
                ->assertSee(__('sport.results_clear', [], $locale), false);
        }

        $this->get('/sport/results?lang=ar')->assertSee('dir="rtl"', false);
    }

    private function finished(SportLeague $league, string $home, string $away, $starts): SportFixture
    {
        $homeTeam = SportTeam::query()->create(['api_id' => random_int(1000, 999999), 'name' => $home]);
        $awayTeam = SportTeam::query()->create(['api_id' => random_int(1000, 999999), 'name' => $away]);

        return SportFixture::query()->create([
            'api_id' => random_int(1000, 999999),
            'league_id' => $league->id,
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
            'starts_at' => $starts,
            'status' => 'FT',
            'score_home' => '1',
            'score_away' => '0',
            'bulletin_code' => random_int(1000, 999999),
        ]);
    }
}
