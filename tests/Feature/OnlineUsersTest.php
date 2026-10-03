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
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Online kullanicilar: presence yazimi, 5 dk siniri, agac kapsami, bayi 404. */
class OnlineUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_presence_and_online_page(): void
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
        $sa1 = $h->create($owner, ['username' => 'sa_one'] + $base)->refresh();
        $sa2 = $h->create($owner, ['username' => 'sa_two'] + $base)->refresh();
        $bayi1 = $h->create($sa1, ['username' => 'bayi_one'] + $base)->refresh();
        $bayi2 = $h->create($sa2, ['username' => 'bayi_two'] + $base)->refresh();
        $m1 = $h->create($bayi1, ['username' => 'oyuncu_one'] + $base)->refresh();

        $this->actingAs($bayi1)->get('http://panel.test/panel')->assertOk();
        $this->assertSame('panel', Cache::get('presence:'.$bayi1->id)['area'] ?? null);

        Cache::put('presence:'.$m1->id, ['at' => now()->getTimestamp(), 'area' => 'site', 'ip' => '1.2.3.4', 'ua' => 'Mozilla/5.0 (Linux; Android 14) Chrome/120.0'], 300);
        Cache::put('presence:'.$bayi2->id, ['at' => now()->getTimestamp() - 600, 'area' => 'panel', 'ip' => null, 'ua' => null], 300); // 10 dk once: online degil

        $this->actingAs($owner)->get('http://panel.test/panel/online')->assertOk()
            ->assertSee('bayi_one')->assertSee('oyuncu_one')->assertSee('Android · Chrome')->assertDontSee('bayi_two');
        $this->actingAs($sa2)->get('http://panel.test/panel/online')->assertOk()->assertDontSee('oyuncu_one');
        $this->actingAs($bayi1)->get('http://panel.test/panel/online')->assertNotFound();
    }
}
