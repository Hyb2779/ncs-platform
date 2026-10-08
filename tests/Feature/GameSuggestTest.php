<?php

namespace Tests\Feature;

use App\Models\CasinoGame;
use App\Models\CasinoProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameSuggestTest extends TestCase
{
    use RefreshDatabase;

    public function test_suggest_matches_turkish_names_and_returns_at_most_eight_rows(): void
    {
        $provider = CasinoProvider::query()->create([
            'code' => 'goldpalace', 'name' => 'GoldPalace', 'status' => 'active', 'is_live' => false,
        ]);
        $this->game($provider, 'İstanbul Nights', 'pp');
        $this->game($provider, 'Sweet Bonanza', 'pp');
        for ($i = 1; $i <= 9; $i++) {
            $this->game($provider, 'Zed Hunt '.$i, 'pg');
        }

        $this->getJson('/games/suggest?q=i')
            ->assertOk()
            ->assertExactJson(['total' => 0, 'games' => []]);

        $istanbul = $this->getJson('/games/suggest?q=İST')
            ->assertOk()
            ->json();
        $this->assertSame(1, $istanbul['total']);
        $this->assertSame('İstanbul Nights', $istanbul['games'][0]['name']);
        $this->assertSame('Pragmatic Play', $istanbul['games'][0]['provider']);
        $this->assertArrayHasKey('image', $istanbul['games'][0]);
        $this->assertArrayHasKey('href', $istanbul['games'][0]);
        $this->assertArrayNotHasKey('category', $istanbul['games'][0]);

        $zed = $this->getJson('/games/suggest?q=zed&mode=slot')->assertOk()->json();
        $this->assertSame(9, $zed['total']);
        $this->assertCount(8, $zed['games']);

        $this->get('/slots?q=istanbul')
            ->assertOk()
            ->assertSee('İstanbul Nights', false)
            ->assertDontSee('Sweet Bonanza', false);
    }

    public function test_suggest_page_renders_the_live_list_in_four_languages(): void
    {
        foreach (['tr' => 'Oyun bulunamadı', 'en' => 'No games found', 'de' => 'Kein Spiel gefunden', 'ar' => 'لم يتم العثور على لعبة'] as $locale => $empty) {
            $this->get('/slots?lang='.$locale)
                ->assertOk()
                ->assertSee('gameSuggest', false)
                ->assertSee($empty, false)
                ->assertSee(__('site.search_all', ['count' => ':count'], $locale), false);
        }

        $this->get('/slots?lang=ar')->assertOk()->assertSee('dir="rtl"', false);
    }

    private function game(CasinoProvider $provider, string $name, string $vendor): CasinoGame
    {
        return CasinoGame::query()->create([
            'provider_id' => $provider->id,
            'external_id' => 'gp:'.$name,
            'name' => $name,
            'category' => 'Slots',
            'vendor' => $vendor,
            'image_url' => 'https://cdn.test/'.$name.'.png',
            'is_live' => false,
            'is_active' => true,
            'sort_order' => 0,
            'is_popular' => false,
        ]);
    }
}
