<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\HierarchyException;
use App\Services\HierarchyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HierarchyTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_can_only_create_the_next_role(): void
    {
        $owner = $this->owner();
        $hierarchy = app(HierarchyService::class);

        $superadmin = $hierarchy->create($owner, $this->payload('sa', [
            'language' => 'tr',
            'currency' => 'TRY',
            'timezone' => 'Europe/Istanbul',
        ]));
        $this->assertSame(UserRole::Superadmin, $superadmin->role);

        $bayi = $hierarchy->create($superadmin, $this->payload('bayi'));
        $this->assertSame(UserRole::Bayi, $bayi->role);

        $member = $hierarchy->create($bayi, $this->payload('uye'));
        $this->assertSame(UserRole::Uye, $member->role);

        $this->expectException(HierarchyException::class);
        $hierarchy->create($member, $this->payload('nope'));
    }

    public function test_children_inherit_language_currency_and_timezone(): void
    {
        $owner = $this->owner();
        $hierarchy = app(HierarchyService::class);
        $superadmin = $hierarchy->create($owner, $this->payload('sa-de', [
            'language' => 'de',
            'currency' => 'EUR',
            'timezone' => 'Europe/Berlin',
        ]));
        $bayi = $hierarchy->create($superadmin, $this->payload('bayi-de'));
        $member = $hierarchy->create($bayi, $this->payload('uye-de'));

        $this->assertSame(Language::De, $member->language);
        $this->assertSame(Currency::Eur, $member->currency);
        $this->assertSame('Europe/Berlin', $member->timezone);
        $this->assertSame('/'.$owner->id.'/'.$superadmin->id.'/'.$bayi->id.'/'.$member->id.'/', $member->path);
        $this->assertSame(3, $member->depth);
        $this->assertSame($superadmin->id, $member->superadmin_id);
    }

    public function test_agent_cannot_see_another_agents_member(): void
    {
        [$bayiA, $memberB] = $this->twoBranches();

        $this->actingAs($bayiA)
            ->get('/panel/users/'.$memberB->id.'/edit')
            ->assertNotFound();

        $this->actingAs($bayiA)
            ->get('/panel/users')
            ->assertOk()
            ->assertDontSee($memberB->username);
    }

    public function test_user_limit_blocks_further_children(): void
    {
        $owner = $this->owner();
        $hierarchy = app(HierarchyService::class);
        $superadmin = $hierarchy->create($owner, $this->payload('sa-limit', [
            'language' => 'en',
            'currency' => 'USD',
            'timezone' => 'UTC',
            'user_limit' => 1,
        ]));

        $hierarchy->create($superadmin, $this->payload('only-bayi'));

        $this->expectException(HierarchyException::class);
        $hierarchy->create($superadmin, $this->payload('second-bayi'));
    }

    public function test_passive_ancestor_blocks_login(): void
    {
        $owner = $this->owner();
        $hierarchy = app(HierarchyService::class);
        $superadmin = $hierarchy->create($owner, $this->payload('sa-block', [
            'language' => 'tr',
            'currency' => 'TRY',
            'timezone' => 'UTC',
        ]));
        $bayi = $hierarchy->create($superadmin, $this->payload('bayi-block'));
        $member = $hierarchy->create($bayi, $this->payload('uye-block', ['password' => 'password']));
        $bayi->update(['status' => UserStatus::Passive]);

        $this->post('/login', [
            'username' => $member->username,
            'password' => 'password',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_login_redirects_by_role_and_sets_locale(): void
    {
        $owner = $this->owner();
        $hierarchy = app(HierarchyService::class);
        $superadmin = $hierarchy->create($owner, $this->payload('sa-login', [
            'language' => 'de',
            'currency' => 'EUR',
            'timezone' => 'Europe/Berlin',
            'password' => 'password',
        ]));
        $bayi = $hierarchy->create($superadmin, $this->payload('bayi-login'));
        $member = $hierarchy->create($bayi, $this->payload('uye-login', ['password' => 'password']));

        $this->post('/login', [
            'username' => $member->username,
            'password' => 'password',
        ])->assertRedirect('/');

        $this->get('/')->assertOk()->assertSee('Willkommen', false);
        $this->get('/?lang=ar')->assertSee('Willkommen', false);

        $this->post('/logout');

        $this->post('/login', [
            'username' => $superadmin->username,
            'password' => 'password',
        ])->assertRedirect('/panel');

        $this->get('/panel')->assertOk()->assertSee('Übersicht', false);

        $this->assertNotNull($superadmin->fresh()->last_login_at);
        $this->assertTrue(ActivityLog::query()->where('action', 'auth.login')->where('actor_id', $superadmin->id)->exists());
    }

    public function test_descendants_cannot_see_or_open_ancestors(): void
    {
        $owner = $this->owner('root-alpha');
        $hierarchy = app(HierarchyService::class);
        $superadmin = $hierarchy->create($owner, $this->payload('sa-alpha', [
            'language' => 'tr',
            'currency' => 'TRY',
            'timezone' => 'UTC',
        ]));
        $bayi = $hierarchy->create($superadmin, $this->payload('bayi-alpha'));

        $this->actingAs($bayi)
            ->get('/panel/users')
            ->assertOk()
            ->assertSee('bayi-alpha', false)
            ->assertDontSee('root-alpha', false)
            ->assertDontSee('sa-alpha', false);

        $this->actingAs($bayi)->get('/panel/users?parent='.$owner->id)->assertNotFound();
        $this->actingAs($bayi)->get('/panel/users?parent='.$superadmin->id)->assertNotFound();

        $this->actingAs($superadmin)
            ->get('/panel/users')
            ->assertOk()
            ->assertSee('sa-alpha', false)
            ->assertDontSee('root-alpha', false);

        $this->actingAs($superadmin)->get('/panel/users?parent='.$owner->id)->assertNotFound();
    }

    private function owner(string $username = 'owner'): User
    {
        $owner = User::query()->create([
            'username' => $username,
            'password' => 'password',
            'role' => UserRole::Owner,
            'parent_id' => null,
            'path' => '/',
            'depth' => 0,
            'superadmin_id' => null,
            'language' => Language::Tr,
            'currency' => Currency::Try,
            'timezone' => 'UTC',
            'commission_rate' => 0,
            'status' => UserStatus::Active,
        ]);
        $owner->path = '/'.$owner->id.'/';
        $owner->save();

        return $owner->refresh();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(string $username, array $extra = []): array
    {
        return array_merge([
            'username' => $username,
            'password' => 'password',
            'commission_rate' => 0,
            'user_limit' => null,
            'note' => null,
        ], $extra);
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function twoBranches(): array
    {
        $owner = $this->owner();
        $hierarchy = app(HierarchyService::class);
        $saA = $hierarchy->create($owner, $this->payload('sa-a', [
            'language' => 'tr', 'currency' => 'TRY', 'timezone' => 'UTC',
        ]));
        $saB = $hierarchy->create($owner, $this->payload('sa-b', [
            'language' => 'en', 'currency' => 'USD', 'timezone' => 'UTC',
        ]));
        $bayiA = $hierarchy->create($saA, $this->payload('bayi-a'));
        $bayiB = $hierarchy->create($saB, $this->payload('bayi-b'));
        $hierarchy->create($bayiA, $this->payload('uye-a'));
        $memberB = $hierarchy->create($bayiB, $this->payload('uye-b'));

        return [$bayiA, $memberB];
    }
}
