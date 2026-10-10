<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\WalletProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateSubOwnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_opens_a_second_owner_under_the_root(): void
    {
        $root = $this->root();

        $this->artisan('wegas:create-sub-owner', ['username' => 'Volkan', '--password' => 'gecici-sifre'])
            ->assertSuccessful();

        $sub = User::query()->where('username', 'Volkan')->first();
        $this->assertNotNull($sub);
        $this->assertSame(UserRole::Owner, $sub->role);
        $this->assertSame($root->id, $sub->parent_id);
        $this->assertNull($sub->superadmin_id);
        $this->assertSame($root->depth + 1, $sub->depth);
        $this->assertSame($root->path.$sub->id.'/', $sub->path);
        $this->assertTrue($sub->must_change_password);
        $this->assertTrue(Hash::check('gecici-sifre', $sub->password));
        $this->assertSame(count(Currency::cases()), $sub->wallets()->count());
        $this->assertSame(0, $sub->wallets()->where('allow_negative', true)->count());

        $this->artisan('wegas:create-sub-owner', ['username' => 'Baska', '--password' => 'gecici-sifre'])
            ->assertFailed();
        $this->assertDatabaseMissing('users', ['username' => 'Baska']);
    }

    public function test_command_rejects_a_missing_password(): void
    {
        $this->root();

        $this->artisan('wegas:create-sub-owner', ['username' => 'Volkan'])->assertFailed();

        $this->assertDatabaseMissing('users', ['username' => 'Volkan']);
    }

    private function root(): User
    {
        $user = User::query()->create([
            'username' => 'owner', 'password' => 'password', 'role' => UserRole::Owner, 'parent_id' => null,
            'path' => '/', 'depth' => 0, 'superadmin_id' => null, 'language' => Language::Tr, 'currency' => Currency::Try,
            'timezone' => 'Europe/Istanbul', 'commission_rate' => 0, 'status' => UserStatus::Active,
        ]);
        $user->path = '/'.$user->id.'/';
        $user->save();
        app(WalletProvisioner::class)->openFor($user->refresh());

        return $user->refresh();
    }
}
