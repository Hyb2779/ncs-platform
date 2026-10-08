<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CasinoGame;
use App\Models\CasinoProvider;
use App\Models\GameBlock;
use App\Models\GameRound;
use App\Models\HomeSlide;
use App\Models\User;
use App\Services\Casino\GameAvailability;
use App\Services\HierarchyService;
use App\Services\HomeSlides;
use App\Support\Money;
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
        $winner = $this->game($provider, 'Gates of Olympus', 'https://cdn.test/c.png');
        $quiet = $this->game($provider, 'Wolf Gold', 'https://cdn.test/d.png');
        $player = $this->player('uye-slide');
        $this->win($player, $quiet, '5.00');
        $this->win($player, $winner, '80.00');
        $this->win($player, $winner, '20.00');

        foreach (['Slide Fill 1', 'Slide Fill 2', 'Slide Fill 3'] as $name) {
            $this->game($provider, $name, 'https://cdn.test/'.$name.'.png');
        }

        $home = $this->get('/');
        $slides = app(HomeSlides::class)->forViewer(null);
        $names = array_column($slides, 'name');
        $this->assertGreaterThanOrEqual(6, count($slides));
        $this->assertLessThanOrEqual(8, count($slides));
        $this->assertSame('Sweet Bonanza 2500', $slides[0]['name']);
        $this->assertSame('Sweet Bonanza Super Scatter', $slides[1]['name']);
        $this->assertSame('Gates of Olympus', $slides[2]['name']);
        $this->assertSame(['game'], array_values(array_unique(array_column($slides, 'type'))));
        $this->assertSame(1, count(array_keys($names, 'Sweet Bonanza 2500', true)));
        $this->assertSame(1, count(array_keys($names, 'Sweet Bonanza Super Scatter', true)));
        $this->assertNotContains('match', array_column($slides, 'type'));

        $home->assertOk()
            ->assertSee('id="lobbyHero"', false)
            ->assertSee('lobby-hero-art', false)
            ->assertDontSee('data-home-carousel', false)
            ->assertDontSee('home-hero-slide', false)
            ->assertDontSee('data-slide="match"', false)
            ->assertSeeInOrder(['Sweet Bonanza 2500', 'Sweet Bonanza Super Scatter', 'Gates of Olympus'])
            ->assertSee(__('home.play_now'), false)
            ->assertSee(__('home.day_winner'), false)
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

    public function test_yesterday_winnings_line_uses_rounds_and_hides_without_data(): void
    {
        $this->travelTo('2026-10-08 18:00:00');
        $provider = $this->provider();
        $this->game($provider, 'Sweet Bonanza 2500', 'https://cdn.test/a.png');
        $this->game($provider, 'Sweet Bonanza Super Scatter', 'https://cdn.test/b.png');
        $winner = $this->game($provider, 'Gates of Olympus', 'https://cdn.test/c.png');
        $player = $this->player('uye-yesterday');
        $this->win($player, $winner, '1500.00', '2026-10-07 12:00:00');
        $this->win($player, $winner, '999.00', '2026-10-08 11:00:00');

        $line = __('home.yesterday_won', ['amount' => Money::format('1500.00', Currency::Try)]);

        $this->get('/')
            ->assertOk()
            ->assertSee($line, false)
            ->assertSeeInOrder(['Gates of Olympus', $line])
            ->assertDontSee('999,00', false);
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

        $top = HomeSlide::query()->where('key', HomeSlide::TOP_WIN)->firstOrFail();
        $this->actingAs($owner)->delete(route('panel.home-slides.destroy', $top))->assertNotFound();
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
        $extra = $this->game($provider, 'Starlight Princess', 'https://cdn.test/pp/star_584x438_NB.jpg');
        $owner = $this->player('owner-manage', UserRole::Owner);
        $owner->path = '/'.$owner->id.'/';
        $owner->save();

        $this->actingAs($owner)->get(route('panel.home-slides.index'))
            ->assertOk()
            ->assertSee(__('panel.home_slides_pinned'), false)
            ->assertSee(__('panel.home_slides_top_win'), false)
            ->assertDontSee(__('home.hero_title', ['brand' => brand()->name()]), false);

        $this->actingAs($owner)->post(route('panel.home-slides.store'), ['game_id' => $extra->id])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $added = HomeSlide::query()->where('game_id', $extra->id)->first();
        $this->assertNotNull($added);

        $this->actingAs($owner)->post(route('panel.home-slides.move', $added), ['direction' => 'up'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs($owner)->post(route('panel.home-slides.image', $added), [
            'image' => UploadedFile::fake()->image('banner.jpg'),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $added->refresh();
        $this->assertNotNull($added->image_path);
        $this->get(route('site.home_slide.image', $added))->assertOk();
        auth()->logout();
        $this->get('/')
            ->assertSee(route('site.home_slide.image', $added), false)
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
        $mini = $this->catalogGame($live, 'Aviator', 'mini-aviator', false, 'mini', 'https://cdn.test/aviator.png');
        $inactive = $this->catalogGame($live, 'Dream Catcher', 'casino-evolution', true, 'live', 'https://cdn.test/dream.png');
        $inactive->is_active = false;
        $inactive->save();
        $blank = $this->catalogGame($live, 'Mines', 'mini-spribe', false, 'mini', null);

        config(['home.slides' => [
            ['category' => 'live', 'game_id' => $table->id],
            ['category' => 'mini', 'game_id' => $mini->id],
            ['category' => 'live', 'game_id' => $inactive->id],
            ['category' => 'mini', 'game_id' => $blank->id],
        ]]);

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
        $this->assertSame(
            ['Sweet Bonanza 2500', 'Auto-Roulette VIP', 'Aviator'],
            $open->pluck('name')->all(),
        );
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

    private function win(User $user, CasinoGame $game, string $amount, ?string $at = null): void
    {
        GameRound::query()->create([
            'provider' => 'goldpalace',
            'provider_transaction_id' => $game->id.'-'.uniqid(),
            'round_id' => uniqid('round'),
            'user_id' => $user->id,
            'game_id' => $game->id,
            'bet' => '0.00',
            'win' => $amount,
            'balance_before' => '10.00',
            'amount' => $amount,
            'balance_after' => '20.00',
            'status' => 'win',
            'payload' => [],
            'created_at' => $at ?? now(),
        ]);
    }

    private function locale(string $username): array
    {
        return [
            'username' => $username, 'password' => 'password', 'commission_rate' => 0, 'user_limit' => null,
            'note' => null, 'language' => 'tr', 'currency' => 'TRY', 'timezone' => 'Europe/Istanbul',
        ];
    }
}
