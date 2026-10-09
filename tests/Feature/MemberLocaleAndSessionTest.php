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

class MemberLocaleAndSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_change_site_language_without_affecting_others(): void
    {
        [$member, $other] = $this->members();

        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertSee('action="'.route('site.locale').'"', false)
            ->assertSee('lang="de"', false);

        $this->actingAs($member)->from('/')->post('/locale', ['language' => 'ar'])->assertRedirect('/');

        $this->assertSame(Language::Ar, $member->fresh()->language);
        $this->assertSame(Language::De, $other->fresh()->language);

        $this->actingAs($member->fresh())->get('/')
            ->assertOk()
            ->assertSee('lang="ar"', false)
            ->assertSee('dir="rtl"', false);

        $this->actingAs($member->fresh())->get('/?lang=en')->assertSee('lang="ar"', false);
        $this->actingAs($member->fresh())->post('/locale', ['language' => 'fr'])->assertStatus(422);
    }

    public function test_guest_keeps_query_language_and_dealer_cannot_use_the_member_switch(): void
    {
        [$member] = $this->members();
        $dealer = $member->parent;

        $this->get('/')->assertDontSee('action="'.route('site.locale').'"', false);
        $this->get('/?lang=en')->assertOk()->assertSee('lang="en"', false);

        $this->actingAs($dealer)->post('/locale', ['language' => 'en'])->assertForbidden();
        $this->assertSame(Language::De, $dealer->fresh()->language);
    }

    public function test_a_new_login_closes_the_other_session(): void
    {
        [$member] = $this->members();

        $this->post('/login', ['username' => $member->username, 'password' => 'password'])->assertRedirect('/');
        $first = $member->fresh()->auth_session;
        $this->assertIsString($first);
        $this->assertSame($first, session('auth_session'));

        $this->app['auth']->forgetGuards();
        $this->get('/')->assertOk();

        $this->post('/logout');
        $this->post('/login', ['username' => $member->username, 'password' => 'password'])->assertRedirect('/');
        $second = $member->fresh()->auth_session;
        $this->assertNotSame($first, $second);
        $this->assertSame($second, session('auth_session'));

        $this->app['auth']->forgetGuards();
        $this->get('/')->assertOk();

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->actingAs($member->fresh())->withSession(['auth_session' => $first])
            ->get('/')
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['username' => __('auth.session_replaced', [], 'de')]);
    }

    public function test_the_current_session_stays_open_and_json_points_to_login(): void
    {
        [$member] = $this->members();

        $this->actingAs($member)->get('/account')->assertOk();

        $member->forceFill(['auth_session' => 'same-device'])->save();
        $this->actingAs($member->fresh())->withSession(['auth_session' => 'same-device'])
            ->get('/account')
            ->assertOk();

        $member->forceFill(['auth_session' => 'other-device'])->save();

        $this->actingAs($member->fresh())->withSession(['auth_session' => 'same-device'])
            ->getJson('/account/balance')
            ->assertUnauthorized()
            ->assertJsonPath('redirect', route('login'))
            ->assertJsonPath('message', __('auth.session_replaced', [], 'de'));
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function members(): array
    {
        $owner = User::query()->create([
            'username' => 'owner-locale',
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

        $hierarchy = app(HierarchyService::class);
        $base = ['password' => 'password', 'commission_rate' => 0, 'user_limit' => null, 'note' => null, 'currency' => 'EUR', 'timezone' => 'Europe/Istanbul'];
        $superadmin = $hierarchy->create($owner, ['username' => 'sa-locale', 'language' => 'de'] + $base);
        $dealer = $hierarchy->create($superadmin, ['username' => 'bayi-locale', 'language' => 'de'] + $base);
        $member = $hierarchy->create($dealer, ['username' => 'uye-locale'] + $base);
        $other = $hierarchy->create($dealer, ['username' => 'uye-other'] + $base);

        return [$member->fresh(), $other->fresh()];
    }
}
