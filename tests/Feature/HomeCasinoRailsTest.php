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
use App\Models\User;
use App\Services\HierarchyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeCasinoRailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_popular_rail_ranks_recent_bets_then_featured_then_provider_order(): void
    {
        $slots = $this->provider('goldpalace', 'GoldPalace');
        $played = $this->game($slots, 'Played Slot', ['sort_order' => 50, 'is_popular' => false, 'image_url' => 'https://cdn.test/played.png']);
        $featured = $this->game($slots, 'Featured Slot', ['sort_order' => 20, 'is_popular' => true]);
        $this->game($slots, 'Catalog Slot', ['sort_order' => 1, 'is_popular' => false]);
        $stale = $this->game($slots, 'Stale Slot', ['sort_order' => 0, 'is_popular' => false]);
        $blocked = $this->game($slots, 'Blocked Slot', ['sort_order' => 0, 'is_popular' => false]);
        $mini = $this->game($slots, 'Mini Crash', ['category' => 'mini', 'is_popular' => false, 'sort_order' => 0]);

        $player = $this->player();
        $this->bet($player, $played, 'r1');
        $this->bet($player, $played, 'r2');
        $this->bet($player, $played, 'r2');
        $this->bet($player, $blocked, 'r3');
        $this->bet($player, $mini, 'r4');
        $this->bet($player, $stale, 'old', now()->subDays(8));

        $owner = $this->player('owner-rails', UserRole::Owner);
        GameBlock::query()->create([
            'superadmin_id' => null, 'scope' => 'game', 'value' => (string) $blocked->id, 'created_by' => $owner->id,
        ]);

        $home = $this->get('/');
        $home->assertOk()
            ->assertSeeInOrder(['Played Slot', 'Featured Slot', 'Stale Slot', 'Catalog Slot'])
            ->assertSee('loading="lazy"', false)
            ->assertSee('/slots?list=popular', false)
            ->assertSee('data-home-rail="popular"', false)
            ->assertSee(__('home.popular_games'), false)
            ->assertDontSee('Blocked Slot', false)
            ->assertDontSee('Mini Crash', false)
            ->assertDontSee(__('home.daily_games'), false);

        $this->get('/slots?list=popular')
            ->assertOk()
            ->assertSeeInOrder(['Played Slot', 'Featured Slot', 'Stale Slot', 'Catalog Slot']);
    }

    public function test_live_rail_is_romaspin_only_and_hides_when_the_category_is_blocked(): void
    {
        $roma = $this->provider('romaspin', 'RomaSpin');
        $other = $this->provider('goldpalace', 'GoldPalace');
        $this->game($other, 'Home Slot', ['is_popular' => true]);
        $this->game($roma, 'Lightning Roulette', ['is_live' => true, 'category' => 'live', 'image_url' => 'https://cdn.test/roulette.png', 'sort_order' => 2]);
        $this->game($roma, 'Closed Table', ['is_live' => true, 'category' => 'live', 'is_active' => false, 'sort_order' => 0]);
        $this->game($other, 'Palace Roulette', ['is_live' => true, 'category' => 'live', 'sort_order' => 0]);

        $this->get('/')
            ->assertOk()
            ->assertSeeInOrder(['data-home-tiles', 'data-home-rail="popular"', 'data-home-rail="live"'], false)
            ->assertSee('data-home-rail="live"', false)
            ->assertSee('Lightning Roulette', false)
            ->assertSee('/live-casino', false)
            ->assertSee(__('home.live_casino'), false)
            ->assertDontSee('Palace Roulette', false)
            ->assertDontSee('Closed Table', false);

        $this->get('/?lang=ar')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('home-rail', false)
            ->assertSee(__('home.popular_games', [], 'ar'), false)
            ->assertSee(__('home.live_casino', [], 'ar'), false);

        $h = app(HierarchyService::class);
        $owner = $this->player('owner-live', UserRole::Owner);
        $owner->path = '/'.$owner->id.'/';
        $owner->save();
        $sa = $h->create($owner->refresh(), $this->locale('sa-live'));
        $bayi = $h->create($sa, $this->locale('bayi-live'));
        $member = $h->create($bayi, $this->locale('uye-live'));
        $otherSa = $h->create($owner->refresh(), $this->locale('sa-other'));
        $otherMember = $h->create($h->create($otherSa, $this->locale('bayi-other')), $this->locale('uye-other'));

        GameBlock::query()->create([
            'superadmin_id' => $sa->id, 'scope' => 'category', 'value' => 'live', 'created_by' => $sa->id,
        ]);

        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertDontSee('data-home-rail="live"', false)
            ->assertDontSee('Lightning Roulette', false);

        $this->actingAs($otherMember)->get('/')
            ->assertOk()
            ->assertSee('data-home-rail="live"', false)
            ->assertSee('Lightning Roulette', false);
    }

    public function test_category_counts_and_headings_follow_the_locale(): void
    {
        $slots = $this->provider('goldpalace', 'GoldPalace');
        $live = $this->provider('romaspin', 'RomaSpin');
        for ($i = 1; $i <= 1005; $i++) {
            $this->game($slots, 'Count Slot '.$i);
        }
        $this->game($slots, 'Featured Count', ['is_popular' => true]);
        $this->game($live, 'Count Table', ['is_live' => true, 'category' => 'live']);

        $this->get('/?lang=en')->assertOk()
            ->assertSee('1,006', false)
            ->assertSee(__('home.popular_games', [], 'en'), false)
            ->assertDontSee('1.006', false);
        $this->get('/?lang=tr')->assertOk()->assertSee('1.006', false)->assertSee(__('home.popular_games', [], 'tr'), false);
        $this->get('/?lang=de')->assertOk()->assertSee('1.006', false)->assertSee(__('home.popular_games', [], 'de'), false);
        $this->get('/?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('1,006', false)
            ->assertSee(__('home.popular_games', [], 'ar'), false);
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

    private function player(string $username = 'uye-rails', UserRole $role = UserRole::Uye): User
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

    private function bet(User $user, CasinoGame $game, string $round, $at = null): void
    {
        GameRound::query()->create([
            'provider' => 'goldpalace',
            'provider_transaction_id' => $game->id.'-'.$round.'-'.uniqid(),
            'round_id' => $round,
            'user_id' => $user->id,
            'game_id' => $game->id,
            'bet' => '10.00',
            'win' => '0.00',
            'balance_before' => '100.00',
            'amount' => '-10.00',
            'balance_after' => '90.00',
            'status' => 'bet',
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
