<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Services\WalletException;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiCurrencySuperadminTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_is_multi_currency_and_bayi_locks_its_country(): void
    {
        $owner = $this->owner();
        $h = app(HierarchyService::class);
        $w = app(WalletService::class);

        $sa = $h->create($owner, $this->data('sa', 'tr', 'TRY', 'Europe/Istanbul'));
        $this->assertSame(3, $sa->wallets()->count());

        // Owner → süperadmin: seçilen para birimi (süperadminin varsayılanı TRY)
        $w->transfer($owner, $sa, '100.00', 'mc-1', $owner, null, null, Currency::Usd);
        $w->transfer($owner, $sa, '80.00', 'mc-2', $owner, null, null, Currency::Eur);
        $this->assertSame('100.00', $w->walletFor($sa, Currency::Usd)->fresh()->balance);
        $this->assertSame('80.00', $w->walletFor($sa, Currency::Eur)->fresh()->balance);
        $this->assertSame('0.00', $w->walletFor($sa, Currency::Try)->fresh()->balance);

        // Süperadmin EUR / Almanca / Berlin bayi açar, üye miras alır
        $bayi = $h->create($sa, $this->data('bayi-de', 'de', 'EUR', 'Europe/Berlin'));
        $this->assertSame(Currency::Eur, $bayi->currency);
        $this->assertSame(Language::De, $bayi->language);
        $this->assertSame('Europe/Berlin', $bayi->timezone);
        $this->assertSame(1, $bayi->wallets()->count());
        $uye = $h->create($bayi, $this->data('uye-de', 'tr', 'TRY', 'UTC'));
        $this->assertSame(Currency::Eur, $uye->currency);
        $this->assertSame(Language::De, $uye->language);

        // Süperadmin → bayi: para birimi belirtilmeden bayinin para biriminden (EUR)
        $w->transfer($sa, $bayi, '30.00', 'mc-3', $sa);
        $this->assertSame('50.00', $w->walletFor($sa, Currency::Eur)->fresh()->balance);
        $this->assertSame('30.00', $w->walletFor($bayi, Currency::Eur)->fresh()->balance);

        // EUR bayiye USD gönderilemez, hiçbir bakiye değişmez
        try {
            $w->transfer($sa, $bayi, '10.00', 'mc-4', $sa, null, null, Currency::Usd);
            $this->fail('currency_mismatch bekleniyordu');
        } catch (WalletException $e) {
            $this->assertSame('wallet.currency_mismatch', $e->translationKey);
        }
        $this->assertSame('100.00', $w->walletFor($sa, Currency::Usd)->fresh()->balance);
        $this->assertSame('30.00', $w->walletFor($bayi, Currency::Eur)->fresh()->balance);

        $this->artisan('wallet:verify')->assertOk();
    }

    public function test_owner_loads_superadmin_in_chosen_currency_from_panel(): void
    {
        $owner = $this->owner();
        $sa = app(HierarchyService::class)->create($owner, $this->data('sa-panel', 'tr', 'TRY', 'Europe/Istanbul'));

        $this->actingAs($owner)->post('/panel/users/'.$sa->id.'/balance', [
            'direction' => 'add', 'amount' => '25.00', 'currency' => 'USD', 'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ])->assertSessionHasNoErrors();

        $w = app(WalletService::class);
        $this->assertSame('25.00', $w->walletFor($sa, Currency::Usd)->fresh()->balance);
        $this->assertSame('0.00', $w->walletFor($sa, Currency::Try)->fresh()->balance);
    }

    private function owner(): User
    {
        $owner = User::query()->create([
            'username' => 'owner', 'password' => 'password', 'role' => UserRole::Owner,
            'parent_id' => null, 'path' => '/', 'depth' => 0, 'superadmin_id' => null,
            'language' => Language::Tr, 'currency' => Currency::Try, 'timezone' => 'Europe/Istanbul',
            'commission_rate' => 0, 'status' => UserStatus::Active,
        ]);
        $owner->path = '/'.$owner->id.'/';
        $owner->save();

        return $owner->refresh();
    }

    private function data(string $username, string $lang, string $cur, string $tz): array
    {
        return ['username' => $username, 'password' => 'password', 'commission_rate' => 0, 'user_limit' => null,
            'note' => null, 'language' => $lang, 'currency' => $cur, 'timezone' => $tz];
    }
}
