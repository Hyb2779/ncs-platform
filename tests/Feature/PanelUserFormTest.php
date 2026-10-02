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
}
