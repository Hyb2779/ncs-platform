<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\HierarchyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Faz 4: Kullanıcılar (sadece oyuncular, tüm ağaç) / Bayiler (süperadmin + bayi) sekmeleri. */
class UsersTabsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'http://panel.test/panel/users';

    public function test_members_tab_lists_all_players_in_tree_only(): void
    {
        [$owner] = $this->world();

        $this->actingAs($owner)->get(self::URL)->assertOk()
            ->assertSee(__('panel.users_tab_members'))->assertSee(__('panel.users_tab_dealers'))
            ->assertSee('oyuncu_a1')->assertSee('oyuncu_a2')->assertSee('oyuncu_b1')
            ->assertDontSee('sa_one') // süperadmin listede yok
            ->assertSee(__('panel.users_col_dealer'))->assertSeeInOrder(['oyuncu_a1', 'bayi_a']); // bayi adı oyuncunun satırında
    }

    public function test_dealers_tab_lists_superadmins_and_dealers_with_counts(): void
    {
        [$owner] = $this->world();

        $this->actingAs($owner)->get(self::URL.'?tab=dealers')->assertOk()
            ->assertSee('sa_one')->assertSee('bayi_a')->assertSee('bayi_b')
            ->assertSee(__('panel.roles.superadmin'))->assertSee(__('panel.roles.bayi'))
            ->assertSee(__('panel.users_col_turnover'))->assertSee(__('panel.users_movements'))
            ->assertDontSee('oyuncu_a1')
            ->assertSee('panel/users/create', false); // owner süperadmin açar → Bayiler sekmesinde
    }

    public function test_create_button_only_on_matching_tab(): void
    {
        [$owner, $sa, $bayiA] = $this->world();

        $this->actingAs($owner)->get(self::URL)->assertOk()->assertDontSee('panel/users/create', false);
        $this->actingAs($bayiA)->get(self::URL)->assertOk()->assertSee('panel/users/create', false);
    }

    public function test_superadmin_dealers_tab_shows_own_dealers(): void
    {
        [$owner, $sa] = $this->world();

        $this->actingAs($sa)->get(self::URL.'?tab=dealers')->assertOk()
            ->assertSee('bayi_a')->assertSee('bayi_b')->assertDontSee('oyuncu_a1');
    }

    public function test_bayi_has_no_tabs_and_sees_only_own_players(): void
    {
        [$owner, $sa, $bayiA] = $this->world();

        $this->actingAs($bayiA)->get(self::URL.'?tab=dealers')->assertOk()
            ->assertDontSee(__('panel.users_tab_dealers'))
            ->assertSee('oyuncu_a1')->assertSee('oyuncu_a2')->assertDontSee('oyuncu_b1');
    }

    public function test_parent_filter_narrows_players_to_one_dealer(): void
    {
        [$owner, $sa, $bayiA] = $this->world();

        $this->actingAs($owner)->get(self::URL.'?tab=members&parent='.$bayiA->id)->assertOk()
            ->assertSee('oyuncu_a1')->assertDontSee('oyuncu_b1');
    }

    /** @return array{0: User, 1: User, 2: User, 3: User} */
    private function world(): array
    {
        $owner = User::query()->create([
            'username' => 'owner', 'password' => 'password', 'role' => UserRole::Owner, 'parent_id' => null,
            'path' => '/', 'depth' => 0, 'superadmin_id' => null, 'language' => Language::Tr, 'currency' => Currency::Try,
            'timezone' => 'Europe/Istanbul', 'commission_rate' => 0, 'status' => UserStatus::Active,
        ]);
        $owner->path = '/'.$owner->id.'/';
        $owner->save();

        $h = app(HierarchyService::class);
        $base = ['password' => 'password', 'commission_rate' => 0, 'user_limit' => null, 'note' => null, 'language' => 'tr', 'currency' => 'TRY'];
        $sa = $h->create($owner->refresh(), ['username' => 'sa_one'] + $base);
        $bayiA = $h->create($sa, ['username' => 'bayi_a'] + $base);
        $bayiB = $h->create($sa, ['username' => 'bayi_b'] + $base);
        $h->create($bayiA, ['username' => 'oyuncu_a1'] + $base);
        $h->create($bayiA, ['username' => 'oyuncu_a2'] + $base);
        $h->create($bayiB, ['username' => 'oyuncu_b1'] + $base);

        return [$owner->refresh(), $sa->refresh(), $bayiA->refresh(), $bayiB->refresh()];
    }
}
