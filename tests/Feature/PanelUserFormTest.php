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

/**
 * Faz 3: admin/bayi formunda saat dilimi yok (hep Europe/Istanbul),
 * komisyon zorunlu değil (boş = 0), şifre en az 4 karakter.
 */
class PanelUserFormTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'http://panel.test/panel/users';

    public function test_owner_creates_superadmin_without_commission_or_timezone(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(self::URL, [
            'username' => 'sa_yeni', 'password' => 'abcd',
            'language' => 'en', 'currency' => 'USD',
        ])->assertSessionHasNoErrors();

        $sa = User::query()->where('username', 'sa_yeni')->firstOrFail();
        $this->assertSame(UserRole::Superadmin, $sa->role);
        $this->assertSame('Europe/Istanbul', $sa->timezone);
        $this->assertEquals(0, (float) $sa->commission_rate);
        $this->assertSame(Language::En, $sa->language);
    }

    public function test_superadmin_creates_bayi_without_commission_and_timezone_is_ignored(): void
    {
        $sa = app(HierarchyService::class)->create($this->owner(), [
            'username' => 'sa', 'password' => 'password', 'commission_rate' => 0,
            'user_limit' => null, 'note' => null,
            'language' => 'tr', 'currency' => 'TRY',
        ]);

        $this->actingAs($sa)->post(self::URL, [
            'username' => 'bayi_yeni', 'password' => '1234',
            'language' => 'tr', 'currency' => 'TRY',
            'timezone' => 'UTC', // eski formdan gelse bile yok sayılır
            'commission_rate' => '',
        ])->assertSessionHasNoErrors();

        $bayi = User::query()->where('username', 'bayi_yeni')->firstOrFail();
        $this->assertSame(UserRole::Bayi, $bayi->role);
        $this->assertSame('Europe/Istanbul', $bayi->timezone);
        $this->assertEquals(0, (float) $bayi->commission_rate);
    }

    public function test_password_shorter_than_four_is_rejected(): void
    {
        $this->actingAs($this->owner())->post(self::URL, [
            'username' => 'kisa_sifre', 'password' => 'abc',
            'language' => 'tr', 'currency' => 'TRY',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['username' => 'kisa_sifre']);
    }

    public function test_password_fields_allow_four_characters_in_the_browser(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->get(self::URL)->assertOk()
            ->assertSee('minlength="4"', false)
            ->assertDontSee('minlength="8"', false);

        $this->actingAs($owner)->get(self::URL.'/create')->assertOk()
            ->assertSee('name="password"', false)
            ->assertSee('minlength="4"', false)
            ->assertDontSee('minlength="8"', false);
    }

    private function owner(): User
    {
        $owner = User::query()->create([
            'username' => 'owner', 'password' => 'password', 'role' => UserRole::Owner,
            'parent_id' => null, 'path' => '/', 'depth' => 0, 'superadmin_id' => null,
            'language' => Language::Tr, 'currency' => Currency::Try, 'timezone' => 'UTC',
            'commission_rate' => 0, 'status' => UserStatus::Active,
        ]);
        $owner->path = '/'.$owner->id.'/';
        $owner->save();

        return $owner->refresh();
    }

    private function account(User $parent, string $username, string $currency = 'TRY'): User
    {
        return app(HierarchyService::class)->create($parent, [
            'username' => $username, 'password' => 'password', 'commission_rate' => 0,
            'user_limit' => null, 'note' => null, 'language' => 'tr', 'currency' => $currency,
        ]);
    }

    public function test_owner_creates_bayi_and_uye_under_chosen_parent(): void
    {
        $owner = $this->owner();
        $sa = $this->account($owner, 'sa_p1', 'USD');

        $this->actingAs($owner)->get(self::URL.'/create')->assertOk()->assertSee('name="role"', false)->assertSee('sa_p1');

        $this->actingAs($owner)->post(self::URL, [
            'role' => 'bayi', 'parent' => $sa->id, 'username' => 'bayi_p1', 'password' => '1234',
            'language' => 'de', 'currency' => 'EUR',
        ])->assertSessionHasNoErrors();
        $bayi = User::query()->where('username', 'bayi_p1')->firstOrFail();
        $this->assertSame(UserRole::Bayi, $bayi->role);
        $this->assertSame($sa->id, $bayi->parent_id);
        $this->assertSame($sa->id, $bayi->superadmin_id);

        $this->actingAs($owner)->post(self::URL, [
            'role' => 'uye', 'parent' => $bayi->id, 'username' => 'uye_p1', 'password' => '1234',
            'commission_rate' => '50', 'language' => 'tr', 'currency' => 'TRY',
        ])->assertSessionHasNoErrors();
        $uye = User::query()->where('username', 'uye_p1')->firstOrFail();
        $this->assertSame(UserRole::Uye, $uye->role);
        $this->assertSame($bayi->id, $uye->parent_id);
        $this->assertSame($sa->id, $uye->superadmin_id);
        $this->assertSame('EUR', $uye->currency->value);
        $this->assertEquals(0, (float) $uye->commission_rate);
    }

    public function test_parent_must_be_in_own_tree_one_level_up_and_role_must_be_allowed(): void
    {
        $owner = $this->owner();
        $sa1 = $this->account($owner, 'sa_q1');
        $sa2 = $this->account($owner, 'sa_q2');
        $b1 = $this->account($sa1, 'bayi_q1');
        $b2 = $this->account($sa2, 'bayi_q2');

        $this->actingAs($sa1)->post(self::URL, [
            'role' => 'uye', 'parent' => $b2->id, 'username' => 'x_q1', 'password' => '1234',
        ])->assertSessionHasErrors('parent');

        $this->actingAs($owner)->post(self::URL, [
            'role' => 'uye', 'parent' => $sa1->id, 'username' => 'x_q2', 'password' => '1234',
        ])->assertSessionHasErrors('parent');

        $this->actingAs($b1)->post(self::URL, [
            'role' => 'bayi', 'username' => 'x_q3', 'password' => '1234',
        ])->assertSessionHasErrors('role');

        foreach (['x_q1', 'x_q2', 'x_q3'] as $name) {
            $this->assertDatabaseMissing('users', ['username' => $name]);
        }

        $this->actingAs($sa1)->post(self::URL, [
            'role' => 'uye', 'parent' => $b1->id, 'username' => 'x_q4', 'password' => '1234',
        ])->assertSessionHasNoErrors();
        $this->assertSame($b1->id, User::query()->where('username', 'x_q4')->value('parent_id'));
    }
}
