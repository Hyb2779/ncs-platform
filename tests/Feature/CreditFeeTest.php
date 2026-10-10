<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\Stats\CreditFees;
use App\Services\WalletProvisioner;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Alt owner kredi ücreti: en yüksek eksi bakiye × oran; tahsilatı sadece kök owner girer/görür. */
class CreditFeeTest extends TestCase
{
    use RefreshDatabase;

    private const PAY = 'http://panel.test/panel/credit-fees/payments';

    public function test_fee_is_on_gross_credit_received_from_root(): void
    {
        [$root, $sub, $sa] = $this->world();
        $w = app(WalletService::class);
        \App\Support\PlatformSetting::putCreditFeeRate('USD', '10');
        $w->transfer($root, $sub, '1000.00', 'cf-1', $root, null, null, Currency::Try);
        $w->transfer($sub, $sa, '1000.00', 'cf-try', $sub, null, null, Currency::Try);
        try {
            $w->transfer($sub, $sa, '400.00', 'cf-mint', $sub, null, null, Currency::Try);
            $this->fail('sub owner should not mint');
        } catch (\App\Services\WalletException $exception) {
            $this->assertSame('wallet.insufficient_balance', $exception->translationKey);
        }
        $w->transfer($root, $sub, '500.00', 'cf-usd', $root, null, null, Currency::Usd);
        $w->transfer($sub, $root, '200.00', 'cf-usd-back', $sub, null, null, Currency::Usd);

        $rows = collect(app(CreditFees::class)->summary()[0]['rows'])->keyBy('currency');
        $this->assertSame('1000.00', $rows['TRY']['issued']);
        $this->assertSame('120.00', $rows['TRY']['fee']);
        $this->assertSame('500.00', $rows['USD']['issued']);
        $this->assertSame('50.00', $rows['USD']['fee']);
        $this->assertSame('0.00', $rows['EUR']['fee']);
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('credit_issues')->count());
    }

    public function test_mistaken_load_correction_reduces_issued_credit(): void
    {
        [$root, $sub] = $this->world();
        $w = app(WalletService::class);
        $w->transfer($root, $sub, '1000000000.00', 'cf-billion', $root, null, null, Currency::Try);
        $w->transfer($sub, $root, '999000000.00', 'credit-load-correction:try', $sub, CreditFees::LOAD_CORRECTION_NOTE, null, Currency::Try);

        $try = collect(app(CreditFees::class)->summary()[0]['rows'])->firstWhere('currency', 'TRY');
        $this->assertSame('1000000.00', $try['issued']);
        $this->assertSame('120000.00', $try['fee']);
        $this->assertSame('1000000.00', number_format((float) $sub->wallets()->where('currency', 'TRY')->value('balance'), 2, '.', ''));
    }

    public function test_loads_sum_and_sub_owner_cannot_go_negative(): void
    {
        [$root, $sub, $sa] = $this->world();
        $w = app(WalletService::class);
        $w->transfer($root, $sub, '10000.00', 'hw-1', $root, null, null, Currency::Try);
        $w->transfer($root, $sub, '5000.00', 'hw-2', $root, null, null, Currency::Try);
        $w->transfer($sub, $sa, '6000.00', 'hw-3', $sub, null, null, Currency::Try);

        $try = collect(app(CreditFees::class)->summary()[0]['rows'])->firstWhere('currency', 'TRY');
        $this->assertSame('15000.00', $try['issued']);
        $this->assertSame('1800.00', $try['fee']);
        $this->assertSame('9000.00', number_format((float) $sub->wallets()->where('currency', 'TRY')->value('balance'), 2, '.', ''));
        $this->assertFalse((bool) $sub->wallets()->where('currency', 'TRY')->value('allow_negative'));
        $this->assertTrue((bool) $root->wallets()->where('currency', 'TRY')->value('allow_negative'));
    }

    public function test_root_records_payment_and_due_drops(): void
    {
        [$root, $sub, $sa] = $this->world();
        app(WalletService::class)->transfer($root, $sub, '1000.00', 'cf-4', $root, null, null, Currency::Try);

        $this->actingAs($root)->post(self::PAY, [
            'sub_owner_id' => $sub->id, 'currency' => 'TRY', 'amount' => '100,50', 'note' => 'nakit',
        ])->assertSessionHasNoErrors();

        $try = collect(app(CreditFees::class)->summary()[0]['rows'])->firstWhere('currency', 'TRY');
        $this->assertSame('100.50', $try['paid']);
        $this->assertSame('19.50', $try['due']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'credit_fee.payment', 'actor_id' => $root->id, 'target_id' => $sub->id]);
    }

    public function test_only_root_owner_sees_card_and_can_record(): void
    {
        [$root, $sub, $sa] = $this->world();

        $this->actingAs($sub)->post(self::PAY, ['sub_owner_id' => $sub->id, 'currency' => 'TRY', 'amount' => '10'])->assertNotFound();
        $this->actingAs($sa)->post(self::PAY, ['sub_owner_id' => $sub->id, 'currency' => 'TRY', 'amount' => '10'])->assertNotFound();
        $this->assertDatabaseCount('credit_fee_payments', 0);

        $this->actingAs($root)->get('http://panel.test/panel')->assertOk()->assertSee(__('panel.credit_fees'));
        $this->actingAs($root)->get('http://panel.test/panel/credit-fees')->assertOk()->assertSee(__('panel.credit_fees'));
        $this->actingAs($sub)->get('http://panel.test/panel')->assertOk()->assertDontSee(__('panel.credit_fees'));
        $this->actingAs($sub)->get('http://panel.test/panel/credit-fees')->assertNotFound();
        $this->actingAs($sub)->post('http://panel.test/panel/credit-fees/rate', ['rates' => ['TRY' => '5', 'USD' => '5', 'EUR' => '5']])->assertNotFound();
    }

    public function test_invalid_payment_is_rejected(): void
    {
        [$root, $sub] = $this->world();

        $this->actingAs($root)->post(self::PAY, ['sub_owner_id' => $sub->id, 'currency' => 'TRY', 'amount' => '0'])->assertSessionHasErrors('amount');
        $this->actingAs($root)->post(self::PAY, ['sub_owner_id' => $root->id, 'currency' => 'TRY', 'amount' => '5'])->assertSessionHasErrors('sub_owner_id');
        $this->assertDatabaseCount('credit_fee_payments', 0);
    }

    /** @return array{0: User, 1: User, 2: User} */
    private function world(): array
    {
        $root = $this->rawUser('root_owner', null);
        $sub = $this->rawUser('volkan_owner', $root);
        $sub->forceFill(['credit_fee_rate' => 12])->save();

        $sa = app(HierarchyService::class)->create($sub, [
            'username' => 'volkan_sa', 'password' => 'password', 'commission_rate' => 0,
            'user_limit' => null, 'note' => null, 'language' => 'tr', 'currency' => 'TRY',
        ]);

        return [$root->refresh(), $sub->refresh(), $sa->refresh()];
    }

    private function rawUser(string $username, ?User $parent): User
    {
        $user = User::query()->create([
            'username' => $username, 'password' => 'password', 'role' => UserRole::Owner,
            'parent_id' => $parent?->id, 'path' => '/', 'depth' => $parent ? $parent->depth + 1 : 0,
            'superadmin_id' => null, 'language' => Language::Tr, 'currency' => Currency::Try,
            'timezone' => 'Europe/Istanbul', 'commission_rate' => 0, 'status' => UserStatus::Active,
        ]);
        $user->path = ($parent?->path ?? '/').$user->id.'/';
        $user->save();
        app(WalletProvisioner::class)->openFor($user->refresh());

        return $user->refresh();
    }

    public function test_sub_owner_distributes_only_received_credit_and_other_branches_are_closed(): void
    {
        [$root, $sub, $sa] = $this->world();
        $h = app(HierarchyService::class);
        $data = fn (string $name) => [
            'username' => $name, 'password' => 'password', 'commission_rate' => 0,
            'user_limit' => null, 'note' => null, 'language' => 'tr', 'currency' => 'TRY',
        ];
        $bayi = $h->create($sa, $data('bayi_cf'));
        $otherSa = $h->create($root, $data('sa_baska'));

        $this->actingAs($sub)->get('http://panel.test/panel/balance')->assertOk()->assertSee('bayi_cf')->assertDontSee('sa_baska');

        $this->actingAs($sub)->post('http://panel.test/panel/users/'.$bayi->id.'/balance', [
            'amount' => '100.00', 'direction' => 'add', 'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ])->assertSessionHasErrors('amount');

        app(WalletService::class)->transfer($root, $sub, '100.00', 'cf-fund', $root, null, null, Currency::Try);

        $this->actingAs($sub)->post('http://panel.test/panel/users/'.$bayi->id.'/balance', [
            'amount' => '100.00', 'direction' => 'add', 'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ])->assertSessionHasNoErrors();
        $this->assertEquals(100, (float) $bayi->wallets()->value('balance'));

        $this->actingAs($sub)->post('http://panel.test/panel/users/'.$otherSa->id.'/balance', [
            'amount' => '10.00', 'direction' => 'add', 'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ])->assertNotFound();

        $this->actingAs($sub)->post('http://panel.test/panel/users/'.$bayi->id.'/balance', [
            'amount' => '5000.00', 'direction' => 'add', 'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ])->assertSessionHasErrors('amount');

        $try = collect(app(CreditFees::class)->summary()[0]['rows'])->firstWhere('currency', 'TRY');
        $this->assertSame('100.00', $try['issued']);
        $this->assertSame('12.00', $try['fee']);
        \Illuminate\Support\Facades\DB::table('credit_issues')->insert([
            'user_id' => $sub->id, 'currency' => 'TRY', 'amount' => '25.00', 'created_at' => now(),
        ]);
        $issue = \Illuminate\Support\Facades\DB::table('credit_issues')->first();
        try {
            \Illuminate\Support\Facades\DB::table('credit_issues')->where('id', $issue->id)->update(['amount' => '1.00']);
            $this->fail('credit issue update should be rejected');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }
    }
}
