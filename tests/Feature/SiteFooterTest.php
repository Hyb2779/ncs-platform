<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CasinoGame;
use App\Models\CasinoProvider;
use App\Models\GameBlock;
use App\Models\User;
use App\Services\Casino\GameCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteFooterTest extends TestCase
{
    use RefreshDatabase;

    public function test_rounded_counts_use_the_locale_number_format(): void
    {
        $this->assertSame(3900, GameCatalog::rounded(3927));
        $this->assertSame(100, GameCatalog::rounded(109));
        $this->assertSame(9, GameCatalog::rounded(9));

        app()->setLocale('tr');
        $this->assertSame('3.900+', GameCatalog::formatPlus(3927));
        app()->setLocale('en');
        $this->assertSame('3,900+', GameCatalog::formatPlus(3927));
    }

    public function test_footer_lists_active_brands_and_opens_the_filtered_lobby(): void
    {
        $active = $this->provider('gold', 'active');
        $this->game($active, 'sweet', 'Sweet', 'pp', 'slot', false);
        $this->game($active, 'plain', 'Plain', null, 'slot', false);
        $this->game($active, 'roulette', 'Roulette', 'casino-evolution', 'live', true);
        $this->game($active, 'aviator', 'Aviator', 'mini-spribe', 'mini', false);
        $this->game($active, 'hidden', 'Hidden Slot', 'hab', 'slot', false, false);
        $this->game($this->provider('closed', 'passive'), 'egt-game', 'EGT Game', 'egt', 'slot', false);
        $this->game($active, 'virtual-one', 'Virtual Cup', 'goldenrace', 'virtual', false);

        $home = $this->get('/');
        $home->assertOk()
            ->assertSee('data-footer-stat="slots">2+', false)
            ->assertSee('data-footer-stat="live">1+', false)
            ->assertSee('data-footer-stat="providers">3+', false)
            ->assertSee(__('site.footer_slots'), false)
            ->assertSee(__('site.footer_tables'), false)
            ->assertSee(__('site.footer_providers'), false)
            ->assertSee(__('site.footer_quick'), false)
            ->assertSee('/slots?vendor=pp', false)
            ->assertSee('/live-casino?vendor=casino-evolution', false)
            ->assertSee('/mini?vendor=mini-spribe', false)
            ->assertSee('Pragmatic Play', false)
            ->assertSee('Evolution', false)
            ->assertSee('Spribe', false)
            ->assertSee(__('site.license_regulator'), false)
            ->assertSee('License No: '.GameCatalog::LICENSE_NO, false)
            ->assertSee('Company No: '.GameCatalog::COMPANY_NO, false)
            ->assertSee('target="_blank"', false)
            ->assertSee(route('site.license'), false)
            ->assertSee(__('site.footer_age'), false)
            ->assertDontSee('Habanero', false)
            ->assertDontSee('EGT', false)
            ->assertDontSee('GoldenRace', false)
            ->assertDontSee(__('home.footer_note', ['brand' => brand()->name()]), false);

        $this->get('/slots?vendor=pp')->assertOk()->assertSee('Sweet', false)->assertDontSee('Plain', false);
        $this->get('/live-casino?vendor=casino-evolution')->assertOk()->assertSee('Roulette', false)->assertDontSee('Sweet', false);
        $this->get('/mini?vendor=mini-spribe')->assertOk()->assertSee('Aviator', false)->assertDontSee('Roulette', false);

        $this->get('/?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee(__('site.footer_quick', [], 'ar'), false);
        $this->get('/lisans-dogrula?lang=ar')->assertOk()
            ->assertSee(__('site.license_title', [], 'ar'), false)
            ->assertSee(__('site.license_back', [], 'ar'), false)
            ->assertSee('License No: '.GameCatalog::LICENSE_NO, false);
    }

    public function test_empty_groups_stay_hidden_and_global_blocks_drop_the_brand(): void
    {
        $active = $this->provider('gold', 'active');
        $this->game($active, 'sweet', 'Sweet', 'pp', 'slot', false);
        $owner = User::query()->create([
            'username' => 'owner-footer', 'password' => 'password', 'role' => UserRole::Owner,
            'parent_id' => null, 'path' => '/', 'depth' => 0, 'superadmin_id' => null,
            'language' => Language::Tr, 'currency' => Currency::Try, 'timezone' => 'Europe/Istanbul',
            'status' => UserStatus::Active,
        ]);
        GameBlock::query()->create([
            'superadmin_id' => null, 'scope' => 'vendor', 'value' => 'pp', 'created_by' => $owner->id,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee(__('site.footer_quick'), false)
            ->assertDontSee('Pragmatic Play', false)
            ->assertSee('data-footer-stat="slots">0+', false);
    }

    public function test_license_page_is_public_and_the_panel_host_does_not_serve_it(): void
    {
        config(['domains.site' => 'wegas11.com', 'domains.panel' => 'panel.wegas11.com']);

        $this->get('http://wegas11.com/lisans-dogrula')
            ->assertOk()
            ->assertSee(__('site.license_title'), false)
            ->assertSee(__('site.license_back'), false)
            ->assertSee('Curaçao Gaming Control Board', false)
            ->assertSee('License No: '.GameCatalog::LICENSE_NO, false)
            ->assertSee('Company No: '.GameCatalog::COMPANY_NO, false)
            ->assertSee('Domain: wegas11.com', false)
            ->assertSee('Status: Valid', false);

        $this->get('http://panel.wegas11.com/lisans-dogrula')->assertRedirect('/panel');
        $this->get('http://panel.wegas11.com/login')->assertOk()->assertDontSee('Curaçao Gaming Control Board', false);
    }

    private function provider(string $code, string $status): CasinoProvider
    {
        return CasinoProvider::query()->create([
            'code' => $code, 'name' => $code, 'status' => $status, 'is_live' => false,
        ]);
    }

    private function game(CasinoProvider $provider, string $externalId, string $name, ?string $vendor, string $category, bool $live, bool $active = true): CasinoGame
    {
        return CasinoGame::query()->create([
            'provider_id' => $provider->id,
            'external_id' => $externalId,
            'name' => $name,
            'vendor' => $vendor,
            'category' => $category,
            'is_live' => $live,
            'is_active' => $active,
        ]);
    }
}
