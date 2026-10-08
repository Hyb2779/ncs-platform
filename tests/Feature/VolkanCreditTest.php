<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\WalletService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class VolkanCreditTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'http://panel.test/panel';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_only_the_root_owner_can_open_the_page(): void
    {
        [$root, $sub, $sa, $bayi] = $this->world();

        $this->actingAs($root)->get(self::BASE)->assertOk()->assertSee(__('panel.volkan_credit'), false);
        $this->actingAs($root)->get(self::BASE.'/volkan-credit')->assertOk()
            ->assertSee(__('panel.volkan_credit_produced'), false)
            ->assertSee(__('panel.volkan_credit_distributed'), false);

        foreach ([$sub, $sa, $bayi] as $user) {
            $this->actingAs($user)->get(self::BASE)->assertOk()->assertDontSee(__('panel.volkan_credit'), false);
            $this->actingAs($user)->get(self::BASE.'/volkan-credit')->assertForbidden();
            $this->actingAs($user)->get(self::BASE.'/reports')->assertOk()->assertDontSee(__('panel.volkan_credit_produced'), false);
            $this->actingAs($user)->get(self::BASE.'/transactions')->assertOk()->assertDontSee(__('panel.volkan_credit_distributed'), false);
        }
    }

    public function test_page_follows_the_root_owner_language(): void
    {
        [$root] = $this->world();

        foreach (['tr' => 'Ürettiği kredi', 'en' => 'Credit he produced', 'de' => 'Von ihm erzeugtes Guthaben', 'ar' => 'الائتمان الذي أنتجه'] as $locale => $label) {
            $root->forceFill(['language' => $locale])->save();
            $this->actingAs($root->fresh())->get(self::BASE.'/volkan-credit')->assertOk()->assertSee($label, false);
        }
    }

    public function test_only_new_negative_troughs_count_and_the_week_filter_splits_them(): void
    {
        [$root, $sub, , , $member] = $this->world();
        $wallets = app(WalletService::class);

        Carbon::setTestNow(Carbon::parse('2026-09-30 12:00:00', 'Europe/Istanbul'));
        $wallets->transfer($sub, $member, '100.00', 'load-100', $sub);

        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00', 'Europe/Istanbul'));
        $wallets->transfer($member, $sub, '70.00', 'return-70', $member);
        $wallets->transfer($sub, $member, '20.00', 'load-20', $sub);
        $wallets->transfer($sub, $root, '80.00', 'to-root', $sub);
        $wallets->transfer($root, $sub, '90.00', 'repay-90', $root);

        Carbon::setTestNow(Carbon::parse('2026-10-08 12:00:00', 'Europe/Istanbul'));

        $week = $this->actingAs($root)->get(self::BASE.'/volkan-credit');
        $week->assertOk();
        $week->assertSee('period=this_week', false);
        $week->assertSee(Money::format('30.00', Currency::Try), false);
        $week->assertSee(Money::format('20.00', Currency::Try), false);
        $week->assertDontSee(Money::format('100.00', Currency::Try), false);

        $lastWeek = $this->actingAs($root)->get(self::BASE.'/volkan-credit?period=custom&from=2026-09-28&to=2026-10-04');
        $lastWeek->assertOk()->assertSee(Money::format('100.00', Currency::Try), false);
        $lastWeek->assertDontSee(Money::format('20.00', Currency::Try), false);

        $both = $this->actingAs($root)->get(self::BASE.'/volkan-credit?period=custom&from=2026-09-28&to=2026-10-08');
        $both->assertOk();
        $both->assertSee(Money::format('130.00', Currency::Try), false);
        $both->assertSee(Money::format('120.00', Currency::Try), false);

        $this->actingAs($sub)->get(self::BASE)->assertOk()
            ->assertDontSee(__('panel.volkan_credit_produced'), false)
            ->assertDontSee(Money::format('130.00', Currency::Try), false)
            ->assertDontSee(Money::format('120.00', Currency::Try), false);
        $this->actingAs($sub)->get(self::BASE.'/reports')->assertOk()
            ->assertDontSee(Money::format('130.00', Currency::Try), false)
            ->assertDontSee(Money::format('120.00', Currency::Try), false);
    }

    /**
     * @return array{0: User, 1: User, 2: User, 3: User, 4: User}
     */
    private function world(): array
    {
        $root = $this->raw('root_owner', UserRole::Owner, null);
        $sub = $this->raw('Volkan', UserRole::Owner, $root);
        $hierarchy = app(HierarchyService::class);
        $base = ['password' => 'password', 'commission_rate' => 0, 'user_limit' => null, 'note' => null, 'language' => 'tr', 'currency' => 'TRY'];
        $sa = $hierarchy->create($sub, ['username' => 'volkan_sa'] + $base);
        $bayi = $hierarchy->create($sa, ['username' => 'volkan_bayi'] + $base);
        $member = $hierarchy->create($bayi, ['username' => 'volkan_uye'] + $base);

        return [$root->refresh(), $sub->refresh(), $sa, $bayi, $member];
    }

    private function raw(string $username, UserRole $role, ?User $parent): User
    {
        $user = User::query()->create([
            'username' => $username,
            'password' => 'password',
            'role' => $role,
            'parent_id' => $parent?->id,
            'path' => '/',
            'depth' => $parent ? $parent->depth + 1 : 0,
            'superadmin_id' => null,
            'language' => Language::Tr,
            'currency' => Currency::Try,
            'timezone' => 'Europe/Istanbul',
            'commission_rate' => 0,
            'status' => UserStatus::Active,
        ]);
        $user->path = ($parent?->path ?? '/').$user->id.'/';
        $user->save();

        return $user->refresh();
    }
}
