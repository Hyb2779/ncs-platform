<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CasinoGame;
use App\Models\CasinoProvider;
use App\Models\SportCountry;
use App\Models\SportFixture;
use App\Models\SportLeague;
use App\Models\SportTeam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HomeShowcaseTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Http::swap(new \Illuminate\Http\Client\Factory());
        parent::tearDown();
    }

    public function test_category_cards_use_configured_images_and_fall_back_without_one(): void
    {
        config(['services.ncs_bridge.secret' => 'test-secret', 'home.categories.sport.file' => 'img/categories/missing-sport.jpg']);
        $gold = $this->provider('goldpalace', 'GoldPalace');
        $roma = $this->provider('romaspin', 'RomaSpin');
        $slot = $this->game($gold, 'Sweet Bonanza 2500', ['image_url' => 'https://cdn.test/sweet.png']);
        $aviator = $this->game($roma, 'Aviator', ['category' => 'mini', 'image_url' => 'https://cdn.test/aviator.png']);
        $table = $this->game($roma, 'Roulette A', ['is_live' => true, 'category' => 'live', 'image_url' => 'https://cdn.test/roulette.png']);

        $html = $this->actingAs($this->member())->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('/cache/g/'.$slot->id.'-', $html);
        $this->assertStringContainsString('/cache/g/'.$aviator->id.'-', $html);
        $this->assertStringContainsString('/cache/g/'.$table->id.'-', $html);
        $this->assertStringContainsString('is-plain', $html);
        $this->assertStringNotContainsString('img/categories/missing-sport.jpg', $html);
        $this->assertStringContainsString('data-home-rail="popular"', $html);
        $this->assertStringContainsString('h-[110px]', $html);
        $this->assertStringContainsString('md:h-[140px]', $html);
        $this->assertStringContainsString(__('home.live_matches'), $html);
        $this->assertStringNotContainsString('Canlı ve maç önü', $html);
    }

    public function test_match_block_uses_fenix_live_and_the_member_timezone(): void
    {
        config(['services.ncs_bridge.secret' => 'test-secret']);
        Carbon::setTestNow(Carbon::parse('2026-10-07 20:00:00', 'UTC'));
        $this->liveFixture('Union Minas', 'Union Comercio', '2H', 80, 4, 1, 501);
        $this->liveFixture('Skip Hoops', 'Other', '2H', 10, 20, 18, 502)->forceFill(['sport' => 'basketball'])->save();
        $this->liveFixture('Half Home', 'Half Away', 'HT', 45, 1, 0, 503);

        $this->fixture('Early Home', 'Early Away', Carbon::parse('2026-10-08 02:30:00', 'UTC'), 1001);
        $this->fixture('Late Home', 'Late Away', Carbon::parse('2026-10-08 18:00:00', 'UTC'), 1002);
        for ($i = 3; $i <= 7; $i++) {
            $this->fixture('Extra '.$i, 'Rival '.$i, Carbon::parse('2026-10-08 18:00:00', 'UTC')->addHours($i), 1000 + $i);
        }

        $member = $this->member('player-ny', 'America/New_York');
        $page = $this->actingAs($member)->get('/');
        $page->assertOk()
            ->assertSee('data-home-matches', false)
            ->assertSee('home-matches-grid', false)
            ->assertSee('data-match-card="live"', false)
            ->assertSee('data-match-card="upcoming"', false)
            ->assertDontSee('data-match-tab', false)
            ->assertDontSee('home-match-dot', false)
            ->assertSee('Union Minas', false)
            ->assertSee('Union Comercio', false)
            ->assertSee('data-side="score-home">4<', false)
            ->assertSee('data-side="score-away">1<', false)
            ->assertDontSee('4 - 1', false)
            ->assertSee("80'")
            ->assertSee(__('sport.half_time'), false)
            ->assertDontSee('Skip Hoops', false)
            ->assertSee('Early Home', false)
            ->assertSee('data-side="day">Bugün<', false)
            ->assertSee('data-side="clock">22:30<', false)
            ->assertSee('data-side="day">Yarın<', false)
            ->assertSee('data-side="clock">14:00<', false)
            ->assertDontSee('Extra 7', false)
            ->assertSee(route('site.sport.live'), false)
            ->assertSee(route('site.sport'), false)
            ->assertSee(__('home.matches_live'), false)
            ->assertSee(__('home.matches_upcoming'), false);

        $this->actingAs($member)->get('/home/matches')
            ->assertOk()
            ->assertJsonPath('live.0.home', 'Union Minas')
            ->assertJsonPath('live.0.score', '4 - 1')
            ->assertJsonPath('upcoming.0.clock', 'Bugün 22:30');
    }

    public function test_an_empty_side_hides_its_card(): void
    {
        config(['services.ncs_bridge.secret' => 'test-secret']);
        Carbon::setTestNow(Carbon::parse('2026-10-07 12:00:00', 'UTC'));
        $this->fixture('Only Home', 'Only Away', Carbon::parse('2026-10-07 18:00:00', 'UTC'), 2001);

        $upcoming = $this->actingAs($this->member())->get('/');
        $upcoming->assertOk()->assertSee('Only Home', false);
        $this->assertMatchesRegularExpression('/data-match-card="live"[^>]*\shidden/', $upcoming->getContent());
        $this->assertDoesNotMatchRegularExpression('/data-match-card="upcoming"[^>]*\shidden/', $upcoming->getContent());

    }

    public function test_an_empty_upcoming_card_hides_when_only_live_matches_exist(): void
    {
        config(['services.ncs_bridge.secret' => 'test-secret']);
        $this->liveFixture('Only Live', 'Only Rival', '2H', 12, 1, 0, 900);

        $live = $this->actingAs($this->member('player-live'))->get('/');
        $live->assertOk()->assertSee('Only Live', false);
        $this->assertMatchesRegularExpression('/data-match-card="upcoming"[^>]*\shidden/', $live->getContent());
        $this->assertDoesNotMatchRegularExpression('/data-match-card="live"[^>]*\shidden/', $live->getContent());
    }

    public function test_match_block_hides_when_fenix_and_the_bulletin_are_empty(): void
    {
        config(['services.ncs_bridge.secret' => 'test-secret']);
        $this->actingAs($this->member())->get('/')
            ->assertOk()
            ->assertDontSee('data-home-matches', false);

        auth()->logout();
        $this->get('/?lang=ar')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('home-cat', false)
            ->assertSee('data-home-cat="sport"', false)
            ->assertSee(__('site.sport', [], 'ar'), false)
            ->assertSee('href="'.route('site.sport').'"', false)
            ->assertDontSee('data-home-matches', false);
    }

    public function test_live_rows_open_the_wegas_live_entry_when_the_bridge_returns_a_root_url(): void
    {
        config([
            'services.ncs_bridge.secret' => 'test-secret',
            'services.ncs_bridge.url' => 'https://ncs.test',
            'sport.own_book_enabled' => false,
        ]);
        Http::fake([
            'https://ncs.test/callback/wegas-session' => Http::response(['success' => true, 'url' => 'https://sports.test/?t=1']),
        ]);

        $this->actingAs($this->member())->get('/wegas-spor?live=1')
            ->assertOk()
            ->assertSee('https://sports.test/canli-bahis?t=1', false);

        Http::assertSent(function ($request): bool {
            $body = json_decode($request->body(), true);

            return ($body['path'] ?? null) === 'canli-bahis';
        });
    }

    private function liveFixture(string $home, string $away, string $status, int $minute, int $homeScore, int $awayScore, int $code): SportFixture
    {
        $fixture = $this->fixture($home, $away, now()->subHour(), $code);
        $fixture->forceFill([
            'status' => $status,
            'elapsed' => $minute,
            'score_home' => (string) $homeScore,
            'score_away' => (string) $awayScore,
            'sport' => 'football',
        ])->save();

        return $fixture;
    }

    private function fixture(string $home, string $away, Carbon $starts, int $code): SportFixture
    {
        $country = SportCountry::query()->firstOrCreate(['name' => 'Testland']);
        $league = SportLeague::query()->create([
            'api_id' => $code,
            'country_id' => $country->id,
            'name' => 'League '.$code,
            'is_active' => true,
        ]);
        $homeTeam = SportTeam::query()->create(['api_id' => $code + 10, 'name' => $home]);
        $awayTeam = SportTeam::query()->create(['api_id' => $code + 20, 'name' => $away]);

        return SportFixture::query()->create([
            'api_id' => $code,
            'league_id' => $league->id,
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
            'starts_at' => $starts,
            'status' => 'NS',
            'bulletin_code' => $code,
        ]);
    }

    private function provider(string $code, string $name): CasinoProvider
    {
        return CasinoProvider::query()->create([
            'code' => $code, 'name' => $name, 'status' => 'active', 'is_live' => $code === 'romaspin',
        ]);
    }

    private function game(CasinoProvider $provider, string $name, array $attrs = []): CasinoGame
    {
        return CasinoGame::query()->create(array_merge([
            'provider_id' => $provider->id,
            'external_id' => $provider->code.':'.$name,
            'name' => $name,
            'category' => 'slot',
            'is_live' => false,
            'is_active' => true,
            'sort_order' => 0,
            'is_popular' => false,
        ], $attrs));
    }

    private function member(string $username = 'uye-home', string $timezone = 'Europe/Istanbul'): User
    {
        return User::query()->create([
            'username' => $username,
            'password' => 'password',
            'role' => UserRole::Uye,
            'parent_id' => null,
            'path' => '/',
            'depth' => 1,
            'superadmin_id' => null,
            'language' => Language::Tr,
            'currency' => Currency::Try,
            'timezone' => $timezone,
            'status' => UserStatus::Active,
        ]);
    }
}
