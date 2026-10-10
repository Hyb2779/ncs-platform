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

/** Ust barda tek bakiye + para birimi secimi (cerez), profil kartinda tum bakiyeler, loglarda sekme yok. */
class PanelHeaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_currency_picker_and_balances(): void
    {
        $owner = User::query()->create([
            'username' => 'owner', 'password' => 'password', 'role' => UserRole::Owner, 'parent_id' => null,
            'path' => '/', 'depth' => 0, 'superadmin_id' => null, 'language' => Language::Tr, 'currency' => Currency::Try,
            'timezone' => 'Europe/Istanbul', 'commission_rate' => 0, 'status' => UserStatus::Active,
        ]);
        $owner->path = '/'.$owner->id.'/';
        $owner->save();
        app(WalletProvisioner::class)->openFor($owner->refresh());
        $h = app(HierarchyService::class);
        $base = ['password' => 'password', 'commission_rate' => 0, 'user_limit' => null, 'note' => null, 'language' => 'tr', 'currency' => 'TRY'];
        $sa = $h->create($owner, ['username' => 'sa_one'] + $base)->refresh();
        $bayi = $h->create($sa, ['username' => 'bayi_one'] + $base)->refresh();

        // 04.10: para birimi secici kaldirildi; bakiye satirlari ve dil secici profil kartinda.
        $this->actingAs($sa)->get('http://panel.test/panel')->assertOk()
            ->assertSee('data-header="balance"', false)
            ->assertDontSee('aria-label="'.__('panel.display_currency').'"', false)
            ->assertSee('· EUR', false)
            ->assertSee('data-header="theme"', false)->assertSee('panelTheme', false);

        // Dil secimi Ayarlar > Dil secenegi sayfasinda; menude ogesi var.
        $this->actingAs($sa)->get('http://panel.test/panel/preferences/language')->assertOk()->assertSee('name="language" value="en"', false);
        $this->actingAs($bayi)->get('http://panel.test/panel')->assertOk()
            ->assertSee(__('panel.menu_language'))
            ->assertSee(__('panel.menu_app_download'))
            ->assertSee('href="/downloads/wegas-panel.apk"', false);
        $this->actingAs($sa)->from('http://panel.test/panel')->post('http://panel.test/panel/preferences/language', ['language' => 'en'])
            ->assertRedirect()->assertCookie('panel_locale', 'en');
        $this->actingAs($sa)->withCookie('panel_locale', 'en')->get('http://panel.test/panel')->assertOk()
            ->assertSee('<html lang="en"', false);
        $this->actingAs($sa)->post('http://panel.test/panel/preferences/language', ['language' => 'fr'])->assertStatus(422);
        $this->actingAs($sa)->post('http://panel.test/panel/preferences/currency', ['currency' => 'GBP'])->assertStatus(422);

        // Tek para birimli bayi: secici yok, kart var.
        $this->actingAs($bayi)->get('http://panel.test/panel')->assertOk()
            ->assertSee('data-header="balance"', false)
            ->assertDontSee('aria-label="'.__('panel.display_currency').'"', false);
        $this->actingAs($bayi)->post('http://panel.test/panel/preferences/currency', ['currency' => 'USD'])->assertStatus(422);

        // Log sayfalarinda ust gecis yok: Giris logu sayfasinda Islem logu sekme linki gorunmez (menu linki haric tek).
        $html = $this->actingAs($owner)->get('http://panel.test/panel/logs/logins')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'href="'.route('panel.logs.index').'"'));
    }
}
