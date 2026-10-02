<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\GameBlock;
use App\Models\User;
use App\Services\Casino\GameAvailability;
use App\Services\HierarchyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Alt owner (ör. Volkan): owner yetkileri var ama sadece kendi ağacını görür.
 * Kök owner (üstü yok) her şeyi görür.
 */
class SubOwnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_sub_owner_sees_only_own_tree_root_sees_all(): void
    {
        [$root, $sub, $rootSa, $subSa] = $this->world();

        $this->assertTrue($root->isRootOwner());
        $this->assertFalse($sub->isRootOwner());

        $subSees = User::query()->subtreeOf($sub)->pluck('username')->all();
        $this->assertContains('volkan_sa', $subSees);
        $this->assertNotContains('yusuf_sa', $subSees);

        $rootSees = User::query()->subtreeOf($root)->pluck('username')->all();
        $this->assertContains('volkan_sa', $rootSees);
        $this->assertContains('yusuf_sa', $rootSees);

        $this->assertFalse($rootSa->isInSubtreeOf($sub));
        $this->assertTrue($subSa->isInSubtreeOf($sub));
    }

    public function test_sub_owner_panel_pages_hide_root_tree(): void
    {
        [$root, $sub, $rootSa, $subSa] = $this->world();

        $this->actingAs($sub)->get('http://panel.test/panel')->assertOk()
            ->assertSee('volkan_sa')->assertDontSee('yusuf_sa');
        $this->actingAs($sub)->get('http://panel.test/panel/users')->assertOk()
            ->assertSee('volkan_sa')->assertDontSee('yusuf_sa');
        $this->actingAs($sub)->get('http://panel.test/panel/network/'.$rootSa->id)->assertNotFound();
        $this->actingAs($sub)->get('http://panel.test/panel/network/'.$subSa->id)->assertOk();

        $this->actingAs($root)->get('http://panel.test/panel')->assertOk()
            ->assertSee('volkan_sa')->assertSee('yusuf_sa');
        $this->actingAs($root)->get('http://panel.test/panel/network/'.$subSa->id)->assertOk();
    }

    public function test_sub_owner_game_block_applies_only_to_own_tree(): void
    {
        [$root, $sub, $rootSa, $subSa, $rootMember, $subMember] = $this->world();

        GameBlock::query()->create(['superadmin_id' => $sub->id, 'scope' => 'game', 'value' => '777', 'created_by' => $sub->id]);
        GameBlock::query()->create(['superadmin_id' => null, 'scope' => 'game', 'value' => '888', 'created_by' => $root->id]);
        app(GameAvailability::class)->flush();
        $ga = app(GameAvailability::class);

        $forSubMember = $ga->blocked(GameAvailability::scopeIdsFor($subMember))['game'];
        $this->assertContains('777', $forSubMember);
        $this->assertContains('888', $forSubMember); // kök owner engeli herkese

        $forRootMember = $ga->blocked(GameAvailability::scopeIdsFor($rootMember))['game'];
        $this->assertNotContains('777', $forRootMember); // Volkan'ın engeli Yusuf'un ağacına değmez
        $this->assertContains('888', $forRootMember);

        $this->assertNotContains('777', $ga->blocked(GameAvailability::scopeIdsFor(null))['game']); // ziyaretçi
    }

    public function test_sub_owner_toggle_writes_own_scope(): void
    {
        [$root, $sub] = $this->world();

        $this->actingAs($sub)->post('http://panel.test/panel/games/block', [
            'scope' => 'category', 'value' => ['live'], 'blocked' => 1,
        ])->assertSessionHasNoErrors();

        // Volkan'ın kapatması kendi katmanına yazılır, genel (NULL) olmaz
        $this->assertDatabaseHas('game_blocks', ['superadmin_id' => $sub->id, 'scope' => 'category', 'value' => 'live']);
        $this->assertDatabaseMissing('game_blocks', ['superadmin_id' => null, 'scope' => 'category', 'value' => 'live']);

        // Kök owner'ın kapatması genel (NULL) yazılır
        $this->actingAs($root)->post('http://panel.test/panel/games/block', [
            'scope' => 'category', 'value' => ['mini'], 'blocked' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('game_blocks', ['superadmin_id' => null, 'scope' => 'category', 'value' => 'mini']);

        // Volkan kök owner'ın engelini kaldıramaz (sadece kendi kaydını siler)
        $this->actingAs($sub)->post('http://panel.test/panel/games/block', [
            'scope' => 'category', 'value' => ['mini'], 'blocked' => 0,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('game_blocks', ['superadmin_id' => null, 'scope' => 'category', 'value' => 'mini']);
    }

    /** @return array{0: User, 1: User, 2: User, 3: User, 4: User, 5: User} */
    private function world(): array
    {
        $root = $this->rawUser('root_owner', UserRole::Owner, null);
        $sub = $this->rawUser('volkan_owner', UserRole::Owner, $root);

        $h = app(HierarchyService::class);
        $base = ['password' => 'password', 'commission_rate' => 0, 'user_limit' => null, 'note' => null, 'language' => 'tr', 'currency' => 'TRY'];

        $rootSa = $h->create($root, ['username' => 'yusuf_sa'] + $base);
        $subSa = $h->create($sub, ['username' => 'volkan_sa'] + $base);
        $rootBayi = $h->create($rootSa, ['username' => 'yusuf_bayi'] + $base);
        $subBayi = $h->create($subSa, ['username' => 'volkan_bayi'] + $base);
        $rootMember = $h->create($rootBayi, ['username' => 'yusuf_uye'] + $base);
        $subMember = $h->create($subBayi, ['username' => 'volkan_uye'] + $base);

        return [$root->refresh(), $sub->refresh(), $rootSa, $subSa, $rootMember, $subMember];
    }

    private function rawUser(string $username, UserRole $role, ?User $parent): User
    {
        $user = User::query()->create([
            'username' => $username, 'password' => 'password', 'role' => $role,
            'parent_id' => $parent?->id, 'path' => '/', 'depth' => $parent ? $parent->depth + 1 : 0,
            'superadmin_id' => null, 'language' => Language::Tr, 'currency' => Currency::Try,
            'timezone' => 'Europe/Istanbul', 'commission_rate' => 0, 'status' => UserStatus::Active,
        ]);
        $user->path = ($parent?->path ?? '/').$user->id.'/';
        $user->save();

        return $user->refresh();
    }
}
