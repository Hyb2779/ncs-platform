<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\WalletProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Panel kullanicisinin kendi sifresi: dogrulama, log, oturum dusmez. */
class PanelPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_user_changes_own_password(): void
    {
        $owner = User::query()->create([
            'username' => 'owner', 'password' => 'password', 'role' => UserRole::Owner, 'parent_id' => null,
            'path' => '/', 'depth' => 0, 'superadmin_id' => null, 'language' => Language::Tr, 'currency' => Currency::Try,
            'timezone' => 'Europe/Istanbul', 'commission_rate' => 0, 'status' => UserStatus::Active,
        ]);
        $owner->path = '/'.$owner->id.'/';
        $owner->save();
        app(WalletProvisioner::class)->openFor($owner->refresh());
        $base = ['password' => 'password', 'commission_rate' => 0, 'user_limit' => null, 'note' => null, 'language' => 'tr', 'currency' => 'TRY'];
        $sa = app(HierarchyService::class)->create($owner, ['username' => 'sa_one'] + $base)->refresh();
        $bayi = app(HierarchyService::class)->create($sa, ['username' => 'bayi_one'] + $base)->refresh();
        $url = 'http://panel.test/panel/password';

        $this->actingAs($bayi)->get($url)->assertOk()->assertSee(__('panel.password_title'));
        $this->actingAs($bayi)->get('http://panel.test/panel')->assertSee('href="'.route('panel.password.edit').'"', false);

        $this->actingAs($bayi)->from($url)->post($url, ['current_password' => 'yanlis', 'password' => 'yeni1', 'password_confirmation' => 'yeni1'])
            ->assertSessionHasErrors('current_password');
        $this->actingAs($bayi)->from($url)->post($url, ['current_password' => 'password', 'password' => 'yeni1', 'password_confirmation' => 'baska'])
            ->assertSessionHasErrors('password');
        $this->actingAs($bayi)->from($url)->post($url, ['current_password' => 'password', 'password' => 'abc', 'password_confirmation' => 'abc'])
            ->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('password', $bayi->refresh()->password));

        $this->actingAs($bayi)->from($url)->post($url, ['current_password' => 'password', 'password' => 'yeni1', 'password_confirmation' => 'yeni1'])
            ->assertSessionHasNoErrors()->assertRedirect(route('panel.password.edit'));
        $this->assertTrue(Hash::check('yeni1', $bayi->refresh()->password));
        $log = ActivityLog::query()->where('action', 'user.password_changed')->where('actor_id', $bayi->id)->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('yeni1', json_encode($log->payload));

        $this->actingAs($bayi)->get('http://panel.test/panel')->assertOk();
    }
}
