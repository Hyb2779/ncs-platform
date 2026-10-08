<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CasinoGame;
use App\Models\CasinoProvider;
use App\Models\GameBlock;
use App\Models\HomeSlide;
use App\Models\User;
use App\Services\Casino\GameAvailability;
use App\Services\HierarchyService;
use App\Services\HomeSlides;
use App\Support\PanelMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HomeSlidesTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_carousel_uses_pinned_games_then_the_biggest_win(): void
    {
        $provider = $this->provider();
        $first = $this->game($provider, 'Sweet Bonanza 2500', 'https://cdn.test/pp/vs20swbon2500_584x438_NB.jpg');
        $this->game($provider, 'Sweet Bonanza Super Scatter', 'https://cdn.test/b.png');
        $player = $this->player('uye-slide');
        foreach (['egt', 'pg', 'hacksaw', 'bng', 'cq9', 'jili'] as $vendor) {
            $studio = CasinoProvider::query()->create([
                'code' => $vendor, 'name' => strtoupper($vendor), 'status' => 'active', 'is_live' => false,
            ]);
            $fill = $this->game($studio, 'Fill '.$vendor, 'https://cdn.test/'.$vendor.'.png');
            $fill->vendor = $vendor;
            $fill->is_popular = true;
            $fill->save();
        }

        $home = $this->get('/');
        $slides = app(HomeSlides::class)->forViewer(null);
        $names = array_column($slides, 'name');
        $this->assertGreaterThanOrEqual(6, count($slides));
        $this->assertLessThanOrEqual(8, count($slides));
        $this->assertContains('Sweet Bonanza 2500', $names);
        $this->assertContains('Sweet Bonanza Super Scatter', $names);
        $this->assertSame(['game'], array_values(array_unique(array_column($slides, 'type'))));
        $this->assertSame(1, count(array_keys($names, 'Sweet Bonanza 2500', true)));
        $this->assertSame(1, count(array_keys($names, 'Sweet Bonanza Super Scatter', true)));
        $this->assertNotContains('match', array_column($slides, 'type'));
        $this->assertNoRepeatedProvider($slides);

        $home->assertOk()
            ->assertSee('id="lobbyHero"', false)
            ->assertSee('lobby-hero-art', false)
            ->assertDontSee('data-home-carousel', false)
            ->assertDontSee('home-hero-slide', false)
            ->assertDontSee('data-slide="match"', false)
            ->assertSee('Sweet Bonanza 2500', false)
            ->assertSee('Sweet Bonanza Super Scatter', false)
            ->assertSee(__('home.play_now'), false)
            ->assertSee(__('home.badge_new'), false)
            ->assertDontSee('Günün Kazandıranı', false)
            ->assertDontSee('Dün bu oyunda', false)
            ->assertSee('fetchpriority="high"', false)
            ->assertSee('loading="lazy"', false)
            ->assertSee('lobby-hero-progress', false)
            ->assertSee('vs20swbon2500_800x600_NB.jpg', false)
            ->assertDontSee('home-slide-arrow', false)
            ->assertDontSee('vs20swbon2500_584x438_NB.jpg', false)
            ->assertDontSee('object-contain', false)
            ->assertDontSee('max-h-[80dvh]', false)
            ->assertDontSee('Dün bu oyunda', false)
            ->assertSee('Pragmatic Play', false)
            ->assertDontSee(__('home.hero_title', ['brand' => brand()->name()]), false)
            ->assertSee('data-home-rail="popular"', false)
            ->assertSee('lobby-hero-slide is-on', false)
            ->assertDontSee('Günün kombinesi', false)
            ->assertDontSee('Günün popüler maçları', false)
            ->assertDontSee('Yaklaşan maçlar', false);

        GameBlock::query()->create([
            'superadmin_id' => null, 'scope' => 'game', 'value' => (string) $first->id, 'created_by' => $player->id,
        ]);
        app(\App\Services\Casino\GameAvailability::class)->flush();

        $this->get('/')->assertOk()->assertDontSee('Sweet Bonanza 2500', false)->assertSee('Sweet Bonanza Super Scatter', false);

        $this->get('/?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee(__('home.play_now', [], 'ar'), false);
    }

    public function test_each_visit_shuffles_and_avoids_the_previous_set(): void
    {
        $provider = $this->provider();
        $this->game($provider, 'Sweet Bonanza 2500', 'https://cdn.test/a.png');
        $this->game($provider, 'Sweet Bonanza Super Scatter', 'https://cdn.test/b.png');
        foreach (['egt', 'pg', 'hacksaw', 'bng', 'cq9', 'jili', 'hab', 'amusnet', 'spribe', 'evolution'] as $vendor) {
            $studio = CasinoProvider::query()->create([
                'code' => $vendor.'-studio', 'name' => strtoupper($vendor), 'status' => 'active', 'is_live' => $vendor === 'evolution',
            ]);
            $game = $this->catalogGame($studio, 'Game '.$vendor, $vendor, $vendor === 'evolution', $vendor === 'spribe' ? 'mini' : 'slot', 'https://cdn.test/'.$vendor.'.png');
            $game->is_popular = true;
            $game->save();
        }
        $blank = $this->game($provider, 'No Art', null);
        $blank->is_popular = true;
        $blank->save();

        $lists = [];
        $previous = [];
        $previousNames = [];
        for ($i = 0; $i < 5; $i++) {
            session(['home.slider.last' => $previous]);
            $slides = app(HomeSlides::class)->forViewer(null);
            $this->assertLessThanOrEqual(8, count($slides));
            $this->assertContains('Sweet Bonanza 2500', array_column($slides, 'name'));
            $this->assertNotContains('No Art', array_column($slides, 'name'));
            $this->assertNoRepeatedProvider($slides);
            $names = array_column($slides, 'name');
            $lists[] = implode('|', $names);
            $fresh = array_values(array_filter($names, fn ($name) => ! in_array($name, ['Sweet Bonanza 2500', 'Sweet Bonanza Super Scatter'], true)));
            if ($previousNames !== []) {
                $this->assertLessThan(count($fresh), count(array_intersect($fresh, $previousNames)));
            }
            $previousNames = $fresh;
            $previous = session('home.slider.last');
        }
        $this->assertGreaterThan(1, count(array_unique($lists)));
    }

    public function test_pinned_slides_can_be_reordered_and_need_confirmation_before_delete(): void
    {
        $owner = $this->player('owner-pinned', UserRole::Owner);
        $owner->path = '/'.$owner->id.'/';
        $owner->save();

        $this->actingAs($owner)->get(route('panel.home-slides.index'))
            ->assertOk()
            ->assertSeeInOrder(['Sweet Bonanza 2500', 'Sweet Bonanza Super Scatter'])
            ->assertSee(__('panel.home_slides_pinned'), false)
            ->assertSee(__('panel.home_slides_delete_confirm'), false)
            ->assertDontSee('data-slide="match"', false);

        $first = HomeSlide::query()->where('key', 'sweet-bonanza-2500')->firstOrFail();
        $second = HomeSlide::query()->where('key', 'sweet-bonanza-super-scatter')->firstOrFail();
        $this->actingAs($owner)->post(route('panel.home-slides.move', $second), ['direction' => 'up'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertTrue($second->refresh()->sort_order < $first->refresh()->sort_order);

        $this->actingAs($owner)->delete(route('panel.home-slides.destroy', $first))->assertRedirect();
        $this->assertDatabaseMissing('home_slides', ['key' => 'sweet-bonanza-2500']);
        $this->actingAs($owner)->get(route('panel.home-slides.index'))->assertOk();
        $this->assertDatabaseMissing('home_slides', ['key' => 'sweet-bonanza-2500']);
        $this->assertNull(HomeSlide::query()->where('key', HomeSlide::TOP_WIN)->first());
    }

    public function test_slide_screen_follows_the_owner_language(): void
    {
        foreach (['en' => Language::En, 'de' => Language::De, 'ar' => Language::Ar] as $code => $language) {
            $owner = $this->player('owner-lang-'.$code, UserRole::Owner);
            $owner->language = $language;
            $owner->path = '/'.$owner->id.'/';
            $owner->save();

            $page = $this->actingAs($owner)->get(route('panel.home-slides.index'));
            $page->assertOk()
                ->assertSee(__('panel.home_slides_title', [], $code), false)
                ->assertSee(__('panel.home_slides_search', [], $code), false)
                ->assertSee('dir="'.($code === 'ar' ? 'rtl' : 'ltr').'"', false);
        }
    }

    public function test_owner_manages_slides_and_other_roles_cannot(): void
    {
        Storage::fake('public');
        $provider = $this->provider();
        $sweet = $this->game($provider, 'Sweet Bonanza 2500', 'https://cdn.test/pp/vs20swbon2500_584x438_NB.jpg');
        $extra = $this->game($provider, 'Starlight Princess', 'https://cdn.test/pp/star_584x438_NB.jpg');
        $owner = $this->player('owner-manage', UserRole::Owner);
        $owner->path = '/'.$owner->id.'/';
        $owner->save();

        $this->actingAs($owner)->get(route('panel.home-slides.index'))
            ->assertOk()
            ->assertSee(__('panel.home_slides_pinned'), false)
            ->assertSee(__('panel.home_slides_excluded'), false)
            ->assertDontSee(__('home.hero_title', ['brand' => brand()->name()]), false);

        $slot = HomeSlide::query()->where('key', 'sweet-bonanza-2500')->firstOrFail();
        $this->actingAs($owner)->post(route('panel.home-slides.store'), [
            'intent' => 'pin', 'slot' => $slot->key, 'game_id' => $extra->id,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($extra->id, $slot->refresh()->game_id);

        $this->actingAs($owner)->post(route('panel.home-slides.store'), [
            'intent' => 'exclude', 'game_id' => $extra->id,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotContains('Starlight Princess', array_column(app(HomeSlides::class)->forViewer(null), 'name'));

        $this->actingAs($owner)->post(route('panel.home-slides.image', $slot), [
            'image' => UploadedFile::fake()->image('banner.jpg'),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $slot->refresh();
        $this->assertNotNull($slot->image_path);
        $this->get(route('site.home_slide.image', $slot))->assertOk();
        auth()->logout();
        $slot->game_id = $sweet->id;
        $slot->save();
        app(HomeSlides::class)->forget();
        $this->get('/')
            ->assertSee(route('site.home_slide.image', $slot), false)
            ->assertDontSee('star_1200x800_NB.jpg', false);

        $sa = app(HierarchyService::class)->create($owner->refresh(), $this->locale('sa-no-slides'));
        $this->actingAs($sa)->get(route('panel.home-slides.index'))->assertNotFound();
    }

    public function test_slides_hide_a_category_closed_for_the_players_dealer(): void
    {
        $this->game($this->provider(), 'Sweet Bonanza 2500', 'https://cdn.test/pp/vs20swbon2500_584x438_NB.jpg');
        $live = CasinoProvider::query()->create([
            'code' => 'romaspin', 'name' => 'RomaSpin', 'status' => 'active', 'is_live' => true,
        ]);
        $table = $this->catalogGame($live, 'Auto-Roulette VIP', 'casino-evolution', true, 'live', 'https://cdn.test/roulette.png');
        $table->is_popular = true;
        $table->save();
        $mini = $this->catalogGame($live, 'Aviator', 'mini-aviator', false, 'mini', 'https://cdn.test/aviator.png');
        $mini->is_popular = true;
        $mini->save();
        $inactive = $this->catalogGame($live, 'Dream Catcher', 'casino-evolution', true, 'live', 'https://cdn.test/dream.png');
        $inactive->is_active = false;
        $inactive->save();
        $blank = $this->catalogGame($live, 'Mines', 'mini-spribe', false, 'mini', null);

        $hierarchy = app(HierarchyService::class);
        $owner = $this->player('owner-slide-block', UserRole::Owner);
        $owner->path = '/'.$owner->id.'/';
        $owner->save();
        $sa = $hierarchy->create($owner->refresh(), $this->locale('sa-slide-block'));
        $bayi = $hierarchy->create($sa, $this->locale('bayi-slide-block'));
        $member = $hierarchy->create($bayi, $this->locale('uye-slide-block'));
        $other = $hierarchy->create(
            $hierarchy->create($hierarchy->create($owner->refresh(), $this->locale('sa-slide-open')), $this->locale('bayi-slide-open')),
            $this->locale('uye-slide-open'),
        );

        GameBlock::query()->create([
            'superadmin_id' => $bayi->id, 'scope' => 'category', 'value' => 'live', 'created_by' => $bayi->id,
        ]);
        app(GameAvailability::class)->flush();

        $hidden = app(HomeSlides::class)->forViewer($member);
        $this->assertNotContains('Auto-Roulette VIP', array_column($hidden, 'name'));
        $this->assertNotContains('Dream Catcher', array_column($hidden, 'name'));
        $this->assertNotContains('Mines', array_column($hidden, 'name'));
        $this->assertContains('Aviator', array_column($hidden, 'name'));
        $this->assertNotContains('RomaSpin', array_column($hidden, 'provider'));

        $open = collect(app(HomeSlides::class)->forViewer($other));
        $this->assertContains('Sweet Bonanza 2500', $open->pluck('name')->all());
        $this->assertContains('Auto-Roulette VIP', $open->pluck('name')->all());
        $this->assertContains('Aviator', $open->pluck('name')->all());
        $this->assertSame('Evolution', $open->firstWhere('name', 'Auto-Roulette VIP')['provider']);
        $this->assertSame('home.sit_down', $open->firstWhere('name', 'Auto-Roulette VIP')['cta']);
        $this->assertSame('Aviator', $open->firstWhere('name', 'Aviator')['provider']);
        $this->assertSame('home.play_now', $open->firstWhere('name', 'Aviator')['cta']);

        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertDontSee('Auto-Roulette VIP', false)
            ->assertSee('Aviator', false)
            ->assertDontSee(__('home.sit_down'), false);

        $this->actingAs($other)->get('/')
            ->assertOk()
            ->assertSee('Auto-Roulette VIP', false)
            ->assertSee('Evolution', false)
            ->assertSee(__('home.sit_down'), false)
            ->assertDontSee('RomaSpin', false);
    }

    private function catalogGame(CasinoProvider $provider, string $name, string $vendor, bool $live, string $category, ?string $image): CasinoGame
    {
        return CasinoGame::query()->create([
            'provider_id' => $provider->id,
            'external_id' => $vendor.':'.$name,
            'name' => $name,
            'category' => $category,
            'vendor' => $vendor,
            'image_url' => $image,
            'is_live' => $live,
            'is_active' => true,
            'sort_order' => 0,
            'is_popular' => false,
        ]);
    }

    private function provider(): CasinoProvider
    {
        return CasinoProvider::query()->create([
            'code' => 'goldpalace', 'name' => 'GoldPalace', 'status' => 'active', 'is_live' => false,
        ]);
    }

    private function game(CasinoProvider $provider, string $name, ?string $image): CasinoGame
    {
        return CasinoGame::query()->create([
            'provider_id' => $provider->id,
            'external_id' => 'gp:'.$name,
            'name' => $name,
            'category' => 'Slots',
            'vendor' => 'pp',
            'image_url' => $image,
            'is_live' => false,
            'is_active' => true,
            'sort_order' => 0,
            'is_popular' => false,
        ]);
    }

    private function player(string $username, UserRole $role = UserRole::Uye): User
    {
        return User::query()->create([
            'username' => $username,
            'password' => 'password',
            'role' => $role,
            'parent_id' => null,
            'path' => '/',
            'depth' => $role === UserRole::Owner ? 0 : 1,
            'superadmin_id' => null,
            'language' => Language::Tr,
            'currency' => Currency::Try,
            'timezone' => 'Europe/Istanbul',
            'status' => UserStatus::Active,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $slides
     */
    private function assertNoRepeatedProvider(array $slides): void
    {
        $keys = [];
        foreach ($slides as $slide) {
            if (in_array($slide['name'], ['Sweet Bonanza 2500', 'Sweet Bonanza Super Scatter'], true)) {
                continue;
            }
            $key = (string) ($slide['provider_key'] ?? '');
            $this->assertNotSame('', $key);
            $this->assertArrayNotHasKey($key, $keys);
            $keys[$key] = true;
        }
    }

    private function locale(string $username): array
    {
        return [
            'username' => $username, 'password' => 'password', 'commission_rate' => 0, 'user_limit' => null,
            'note' => null, 'language' => 'tr', 'currency' => 'TRY', 'timezone' => 'Europe/Istanbul',
        ];
    }
}
