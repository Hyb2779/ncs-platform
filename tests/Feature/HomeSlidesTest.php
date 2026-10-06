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
use App\Services\HierarchyService;
use App\Services\HomeSlides;
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
        $first = $this->game($provider, 'Sweet Bonanza 2500', 'https://cdn.test/a.png');
        $second = $this->game($provider, 'Sweet Bonanza Super Scatter', 'https://cdn.test/b.png');
        $winner = $this->game($provider, 'Gates of Olympus', 'https://cdn.test/c.png');
        $quiet = $this->game($provider, 'Wolf Gold', 'https://cdn.test/d.png');
        $player = $this->player('uye-slide');
        $this->win($player, $quiet, '5.00');
        $this->win($player, $winner, '80.00');
        $this->win($player, $winner, '20.00');

        $home = $this->get('/');
        $home->assertOk()
            ->assertSee('data-home-carousel', false)
            ->assertSeeInOrder(['Sweet Bonanza 2500', 'Sweet Bonanza Super Scatter', 'Gates of Olympus'])
            ->assertSee(__('home.play_now'), false)
            ->assertSee('fetchpriority="high"', false)
            ->assertSee('loading="lazy"', false)
            ->assertSee('PRAGMATIC PLAY', false)
            ->assertDontSee(__('home.hero_title', ['brand' => brand()->name()]), false)
            ->assertSee('data-home-rail="popular"', false);

        GameBlock::query()->create([
            'superadmin_id' => null, 'scope' => 'game', 'value' => (string) $first->id, 'created_by' => $player->id,
        ]);
        app(\App\Services\Casino\GameAvailability::class)->flush();

        $this->get('/')->assertOk()->assertDontSee('Sweet Bonanza 2500', false)->assertSee('Sweet Bonanza Super Scatter', false);

        $this->get('/?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee(__('home.play_now', [], 'ar'), false);
    }

    public function test_match_slide_follows_sport_availability_and_the_owner_switch(): void
    {
        config(['services.ncs_bridge.secret' => 'test-secret']);
        app()->setLocale('tr');
        $featured = ['league' => 'Premier Lig', 'day' => 'Bugün', 'time' => '21:00', 'home' => 'Arsenal', 'away' => 'Chelsea', 'o1' => null, 'ox' => null, 'o2' => null];
        $slides = app(HomeSlides::class);

        $names = array_column($slides->forViewer(null, $featured), 'type');
        $this->assertContains('match', $names);

        $owner = $this->player('owner-slide', UserRole::Owner);
        $owner->path = '/'.$owner->id.'/';
        $owner->save();
        $sa = app(HierarchyService::class)->create($owner->refresh(), $this->locale('sa-slide'));
        $member = app(HierarchyService::class)->create(
            app(HierarchyService::class)->create($sa, $this->locale('bayi-slide')),
            $this->locale('uye-slide-2'),
        );
        GameBlock::query()->create([
            'superadmin_id' => $sa->id, 'scope' => 'product', 'value' => 'wegas_sport', 'created_by' => $sa->id,
        ]);
        app(\App\Services\Casino\GameAvailability::class)->flush();

        $hidden = array_column($slides->forViewer($member->refresh(), $featured), 'type');
        $this->assertNotContains('match', $hidden);

        HomeSlide::query()->where('key', HomeSlide::MATCH)->update(['is_active' => false]);
        $slides->forget();
        $closed = array_column($slides->forViewer(null, $featured), 'type');
        $this->assertNotContains('match', $closed);
    }

    public function test_owner_manages_slides_and_other_roles_cannot(): void
    {
        Storage::fake('public');
        $provider = $this->provider();
        $extra = $this->game($provider, 'Starlight Princess', null);
        $owner = $this->player('owner-manage', UserRole::Owner);
        $owner->path = '/'.$owner->id.'/';
        $owner->save();

        $this->actingAs($owner)->get(route('panel.home-slides.index'))
            ->assertOk()
            ->assertSee(__('panel.home_slides_match'), false)
            ->assertSee(__('panel.home_slides_top_win'), false);

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

        $sa = app(HierarchyService::class)->create($owner->refresh(), $this->locale('sa-no-slides'));
        $this->actingAs($sa)->get(route('panel.home-slides.index'))->assertNotFound();
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

    private function win(User $user, CasinoGame $game, string $amount): void
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
            'created_at' => now(),
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
