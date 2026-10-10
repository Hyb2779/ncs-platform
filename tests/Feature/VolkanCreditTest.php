<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Models\Wallet;
use App\Services\HierarchyService;
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
            ->assertSee(__('panel.volkan_credit_distributed'), false)
            ->assertDontSee('Ürettiği kredi', false);

        foreach ([$sub, $sa, $bayi] as $user) {
            $this->actingAs($user)->get(self::BASE)->assertOk()->assertDontSee(__('panel.volkan_credit'), false);
            $this->actingAs($user)->get(self::BASE.'/volkan-credit')->assertForbidden();
            $this->actingAs($user)->get(self::BASE.'/reports')->assertOk()->assertDontSee(__('panel.volkan_credit_distributed'), false);
            $this->actingAs($user)->get(self::BASE.'/transactions')->assertOk()->assertDontSee(__('panel.volkan_credit_distributed'), false);
        }
    }

    public function test_page_follows_the_root_owner_language(): void
    {
        [$root] = $this->world();

        foreach ([
            'tr' => ['Alt Owner Takip', 'Dağıttığı kredi'],
            'en' => ['Sub-owner tracking', 'Credit distributed'],
            'de' => ['Sub-Owner-Übersicht', 'Verteiltes Guthaben'],
            'ar' => ['متابعة المالكين الفرعيين', 'الائتمان الموزَّع'],
        ] as $locale => [$title, $distributed]) {
            $root->forceFill(['language' => $locale])->save();
            $this->actingAs($root->fresh())->get(self::BASE.'/volkan-credit')->assertOk()
                ->assertSee($title, false)
                ->assertSee($distributed, false)
                ->assertDontSee('Ürettiği kredi', false);
        }
    }

    public function test_lists_every_sub_owner(): void
    {
        [$root, $volkan, , , $member] = $this->world();
        $robin = $this->raw('Robin', UserRole::Owner, $root);
        $robinMember = $this->raw('robin_uye', UserRole::Uye, $robin);
        app(\App\Services\WalletProvisioner::class)->openFor($volkan);
        app(\App\Services\WalletProvisioner::class)->openFor($robin);

        $this->issue($volkan, '9000.00', '2026-10-08 09:00:00');
        $this->load($volkan->wallets()->where('currency', Currency::Try)->firstOrFail(), $volkan, $member, '-4000.00', '2026-10-08 11:00:00', 'volkan-load');
        $this->load($robin->wallets()->where('currency', Currency::Try)->firstOrFail(), $robin, $robinMember, '-3000.00', '2026-10-08 12:00:00', 'robin-load');
        Carbon::setTestNow(Carbon::parse('2026-10-08 18:00:00', 'Europe/Istanbul'));

        $page = $this->actingAs($root)->get(self::BASE.'/volkan-credit');
        $page->assertOk();
        $page->assertSeeInOrder(['Robin', 'Volkan'], false);
        $page->assertSee(Money::format('3000.00', Currency::Try), false);
        $page->assertSee(Money::format('4000.00', Currency::Try), false);
        $page->assertDontSee(Money::format('9000.00', Currency::Try), false);
    }

    public function test_each_day_sums_recorded_issues_and_loads_newest_first(): void
    {
        [$root, $sub, , , $member] = $this->world();
        app(\App\Services\WalletProvisioner::class)->openFor($sub);
        $wallet = $sub->wallets()->where('currency', Currency::Try)->firstOrFail();

        $this->issue($sub, '15000.00', '2026-10-07 15:00:00');
        $this->issue($sub, '10000.00', '2026-10-08 11:00:00');
        $this->load($wallet, $sub, $member, '-7000.00', '2026-10-07 16:00:00', 'day-7000');
        $this->load($wallet, $sub, $member, '-5000.00', '2026-10-08 12:00:00', 'day-5000');
        $this->load($wallet, $sub, $root, '-999.00', '2026-10-08 13:00:00', 'to-root');

        Carbon::setTestNow(Carbon::parse('2026-10-08 18:00:00', 'Europe/Istanbul'));

        $week = $this->actingAs($root)->get(self::BASE.'/volkan-credit');
        $week->assertOk();
        $week->assertSee('Volkan', false);
        $week->assertSee('period=this_week', false);
        $week->assertSeeInOrder([
            '08.10.2026',
            Money::format('5000.00', Currency::Try),
            '07.10.2026',
            Money::format('7000.00', Currency::Try),
            __('panel.volkan_credit_total'),
            Money::format('12000.00', Currency::Try),
        ], false);
        $week->assertDontSee(Money::format('999.00', Currency::Try), false);
        $week->assertDontSee(Money::format('15000.00', Currency::Try), false);
        $week->assertDontSee(Money::format('10000.00', Currency::Try), false);
        $week->assertDontSee(Money::format('25000.00', Currency::Try), false);

        $yesterday = $this->actingAs($root)->get(self::BASE.'/volkan-credit?period=custom&from=2026-10-07&to=2026-10-07');
        $yesterday->assertOk();
        $yesterday->assertSeeInOrder([
            '07.10.2026',
            Money::format('7000.00', Currency::Try),
            __('panel.volkan_credit_total'),
            Money::format('7000.00', Currency::Try),
        ], false);
        $yesterday->assertDontSee('08.10.2026', false);
        $yesterday->assertDontSee(Money::format('5000.00', Currency::Try), false);
        $yesterday->assertDontSee(Money::format('15000.00', Currency::Try), false);

        $this->actingAs($sub)->get(self::BASE.'/volkan-credit')->assertForbidden();
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

    private function issue(User $sub, string $amount, string $local): void
    {
        \Illuminate\Support\Facades\DB::table('credit_issues')->insert([
            'user_id' => $sub->id,
            'currency' => Currency::Try->value,
            'amount' => $amount,
            'created_at' => Carbon::parse($local, 'Europe/Istanbul')->utc(),
        ]);
    }

    private function load(Wallet $wallet, User $sub, User $counterparty, string $amount, string $local, string $key): void
    {
        \Illuminate\Support\Facades\DB::table('wallet_transactions')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'wallet_id' => $wallet->id,
            'user_id' => $sub->id,
            'type' => 'transfer_out',
            'product' => 'transfer',
            'amount' => $amount,
            'balance_before' => '0.00',
            'balance_after' => '0.00',
            'idempotency_key' => $key,
            'counterparty_user_id' => $counterparty->id,
            'created_by' => $sub->id,
            'created_at' => Carbon::parse($local, 'Europe/Istanbul')->utc(),
        ]);
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
