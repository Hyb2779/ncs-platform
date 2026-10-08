<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CasinoGame;
use App\Models\User;
use App\Services\Casino\DemoProvider;
use App\Services\HierarchyService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class GameBackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['casino.demo_secret' => 'demo-callback-secret']);
        app(DemoProvider::class)->syncGames();
    }

    public function test_back_button_returns_to_the_same_site_page_that_opened_the_game(): void
    {
        $member = $this->fundedMember();
        $slot = CasinoGame::query()->where('external_id', 'slot-1')->firstOrFail();
        $live = CasinoGame::query()->where('external_id', 'live-1')->firstOrFail();
        $mini = CasinoGame::query()->create([
            'provider_id' => $slot->provider_id,
            'external_id' => 'mini-1',
            'name' => 'Demo Mini',
            'category' => 'mini',
            'vendor' => 'mini-spribe',
            'is_live' => false,
            'is_active' => true,
            'sort_order' => 0,
            'is_popular' => false,
        ]);

        $root = rtrim((string) config('app.url'), '/');
        $cases = [
            [$slot, $root.'/slots?vendor=pp'],
            [$live, $root.'/live-casino'],
            [$mini, $root.'/mini'],
            [$slot, $root.'/'],
        ];

        foreach ($cases as [$game, $from]) {
            $this->actingAs($member)
                ->get('/play/'.$game->id, ['Referer' => $from])
                ->assertOk()
                ->assertSee('class="play-back" href="'.$from.'"', false);
        }
    }

    public function test_back_button_falls_home_without_a_safe_referrer(): void
    {
        $member = $this->fundedMember();
        $game = CasinoGame::query()->where('external_id', 'slot-1')->firstOrFail();
        $home = route('site.home');

        foreach ([
            null,
            'https://games.example/play',
            'https://cdn.goldpalace.example/launch',
            rtrim((string) config('app.url'), '/').'/play/'.$game->id,
            'http://evil.example/slots',
        ] as $referer) {
            $headers = $referer === null ? [] : ['Referer' => $referer];
            $this->actingAs($member)
                ->get('/play/'.$game->id, $headers)
                ->assertOk()
                ->assertSee('class="play-back" href="'.$home.'"', false);
        }
    }

    public function test_back_label_follows_the_member_language(): void
    {
        $member = $this->fundedMember();
        $game = CasinoGame::query()->where('external_id', 'slot-1')->firstOrFail();

        foreach (['tr' => 'Geri Dön', 'en' => 'Back', 'de' => 'Zurück', 'ar' => 'رجوع'] as $locale => $label) {
            $member->forceFill(['language' => $locale])->save();
            $this->actingAs($member->fresh())
                ->get('/play/'.$game->id)
                ->assertOk()
                ->assertSee($label, false);
        }
    }

    public function test_lobby_survives_a_cached_play_count_that_cannot_be_unserialized(): void
    {
        foreach (['slot', 'live', 'mini'] as $mode) {
            Cache::put('casino:lobby-plays:'.$mode, new \stdClass());
        }

        $this->get('/slots')->assertOk();
        $this->get('/live-casino')->assertOk();
        $this->get('/mini')->assertOk();
        $this->assertIsArray(Cache::get('casino:lobby-plays:slot'));
    }

    private function fundedMember(): User
    {
        $owner = User::query()->create([
            'username' => 'owner-'.uniqid(),
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

        $member = app(HierarchyService::class)->create($owner->refresh(), [
            'username' => 'uye-'.uniqid(),
            'password' => 'password',
            'commission_rate' => 0,
            'user_limit' => null,
            'note' => null,
            'language' => 'tr',
            'currency' => 'TRY',
            'timezone' => 'Europe/Istanbul',
        ]);
        app(WalletService::class)->transfer($owner, $member, '50.00', 'fund-'.uniqid(), $owner);

        return $member->refresh();
    }
}
