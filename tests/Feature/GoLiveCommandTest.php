<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GoLiveCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_mode_deletes_nothing(): void
    {
        [$owner] = $this->tree();
        $users = DB::table('users')->count();
        $tx = DB::table('wallet_transactions')->count();

        $this->artisan('platform:go-live')->assertSuccessful();

        $this->assertSame($users, DB::table('users')->count());
        $this->assertSame($tx, DB::table('wallet_transactions')->count());
    }

    public function test_force_leaves_only_a_reset_owner(): void
    {
        [$owner] = $this->tree();
        $this->assertGreaterThan(0, DB::table('wallet_transactions')->count());

        $this->artisan('platform:go-live', ['--force' => true, '--owner-password' => 'yeni-guclu-sifre-2026'])
            ->expectsQuestion('Onay için CANLIYA-AL yazın', 'CANLIYA-AL')
            ->assertSuccessful();

        $this->assertSame([$owner->id], DB::table('users')->pluck('id')->all());
        $this->assertSame(0, DB::table('wallet_transactions')->count());
        $this->assertSame(0, DB::table('activity_logs')->count());
        $this->assertSame(0, DB::table('wallets')->where('user_id', '!=', $owner->id)->count());
        foreach (DB::table('wallets')->where('user_id', $owner->id)->get() as $w) {
            $this->assertSame(0.0, (float) $w->balance);
            $this->assertSame(0, (int) $w->last_sequence);
        }
        // Silme yasağı trigger'ları yerinde
        $this->assertSame(2, DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'wallet_transactions')->count());

        // Yeni kullanıcılar 1000'den başlar
        $sa = app(HierarchyService::class)->create($owner->refresh(), $this->data('yeni-sa'));
        $this->assertGreaterThanOrEqual(1000, $sa->id);

        // Owner yeni şifreyle girer
        $this->post('/login', ['username' => $owner->username, 'password' => 'yeni-guclu-sifre-2026'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($owner);

        $this->artisan('wallet:verify')->assertOk();
    }

    public function test_wrong_confirmation_cancels(): void
    {
        $this->tree();
        $users = DB::table('users')->count();

        $this->artisan('platform:go-live', ['--force' => true, '--owner-password' => 'yeni-guclu-sifre-2026'])
            ->expectsQuestion('Onay için CANLIYA-AL yazın', 'evet')
            ->assertFailed();

        $this->assertSame($users, DB::table('users')->count());
    }

    private function tree(): array
    {
        $owner = User::query()->create([
            'username' => 'owner', 'password' => 'password', 'role' => UserRole::Owner,
            'parent_id' => null, 'path' => '/', 'depth' => 0, 'superadmin_id' => null,
            'language' => Language::Tr, 'currency' => Currency::Try, 'timezone' => 'Europe/Istanbul',
            'commission_rate' => 0, 'status' => UserStatus::Active,
        ]);
        $owner->path = '/'.$owner->id.'/';
        $owner->save();
        $owner->refresh();

        $h = app(HierarchyService::class);
        $w = app(WalletService::class);
        $sa = $h->create($owner, $this->data('sa'));
        $w->transfer($owner, $sa, '500.00', 'go-live-1', $owner);
        $bayi = $h->create($sa, $this->data('bayi'));
        $w->transfer($sa, $bayi, '200.00', 'go-live-2', $sa);
        $uye = $h->create($bayi, $this->data('uye'));
        $w->transfer($bayi, $uye, '50.00', 'go-live-3', $bayi);

        return [$owner, $sa, $bayi, $uye];
    }

    private function data(string $username): array
    {
        return ['username' => $username, 'password' => 'password', 'commission_rate' => 0, 'user_limit' => null,
            'note' => null, 'language' => 'tr', 'currency' => 'TRY', 'timezone' => 'Europe/Istanbul'];
    }
}
