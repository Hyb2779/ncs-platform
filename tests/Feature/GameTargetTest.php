<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Casino\GameAvailability;
use App\Services\HierarchyService;
use App\Services\WalletProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Oyun Yonetimi: bayi bazli ac/kapat (target) + Wegas Spor urun engeli. */
class GameTargetTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_closes_slot_and_sport_for_one_bayi_only(): void
    {
        config(['services.ncs_bridge.secret' => 'test-secret']);
        $owner = User::query()->create([
            'username' => 'gt_owner', 'password' => 'password', 'role' => UserRole::Owner,
            'parent_id' => null, 'path' => '/', 'depth' => 0, 'superadmin_id' => null,
            'language' => Language::Tr, 'currency' => Currency::Try, 'timezone' => 'Europe/Istanbul',
            'commission_rate' => 0, 'status' => UserStatus::Active,
        ]);
        $owner->path = '/'.$owner->id.'/';
        $owner->save();
        app(WalletProvisioner::class)->openFor($owner->refresh());
        $h = app(HierarchyService::class);
        $data = fn (string $n) => ['username' => $n, 'password' => 'password', 'commission_rate' => 0, 'user_limit' => null, 'note' => null, 'language' => 'tr', 'currency' => 'TRY'];
        $sa = $h->create($owner, $data('gt_sa'));
        $bayiA = $h->create($sa, $data('gt_bayi_a'));
        $bayiB = $h->create($sa, $data('gt_bayi_b'));
        $uyeA = $h->create($bayiA, $data('gt_uye_a'));
        $uyeB = $h->create($bayiB, $data('gt_uye_b'));
        $sa2 = $h->create($owner, $data('gt_sa2'));
        $bayiC = $h->create($sa2, $data('gt_bayi_c'));

        $block = 'http://panel.test'.parse_url(route('panel.games.block'), PHP_URL_PATH);
        $index = 'http://panel.test'.parse_url(route('panel.games.index'), PHP_URL_PATH);

        $this->assertTrue(wegas_sport_available($uyeA->refresh()));

        $this->actingAs($sa)->post($block, ['scope' => 'category', 'value' => ['slot'], 'blocked' => 1, 'target' => $bayiA->id])->assertRedirect();
        $this->actingAs($sa)->post($block, ['scope' => 'product', 'value' => ['wegas_sport'], 'blocked' => 1, 'target' => $bayiA->id])->assertRedirect();

        $av = app(GameAvailability::class);
        $this->assertContains('slot', $av->blocked(GameAvailability::scopeIdsFor($uyeA))['category']);
        $this->assertNotContains('slot', $av->blocked(GameAvailability::scopeIdsFor($uyeB))['category']);
        $this->assertFalse(wegas_sport_available($uyeA->refresh()));
        $this->assertTrue(wegas_sport_available($uyeB->refresh()));

        $this->actingAs($sa)->post($block, ['scope' => 'category', 'value' => ['slot'], 'blocked' => 1, 'target' => $bayiC->id])->assertNotFound();
        $this->actingAs($sa)->get($index.'?target='.$bayiA->id)->assertOk()->assertSee('gt_bayi_a')->assertSee(__('panel.games_target_label'));
        $this->actingAs($bayiA)->get($index)->assertNotFound();

        // Acinca geri gelir
        $this->actingAs($sa)->post($block, ['scope' => 'product', 'value' => ['wegas_sport'], 'blocked' => 0, 'target' => $bayiA->id])->assertRedirect();
        $this->assertTrue(wegas_sport_available($uyeA->refresh()));
    }
}
