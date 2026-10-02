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

/** Alt owner kredi ücreti: süperadminlere verilen brüt kredi × oran; tahsilatı sadece kök owner girer/görür. */
class CreditFeeTest extends TestCase
{
    use RefreshDatabase;

    private const PAY = 'http://panel.test/panel/credit-fees/payments';

    public function test_fee_is_on_gross_credit_given_to_superadmins_only(): void
    {
        [$root, $sub, $sa] = $this->world();
        $w = app(WalletService::class);
        $w->transfer($sub, $sa, '1000.00', 'cf-1', $sub, null, null, Currency::Try);
        $w->transfer($sub, $sa, '500.00', 'cf-2', $sub, null, null, Currency::Usd);
        $w->transfer($sa, $sub, '200.00', 'cf-3', $sub, null, null, Currency::Try); // geri alma: düşülmez

        $rows = collect(app(CreditFees::class)->summary()[0]['rows'])->keyBy('currency');
        $this->assertSame('1000.00', $rows['TRY']['issued']);
        $this->assertSame('120.00', $rows['TRY']['fee']);
        $this->assertSame('500.00', $rows['USD']['issued']);
        $this->assertSame('60.00', $rows['USD']['fee']);
        $this->assertSame('0.00', $rows['EUR']['fee']);
    }

    public function test_root_records_payment_and_due_drops(): void
    {
        [$root, $sub, $sa] = $this->world();
        app(WalletService::class)->transfer($sub, $sa, '1000.00', 'cf-4', $sub, null, null, Currency::Try);

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
        $this->actingAs($sub)->get('http://panel.test/panel')->assertOk()->assertDontSee(__('panel.credit_fees'));
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
}
