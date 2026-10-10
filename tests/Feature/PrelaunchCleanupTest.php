<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\WalletProvisioner;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PrelaunchCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'local';
    }

    public function test_cleanup_refuses_outside_local(): void
    {
        $root = $this->owner('root_owner', null);
        $sub = $this->owner('volkan', $root);
        $this->app['env'] = 'production';

        $this->artisan('wegas:prelaunch-cleanup', ['--force' => true])->assertFailed();

        $this->assertDatabaseHas('users', ['id' => $sub->id]);
    }

    public function test_force_removes_only_the_second_owner_tree_and_restores_triggers(): void
    {
        $root = $this->owner('root_owner', null);
        $sub = $this->owner('volkan', $root);
        $h = app(HierarchyService::class);
        $data = fn (string $name) => [
            'username' => $name, 'password' => 'password', 'commission_rate' => 0,
            'user_limit' => null, 'note' => null, 'language' => 'tr', 'currency' => 'TRY',
        ];
        $rootSa = $h->create($root, $data('root_sa'));
        $subSa = $h->create($sub, $data('sub_sa'));
        $w = app(WalletService::class);
        $w->transfer($root, $rootSa, '10.00', 'keep-ledger', $root, null, null, Currency::Try);
        $w->transfer($root, $sub, '10.00', 'fund-sub', $root, null, null, Currency::Try);
        $w->transfer($sub, $subSa, '10.00', 'drop-ledger', $sub, null, null, Currency::Try);
        DB::table('game_blocks')->insert([
            ['superadmin_id' => null, 'scope' => 'provider', 'value' => 'keep', 'created_by' => $sub->id, 'created_at' => now(), 'updated_at' => now()],
            ['superadmin_id' => $subSa->id, 'scope' => 'provider', 'value' => 'drop', 'created_by' => $sub->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->artisan('wegas:prelaunch-cleanup', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseHas('users', ['username' => 'volkan']);

        $this->artisan('wegas:prelaunch-cleanup', ['--force' => true])->assertSuccessful();

        $this->assertDatabaseMissing('users', ['username' => 'volkan']);
        $this->assertDatabaseMissing('users', ['username' => 'sub_sa']);
        $this->assertDatabaseHas('users', ['username' => 'root_owner']);
        $this->assertDatabaseHas('users', ['username' => 'root_sa']);
        $this->assertDatabaseHas('game_blocks', ['value' => 'keep', 'created_by' => $root->id, 'superadmin_id' => null]);
        $this->assertDatabaseMissing('game_blocks', ['value' => 'drop']);
        $this->assertDatabaseHas('platform_settings', ['key' => 'credit_fee_rate']);

        $this->assertSame($rootSa->id, (int) $rootSa->fresh()->superadmin_id);
        $this->assertSame($root->id, (int) $rootSa->fresh()->parent_id);

        $kept = DB::table('wallet_transactions')->where('user_id', $root->id)->value('id');
        $this->assertNotNull($kept);
        try {
            DB::table('wallet_transactions')->where('id', $kept)->delete();
            $this->fail('wallet transaction delete should stay rejected');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }
    }

    public function test_superadmin_self_reference_is_cleared_only_inside_the_deleted_set(): void
    {
        $root = $this->owner('root_owner', null);
        $sub = $this->owner('volkan', $root);
        $h = app(HierarchyService::class);
        $data = fn (string $name) => [
            'username' => $name, 'password' => 'password', 'commission_rate' => 0,
            'user_limit' => null, 'note' => null, 'language' => 'tr', 'currency' => 'TRY',
        ];
        $rootSa = $h->create($root, $data('root_sa'));
        $subSa = $h->create($sub, $data('dealer'));
        $bayi = $h->create($subSa, $data('shop'));
        $uye = $h->create($bayi, $data('dene21'));

        $subSa->refresh();
        $this->assertSame($subSa->id, (int) $subSa->superadmin_id);
        $this->assertSame($subSa->id, (int) $bayi->fresh()->superadmin_id);
        $this->assertSame($subSa->id, (int) $uye->fresh()->superadmin_id);
        $this->assertSame($rootSa->id, (int) $rootSa->fresh()->superadmin_id);

        $updates = [];
        DB::listen(function ($query) use (&$updates): void {
            if (preg_match('/update\s+["`]?users["`]?/i', $query->sql)) {
                $updates[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
            }
        });

        $this->artisan('wegas:prelaunch-cleanup', ['--force' => true])->assertSuccessful();

        $this->assertDatabaseMissing('users', ['id' => $sub->id]);
        $this->assertDatabaseMissing('users', ['id' => $subSa->id]);
        $this->assertDatabaseMissing('users', ['id' => $bayi->id]);
        $this->assertDatabaseMissing('users', ['id' => $uye->id]);
        $this->assertSame($rootSa->id, (int) $rootSa->fresh()->superadmin_id);
        $this->assertSame($root->id, (int) $rootSa->fresh()->parent_id);

        $detach = collect($updates)->first(function (array $update): bool {
            return str_contains($update['sql'], 'superadmin_id') && str_contains($update['sql'], 'parent_id');
        });
        $this->assertNotNull($detach);
        $this->assertNotContains($root->id, $detach['bindings']);
        $this->assertNotContains($rootSa->id, $detach['bindings']);
        foreach ([$sub->id, $subSa->id, $bayi->id, $uye->id] as $id) {
            $this->assertContains($id, $detach['bindings']);
        }
    }

    private function owner(string $username, ?User $parent): User
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
