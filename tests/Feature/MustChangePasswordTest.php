<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\WalletProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MustChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_flagged_panel_user_can_open_only_the_password_screen(): void
    {
        $owner = $this->root();
        $owner->forceFill(['must_change_password' => true])->save();
        $bayi = $this->bayi($owner);
        $bayi->forceFill(['must_change_password' => true])->save();
        $password = 'http://panel.test/panel/password';

        $this->actingAs($owner)->get('http://panel.test/panel')->assertRedirect(route('panel.password.edit'));
        $this->actingAs($owner)->getJson('http://panel.test/panel')->assertForbidden();
        $this->actingAs($owner)->get($password)->assertOk()->assertSee(__('panel.password_must_change'))->assertDontSee(__('panel.overview'), false);

        $this->actingAs($owner)->from($password)->post($password, [
            'current_password' => 'password', 'password' => 'password', 'password_confirmation' => 'password',
        ])->assertSessionHasErrors('password');
        $this->assertTrue($owner->refresh()->must_change_password);

        $this->actingAs($owner)->from($password)->post($password, [
            'current_password' => 'password', 'password' => 'yeni-sifre', 'password_confirmation' => 'yeni-sifre',
        ])->assertRedirect('/panel');
        $this->assertFalse($owner->refresh()->must_change_password);
        $this->actingAs($owner)->get('http://panel.test/panel')->assertOk();

        $this->actingAs($bayi)->get('http://panel.test/panel/reports')->assertRedirect(route('panel.password.edit'));
        $this->actingAs($bayi)->get($password)->assertOk();
    }

    public function test_flagged_member_can_open_only_the_password_form(): void
    {
        $owner = $this->root();
        $member = $this->member($owner);
        $member->forceFill(['must_change_password' => true])->save();

        $this->actingAs($member)->get('/')->assertRedirect(route('site.account'));
        $this->actingAs($member)->get(route('site.account.movements'))->assertRedirect(route('site.account'));
        $this->actingAs($member)->get(route('site.account'))->assertOk()->assertSee(__('panel.password_must_change'))->assertDontSee(__('site.theme'), false);

        $this->actingAs($member)->from(route('site.account'))->post(route('site.password'), [
            'current_password' => 'password', 'password' => 'password', 'password_confirmation' => 'password',
        ])->assertSessionHasErrors('password');

        $this->actingAs($member)->from(route('site.account'))->post(route('site.password'), [
            'current_password' => 'password', 'password' => 'yeni-sifre', 'password_confirmation' => 'yeni-sifre',
        ])->assertRedirect(route('site.account'));
        $this->assertFalse($member->refresh()->must_change_password);
        $this->actingAs($member)->get('/')->assertOk();
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

    private function bayi(User $owner): User
    {
        $data = ['password' => 'password', 'commission_rate' => 0, 'user_limit' => null, 'note' => null, 'language' => 'tr', 'currency' => 'TRY'];
        $sa = app(HierarchyService::class)->create($owner, ['username' => 'sa_one'] + $data);

        return app(HierarchyService::class)->create($sa, ['username' => 'bayi_one'] + $data)->refresh();
    }

    private function member(User $owner): User
    {
        $data = ['password' => 'password', 'commission_rate' => 0, 'user_limit' => null, 'note' => null, 'language' => 'tr', 'currency' => 'TRY'];
        $sa = app(HierarchyService::class)->create($owner, ['username' => 'sa_one'] + $data);
        $bayi = app(HierarchyService::class)->create($sa, ['username' => 'bayi_one'] + $data);

        return app(HierarchyService::class)->create($bayi, ['username' => 'uye_one'] + $data)->refresh();
    }
}
