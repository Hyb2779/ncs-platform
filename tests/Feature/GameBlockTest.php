<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ActivityLog;
use App\Models\CasinoGame;
use App\Models\CasinoProvider;
use App\Models\GameBlock;
use App\Models\User;
use App\Services\Casino\GameAvailability;
use App\Services\HierarchyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameBlockTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $superadmin;
    private User $bayi;
    private User $member;
    private User $otherMember;
    private CasinoProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $h = app(HierarchyService::class);
        $this->owner = $this->makeOwner();
        $this->superadmin = $h->create($this->owner, $this->locale('sa-a'));
        $this->bayi = $h->create($this->superadmin, $this->locale('bayi-a'));
        $this->member = $h->create($this->bayi, $this->locale('uye-a'));
        $otherSa = $h->create($this->owner, $this->locale('sa-b'));
        $otherBayi = $h->create($otherSa, $this->locale('bayi-b'));
        $this->otherMember = $h->create($otherBayi, $this->locale('uye-b'));

        $this->provider = CasinoProvider::query()->create([
            'code' => 'goldpalace', 'name' => 'GoldPalace', 'status' => 'active', 'is_live' => false,
        ]);
    }

    public function test_tree_is_built_with_expected_roles(): void
    {
        $this->assertSame(UserRole::Superadmin, $this->superadmin->role);
        $this->assertSame(UserRole::Bayi, $this->bayi->role);
        $this->assertSame(UserRole::Uye, $this->member->role);
        $this->assertSame((int) $this->superadmin->id, GameAvailability::superadminIdFor($this->member));
    }

    public function test_owner_game_block_hides_everywhere_and_launch_404(): void
    {
        $game = $this->game('Sweet Bonanza');
        $this->game('Gates of Olympus');

        $this->block($this->owner, 'game', [(string) $game->id]);

        foreach ([null, $this->member, $this->otherMember] as $viewer) {
            $this->assertSame(['Gates of Olympus'], $this->visible($viewer));
        }
        $this->actingAs($this->member)->get('/play/'.$game->id)->assertNotFound();
    }

    public function test_superadmin_block_only_affects_own_tree(): void
    {
        $this->game('Pragmatic Game', ['vendor' => 'pp']);
        $this->game('Egt Game', ['vendor' => 'egt']);

        $this->block($this->superadmin, 'vendor', ['pp']);

        $this->assertSame(['Egt Game'], $this->visible($this->member));
        $this->assertSame(['Egt Game', 'Pragmatic Game'], $this->visible($this->otherMember));
        $this->assertSame(['Egt Game', 'Pragmatic Game'], $this->visible(null));
    }

    public function test_superadmin_cannot_reopen_owner_block(): void
    {
        $game = $this->game('Book of Ra');
        $this->block($this->owner, 'game', [(string) $game->id]);
        $this->block($this->superadmin, 'game', [(string) $game->id], false);

        $this->assertSame(1, GameBlock::query()->count());
        $this->assertSame([], $this->visible($this->member));
    }

    public function test_category_blocks_follow_lobby_rules(): void
    {
        $this->game('Slot One');
        $this->game('Live Table', ['is_live' => true, 'category' => 'live']);
        $this->game('Aviator', ['category' => 'mini']);

        $this->block($this->owner, 'category', ['live']);
        $this->assertSame(['Aviator', 'Slot One'], $this->visible($this->member));

        $this->block($this->owner, 'category', ['slot']);
        $this->assertSame(['Aviator'], $this->visible($this->member));
    }

    public function test_provider_block(): void
    {
        $other = CasinoProvider::query()->create(['code' => 'romaspin', 'name' => 'RomaSpin', 'status' => 'active', 'is_live' => false]);
        $this->game('Gold Game');
        $this->game('Roma Game', ['provider_id' => $other->id]);

        $this->block($this->owner, 'provider', ['romaspin']);

        $this->assertSame(['Gold Game'], $this->visible($this->member));
    }

    public function test_sync_does_not_reopen_blocked_game(): void
    {
        $game = $this->game('Big Bass');
        $this->block($this->owner, 'game', [(string) $game->id]);

        $game->forceFill(['is_active' => true])->save(); // gece senkronu gibi

        $this->assertSame([], $this->visible($this->member));
        $this->assertFalse(app(GameAvailability::class)->isPlayable($game->fresh(), $this->member));
    }

    public function test_bayi_cannot_use_game_management(): void
    {
        $this->actingAs($this->bayi)->get('/panel/games')->assertNotFound();
        $this->actingAs($this->bayi)->post('/panel/games/block', ['scope' => 'category', 'value' => ['slot'], 'blocked' => 1])->assertNotFound();
        $this->assertSame(0, GameBlock::query()->count());
    }

    public function test_owner_and_superadmin_see_the_page(): void
    {
        $this->game('Sugar Rush');

        $this->actingAs($this->owner)->get('/panel/games')->assertOk()->assertSee('Sugar Rush');
        $this->actingAs($this->superadmin)->get('/panel/games')->assertOk()->assertSee('Sugar Rush');
    }

    public function test_unknown_value_is_rejected(): void
    {
        $this->game('Any Game', ['vendor' => 'pp']);

        $this->actingAs($this->owner)->post('/panel/games/block', ['scope' => 'vendor', 'value' => ['nope'], 'blocked' => 1])->assertStatus(422);
        $this->assertSame(0, GameBlock::query()->count());
    }

    public function test_changes_are_logged(): void
    {
        $game = $this->game('Wolf Gold');
        $this->block($this->owner, 'game', [(string) $game->id]);
        $this->block($this->owner, 'game', [(string) $game->id], false);

        $this->assertSame(1, ActivityLog::query()->where('action', 'casino.games_blocked')->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'casino.games_unblocked')->count());
        $this->assertSame(0, GameBlock::query()->count());
    }

    private function block(User $actor, string $scope, array $values, bool $blocked = true): void
    {
        $this->actingAs($actor)
            ->post('/panel/games/block', ['scope' => $scope, 'value' => $values, 'blocked' => $blocked ? 1 : 0])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    private function visible(?User $viewer): array
    {
        $query = CasinoGame::query()->where('is_active', true);

        return app(GameAvailability::class)->apply($query, $viewer)->orderBy('name')->pluck('name')->all();
    }

    private function game(string $name, array $attrs = []): CasinoGame
    {
        return CasinoGame::query()->create(array_merge([
            'provider_id' => $this->provider->id,
            'external_id' => 'test:'.$name,
            'name' => $name,
            'category' => 'Slots',
            'is_live' => false,
            'is_active' => true,
            'sort_order' => 0,
            'is_popular' => true,
        ], $attrs));
    }

    private function makeOwner(): User
    {
        $owner = User::query()->create([
            'username' => 'owner-gb',
            'password' => 'password',
            'role' => UserRole::Owner,
            'parent_id' => null,
            'path' => '/',
            'depth' => 0,
            'superadmin_id' => null,
            'language' => Language::Tr,
            'currency' => Currency::Try,
            'timezone' => 'Europe/Istanbul',
            'commission_rate' => 0,
            'status' => UserStatus::Active,
        ]);
        $owner->path = '/'.$owner->id.'/';
        $owner->save();

        return $owner->refresh();
    }

    private function locale(string $username): array
    {
        return [
            'username' => $username, 'password' => 'password', 'commission_rate' => 0, 'user_limit' => null,
            'note' => null, 'language' => 'tr', 'currency' => 'TRY', 'timezone' => 'Europe/Istanbul',
        ];
    }
}
