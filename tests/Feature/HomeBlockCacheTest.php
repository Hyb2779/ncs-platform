<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CasinoGame;
use App\Models\CasinoProvider;
use App\Models\User;
use App\Services\Casino\CatalogCache;
use App\Services\Casino\HomeCasinoRails;
use App\Services\HierarchyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Ham liste önbelleği bayi engelinden sonra süzülür; kapatma 10 dk beklemez. */
class HomeBlockCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_game_closed_for_one_dealer_disappears_immediately_for_that_dealers_player_only(): void
    {
        $owner = User::query()->create([
            'username' => 'cache_owner', 'password' => 'password', 'role' => UserRole::Owner,
            'parent_id' => null, 'path' => '/', 'depth' => 0, 'superadmin_id' => null,
            'language' => Language::Tr, 'currency' => Currency::Try, 'timezone' => 'Europe/Istanbul',
            'commission_rate' => 0, 'status' => UserStatus::Active,
        ]);
        $owner->path = '/'.$owner->id.'/';
        $owner->save();

        $h = app(HierarchyService::class);
        $data = fn (string $name) => [
            'username' => $name, 'password' => 'password', 'commission_rate' => 0, 'user_limit' => null,
            'note' => null, 'language' => 'tr', 'currency' => 'TRY', 'timezone' => 'Europe/Istanbul',
        ];
        $sa = $h->create($owner->refresh(), $data('cache_sa'));
        $bayiA = $h->create($sa, $data('cache_bayi_a'));
        $bayiB = $h->create($sa, $data('cache_bayi_b'));
        $playerA = $h->create($bayiA, $data('cache_uye_a'));
        $playerB = $h->create($bayiB, $data('cache_uye_b'));

        $provider = CasinoProvider::query()->create([
            'code' => 'goldpalace', 'name' => 'GoldPalace', 'status' => 'active', 'is_live' => false,
        ]);
        $hidden = CasinoGame::query()->create([
            'provider_id' => $provider->id, 'external_id' => 'goldpalace:hidden', 'name' => 'Hidden Slot',
            'category' => 'slot', 'vendor' => 'pp', 'is_live' => false, 'is_active' => true,
            'is_popular' => true, 'sort_order' => 1,
        ]);
        CasinoGame::query()->create([
            'provider_id' => $provider->id, 'external_id' => 'goldpalace:open', 'name' => 'Open Slot',
            'category' => 'slot', 'vendor' => 'pp', 'is_live' => false, 'is_active' => true,
            'is_popular' => true, 'sort_order' => 2,
        ]);

        $this->actingAs($playerA)->get('/')->assertOk()->assertSee('Hidden Slot')->assertSee('2 oyun', false);
        $this->actingAs($playerB)->get('/')->assertOk()->assertSee('Hidden Slot')->assertSee('2 oyun', false);
        $this->assertContains($hidden->id, Cache::get(HomeCasinoRails::SLOTS_KEY));

        $this->actingAs($owner)->post('/panel/games/block', [
            'scope' => 'game',
            'value' => [(string) $hidden->id],
            'blocked' => 1,
            'target' => $bayiA->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull(Cache::get(HomeCasinoRails::SLOTS_KEY));
        $this->assertNull(Cache::get(CatalogCache::KEY));

        $this->actingAs($playerA)->get('/')
            ->assertOk()
            ->assertDontSee('Hidden Slot', false)
            ->assertSee('Open Slot', false)
            ->assertSee('1 oyun', false);
        $this->actingAs($playerA)->get('/slots')
            ->assertOk()
            ->assertDontSee('Hidden Slot', false)
            ->assertSee('Open Slot', false)
            ->assertSeeInOrder(['Pragmatic Play', '1'], false);

        $raw = Cache::get(HomeCasinoRails::SLOTS_KEY);
        $this->assertContains($hidden->id, $raw);

        $this->actingAs($playerB)->get('/')
            ->assertOk()
            ->assertSee('Hidden Slot', false)
            ->assertSee('Open Slot', false)
            ->assertSee('2 oyun', false);
        $this->actingAs($playerB)->get('/slots')
            ->assertOk()
            ->assertSee('Hidden Slot', false)
            ->assertSeeInOrder(['Pragmatic Play', '2'], false);
    }
}
