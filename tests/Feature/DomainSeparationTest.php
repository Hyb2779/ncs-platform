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

class DomainSeparationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['domains.site' => 'site.test', 'domains.panel' => 'panel.test']);
    }

    public function test_owner_logs_in_on_panel_but_not_on_site(): void
    {
        $owner = $this->owner();

        $this->post('http://site.test/login', ['username' => $owner->username, 'password' => 'password'])
            ->assertSessionHasErrors(['username' => __('auth.failed')]);
        $this->assertGuest();

        $this->post('http://panel.test/login', ['username' => $owner->username, 'password' => 'password'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($owner);
    }

    public function test_member_logs_in_on_site_but_not_on_panel(): void
    {
        $member = $this->member();

        $this->post('http://panel.test/login', ['username' => $member->username, 'password' => 'password'])
            ->assertSessionHasErrors(['username' => __('auth.failed')]);
        $this->assertGuest();

        $this->post('http://site.test/login', ['username' => $member->username, 'password' => 'password'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($member);
    }

    public function test_panel_and_site_paths_are_redirected_to_their_own_domain(): void
    {
        $this->get('http://site.test/panel/users')->assertRedirect('https://panel.test/panel/users');
        $this->get('http://panel.test/slots')->assertRedirect('/panel');
        $this->get('http://panel.test/')->assertRedirect('/panel');
        $this->get('http://panel.test/login')->assertOk();
        $this->get('http://site.test/login')->assertOk();
    }

    private function owner(): User
    {
        $owner = User::query()->create([
            'username' => 'owner-'.uniqid(), 'password' => 'password', 'role' => UserRole::Owner,
            'parent_id' => null, 'path' => '/', 'depth' => 0, 'superadmin_id' => null,
            'language' => Language::Tr, 'currency' => Currency::Try, 'timezone' => 'Europe/Istanbul',
            'commission_rate' => 0, 'status' => UserStatus::Active,
        ]);
        $owner->path = '/'.$owner->id.'/';
        $owner->save();

        return $owner->refresh();
    }

    private function member(): User
    {
        $h = app(HierarchyService::class);
        $super = $h->create($this->owner(), $this->data('sa-'.uniqid()));
        $bayi = $h->create($super, $this->data('bayi-'.uniqid()));

        return $h->create($bayi, $this->data('uye-'.uniqid()));
    }

    private function data(string $username): array
    {
        return ['username' => $username, 'password' => 'password', 'commission_rate' => 0, 'user_limit' => null,
            'note' => null, 'language' => 'tr', 'currency' => 'TRY', 'timezone' => 'Europe/Istanbul'];
    }
}
