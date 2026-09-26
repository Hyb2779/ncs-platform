<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\WalletProduct;
use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\HierarchyService;
use App\Services\WalletException;
use App\Services\WalletService;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WalletTest extends TestCase
{
    use RefreshDatabase;

    public function test_transfer_writes_two_rows_and_conserves_money(): void
    {
        [$owner, $child] = $this->pair();
        $wallets = app(WalletService::class);

        [$out, $in] = $wallets->transfer($owner, $child, '40.00', 'move-1', $owner, 'load');

        $this->assertSame(WalletTransactionType::TransferOut, $out->type);
        $this->assertSame(WalletTransactionType::TransferIn, $in->type);
        $this->assertSame($in->id, $out->reference);
        $this->assertSame($out->id, $in->reference);
        $this->assertSame('-40.00', $owner->wallets()->where('currency', 'TRY')->first()->balance);
        $this->assertSame('40.00', $child->wallet()->first()->balance);
        $this->assertSame('0.00', bcadd(
            (string) $owner->wallets()->where('currency', 'TRY')->first()->balance,
            (string) $child->wallet()->first()->balance,
            2,
        ));
    }

    public function test_debit_rejects_insufficient_balance_without_a_row(): void
    {
        [$owner, $child] = $this->pair();
        $wallet = $child->wallet()->first();

        try {
            app(WalletService::class)->debit($wallet, '1.00', WalletTransactionType::Adjustment, WalletProduct::Adjustment, 'nope');
            $this->fail('debit should fail');
        } catch (WalletException $exception) {
            $this->assertSame('wallet.insufficient_balance', $exception->translationKey);
        }

        $this->assertSame(0, WalletTransaction::query()->count());
        $this->assertSame('0.00', $wallet->refresh()->balance);
    }

    public function test_same_idempotency_key_creates_one_row(): void
    {
        [$owner] = $this->pair();
        $wallet = $owner->wallets()->where('currency', 'TRY')->first();
        $service = app(WalletService::class);
        $first = $service->credit($wallet, '5.00', WalletTransactionType::Adjustment, WalletProduct::Adjustment, 'same-key');
        $second = $service->credit($wallet, '5.00', WalletTransactionType::Adjustment, WalletProduct::Adjustment, 'same-key');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, WalletTransaction::query()->count());
        $this->assertSame('5.00', $wallet->refresh()->balance);
    }

    public function test_occurred_at_sets_created_at_outside_production(): void
    {
        [$owner] = $this->pair();
        $wallet = $owner->wallets()->where('currency', 'TRY')->first();
        $at = \Illuminate\Support\Carbon::parse('2026-09-01 12:00:00', 'UTC');

        $row = app(WalletService::class)->credit(
            $wallet,
            '5.00',
            WalletTransactionType::Adjustment,
            WalletProduct::Adjustment,
            'occurred-ok',
            occurredAt: $at,
        );

        $this->assertSame('2026-09-01 12:00:00', $row->created_at->utc()->toDateTimeString());
    }

    public function test_occurred_at_is_rejected_in_production(): void
    {
        $this->app['env'] = 'production';
        [$owner] = $this->pair();
        $wallet = $owner->wallets()->where('currency', 'TRY')->first();

        try {
            app(WalletService::class)->credit(
                $wallet,
                '5.00',
                WalletTransactionType::Adjustment,
                WalletProduct::Adjustment,
                'occurred-no',
                occurredAt: now()->subDay(),
            );
            $this->fail('occurred_at should be rejected in production');
        } catch (WalletException $exception) {
            $this->assertSame('wallet.occurred_at_forbidden', $exception->translationKey);
        }

        $this->assertSame(0, WalletTransaction::query()->count());
        $this->assertSame('0.00', $wallet->refresh()->balance);
    }

    public function test_lock_retry_keeps_one_row_for_the_same_key(): void
    {
        [$owner] = $this->pair();
        $wallet = $owner->wallets()->where('currency', 'TRY')->first();
        $service = app(WalletService::class);
        $retry = new \ReflectionMethod($service, 'retry');
        $runs = 0;

        $retry->invoke($service, function () use (&$runs, $service, $wallet) {
            $runs++;

            if ($runs === 1) {
                throw new QueryException(
                    'sqlite',
                    'insert into wallet_transactions',
                    [],
                    new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction', '40001'),
                );
            }

            return $service->credit($wallet, '4.00', WalletTransactionType::Adjustment, WalletProduct::Adjustment, 'retry-key');
        });
        $service->credit($wallet, '4.00', WalletTransactionType::Adjustment, WalletProduct::Adjustment, 'retry-key');

        $this->assertSame(2, $runs);
        $this->assertSame(1, WalletTransaction::query()->where('idempotency_key', 'retry-key')->count());
        $this->assertSame('4.00', $wallet->refresh()->balance);
    }

    public function test_lock_wait_is_retried_and_other_errors_are_not(): void
    {
        $service = app(WalletService::class);
        $retry = new \ReflectionMethod($service, 'retry');
        $runs = 0;

        $retry->invoke($service, function () use (&$runs) {
            $runs++;

            if ($runs === 1) {
                throw new QueryException(
                    'sqlite',
                    'select 1',
                    [],
                    new \PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction', 1205),
                );
            }

            return 'ok';
        });

        $this->assertSame(2, $runs);

        $runs = 0;

        try {
            $retry->invoke($service, function () use (&$runs) {
                $runs++;

                throw new \RuntimeException('other');
            });
            $this->fail('A non-lock error should escape.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('other', $exception->getMessage());
        }

        $this->assertSame(1, $runs);
    }

    public function test_agent_cannot_fund_another_branch(): void
    {
        $owner = $this->owner('owner-branch');
        $hierarchy = app(HierarchyService::class);
        $saA = $hierarchy->create($owner, $this->locale('sa-a', 'TRY'));
        $saB = $hierarchy->create($owner, $this->locale('sa-b', 'USD'));
        $bayiA = $hierarchy->create($saA, $this->locale('bayi-a'));
        $memberB = $hierarchy->create($hierarchy->create($saB, $this->locale('bayi-b')), $this->locale('uye-b'));

        $this->actingAs($bayiA)
            ->post('/panel/users/'.$memberB->id.'/balance', [
                'amount' => '1.00',
                'direction' => 'add',
                'note' => 'no',
                'idempotency_key' => '11111111-1111-1111-1111-111111111111',
            ])
            ->assertNotFound();
    }

    public function test_currency_mismatch_is_rejected_and_owner_uses_matching_wallet(): void
    {
        $owner = $this->owner('owner-fx');
        $hierarchy = app(HierarchyService::class);
        $tryAdmin = $hierarchy->create($owner, $this->locale('sa-try', 'TRY'));
        $eurAdmin = $hierarchy->create($owner, $this->locale('sa-eur', 'EUR'));
        $service = app(WalletService::class);

        try {
            $service->transfer($tryAdmin, $eurAdmin, '1.00', 'cross');
            $this->fail('cross currency transfer should fail');
        } catch (WalletException $exception) {
            $this->assertSame('wallet.currency_mismatch', $exception->translationKey);
        }

        $service->transfer($owner, $eurAdmin, '12.50', 'owner-eur', $owner);
        $this->assertSame('-12.50', $owner->wallets()->where('currency', 'EUR')->first()->balance);
        $this->assertSame('0.00', $owner->wallets()->where('currency', 'TRY')->first()->balance);
        $this->assertSame('12.50', $eurAdmin->wallet()->first()->balance);
    }

    public function test_owner_add_and_remove_use_the_child_currency_wallet(): void
    {
        $owner = $this->owner('owner-fx-adjust');
        $hierarchy = app(HierarchyService::class);
        $eurAdmin = $hierarchy->create($owner, $this->locale('sa-eur-adj', 'EUR', 'de'));
        $usdAdmin = $hierarchy->create($owner, $this->locale('sa-usd-adj', 'USD', 'en'));
        Carbon::setTestNow(Carbon::parse('2026-09-25 12:00:00'));

        foreach ([
            ['user' => $eurAdmin, 'code' => 'EUR', 'add' => '44444444-4444-4444-8444-444444444401', 'remove' => '55555555-5555-4555-8555-555555555501'],
            ['user' => $usdAdmin, 'code' => 'USD', 'add' => '44444444-4444-4444-8444-444444444402', 'remove' => '55555555-5555-4555-8555-555555555502'],
        ] as $case) {
            $this->actingAs($owner)
                ->post('/panel/users/'.$case['user']->id.'/balance', [
                    'amount' => '5000.00',
                    'direction' => 'add',
                    'note' => 'load',
                    'idempotency_key' => $case['add'],
                ])
                ->assertRedirect()
                ->assertSessionHasNoErrors();

            Carbon::setTestNow(now()->addSecond());

            $this->actingAs($owner)
                ->post('/panel/users/'.$case['user']->id.'/balance', [
                    'amount' => '1500.00',
                    'direction' => 'remove',
                    'note' => 'unload',
                    'idempotency_key' => $case['remove'],
                ])
                ->assertRedirect()
                ->assertSessionHasNoErrors();

            Carbon::setTestNow(now()->addSecond());

            $owner->unsetRelation('wallets');
            $this->assertSame('-3500.00', $owner->wallets()->where('currency', $case['code'])->first()->balance);
            $this->assertSame('3500.00', $case['user']->wallet()->first()->fresh()->balance);
        }

        $this->assertSame('0.00', $owner->wallets()->where('currency', 'TRY')->first()->balance);
        $this->assertSame('-3500.00', $owner->wallets()->where('currency', 'EUR')->first()->balance);
        $this->assertSame('-3500.00', $owner->wallets()->where('currency', 'USD')->first()->balance);
        $this->artisan('wallet:verify')->assertOk();
    }

    public function test_transaction_update_and_delete_are_rejected_by_the_database(): void
    {
        [$owner] = $this->pair();
        $wallet = $owner->wallets()->where('currency', 'TRY')->first();
        $row = app(WalletService::class)->credit($wallet, '3.00', WalletTransactionType::Adjustment, WalletProduct::Adjustment, 'imm');

        try {
            DB::statement('UPDATE wallet_transactions SET note = ? WHERE id = ?', ['changed', $row->id]);
            $this->fail('update should fail');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        try {
            DB::statement('DELETE FROM wallet_transactions WHERE id = ?', [$row->id]);
            $this->fail('delete should fail');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        $this->assertSame(1, WalletTransaction::query()->count());
    }

    public function test_parallel_debits_never_go_negative(): void
    {
        $username = $this->dbEnv('DB_USERNAME');
        $this->assertNotSame('', (string) $username);
        $this->assertNotSame('root', $username);

        $setup = $this->worker(['setup']);
        $this->assertSame(0, $setup['code'], $setup['output']);
        $walletId = trim($setup['output']);
        $processes = [];

        for ($i = 0; $i < 10; $i++) {
            $processes[] = $this->openWorker(['debit', $walletId, 'debit-'.$i]);
        }

        $codes = [];
        $output = '';

        foreach ($processes as &$process) {
            $codes[] = $this->finishWorker($process);
            $output .= $process['output'];
        }
        unset($process);

        $success = substr_count($output, 'RESULT 0');
        $rejected = substr_count($output, 'RESULT 2');
        $check = $this->concPdo();
        $balance = $check->query('SELECT balance FROM wallets WHERE id = '.(int) $walletId)->fetchColumn();
        $debits = $check->query("SELECT COUNT(*) FROM wallet_transactions WHERE type = 'adjustment'")->fetchColumn();
        $lowest = $check->query('SELECT MIN(balance_after) FROM wallet_transactions')->fetchColumn();

        $this->assertSame(
            '5',
            (string) $debits,
            'codes='.implode(',', $codes).' balance='.$balance.' lowest='.$lowest,
        );
        $this->assertCount(10, $codes);
        $this->assertSame(5, $success);
        $this->assertSame(5, $rejected, 'codes='.implode(',', $codes).' '.$output);
        $this->assertSame('0.00', number_format((float) $balance, 2, '.', ''));
        $this->assertGreaterThanOrEqual(0, (float) $lowest);
    }

    public function test_ledger_hides_ancestor_names(): void
    {
        $owner = $this->owner('owner-ledger');
        $hierarchy = app(HierarchyService::class);
        $superadmin = $hierarchy->create($owner, $this->locale('sa-ledger', 'EUR', 'de'));
        $bayi = $hierarchy->create($superadmin, $this->locale('bayi-ledger'));
        $service = app(WalletService::class);
        $service->transfer($owner, $superadmin, '20.00', 'to-sa', $owner, 'down');
        $service->transfer($superadmin, $bayi, '8.00', 'to-bayi', $superadmin, 'down');

        $this->actingAs($bayi)
            ->get('/panel/transactions')
            ->assertOk()
            ->assertDontSee($owner->username)
            ->assertDontSee($superadmin->username)
            ->assertSee(__('wallet.upper_account'));

        $this->actingAs($superadmin)
            ->get('/panel/transactions?user='.$owner->id)
            ->assertNotFound();
    }

    public function test_wallet_verify_exit_codes(): void
    {
        [$owner] = $this->pair();
        $wallet = $owner->wallets()->where('currency', 'TRY')->first();
        app(WalletService::class)->credit($wallet, '4.00', WalletTransactionType::Adjustment, WalletProduct::Adjustment, 'verify-credit');

        $this->artisan('wallet:verify')->assertOk();

        Wallet::query()->where('user_id', $owner->id)->where('currency', 'TRY')->update(['balance' => '9.00']);

        $this->artisan('wallet:verify')->assertFailed();
    }

    public function test_owner_can_fund_superadmin_from_zero_and_go_negative(): void
    {
        [$owner, $child] = $this->pair();
        app(WalletService::class)->transfer($owner, $child, '5000.00', 'load-5000', $owner);

        $this->assertSame('-5000.00', $owner->wallets()->where('currency', 'TRY')->first()->balance);
        $this->assertSame('5000.00', $child->wallet()->first()->balance);
        $this->assertTrue((bool) $owner->wallets()->where('currency', 'TRY')->first()->allow_negative);
        $this->assertFalse((bool) $child->wallet()->first()->allow_negative);
    }

    public function test_superadmin_and_bayi_cannot_go_negative(): void
    {
        $owner = $this->owner('owner-neg');
        $hierarchy = app(HierarchyService::class);
        $superadmin = $hierarchy->create($owner, $this->locale('sa-neg', 'TRY'));
        $bayi = $hierarchy->create($superadmin, $this->locale('bayi-neg'));
        $service = app(WalletService::class);

        try {
            $service->transfer($superadmin, $bayi, '1.00', 'sa-empty', $superadmin);
            $this->fail('superadmin should not go negative');
        } catch (WalletException $exception) {
            $this->assertSame('wallet.insufficient_balance', $exception->translationKey);
        }

        $service->transfer($owner, $superadmin, '10.00', 'fund-sa', $owner);
        $service->transfer($superadmin, $bayi, '10.00', 'fund-bayi', $superadmin);

        try {
            $service->transfer($bayi, $superadmin, '11.00', 'bayi-over', $bayi);
            $this->fail('bayi should not go negative');
        } catch (WalletException $exception) {
            $this->assertSame('wallet.insufficient_balance', $exception->translationKey);
        }

        $this->assertSame('10.00', $bayi->wallet()->first()->balance);
        $this->assertSame('0.00', $superadmin->wallet()->first()->balance);
    }

    public function test_allow_negative_cannot_be_changed_via_a_form(): void
    {
        [$owner, $child] = $this->pair();
        $ownerWallet = $owner->wallets()->where('currency', 'TRY')->first();
        $childWallet = $child->wallet()->first();

        $this->assertTrue((bool) $ownerWallet->allow_negative);
        $this->assertFalse((bool) $childWallet->allow_negative);

        $this->actingAs($owner)
            ->post('/panel/users/'.$child->id.'/balance', [
                'amount' => '1.00',
                'direction' => 'add',
                'note' => 'x',
                'idempotency_key' => '11111111-1111-1111-1111-111111111112',
                'allow_negative' => '1',
            ])
            ->assertRedirect();

        $this->actingAs($owner)
            ->put('/panel/users/'.$child->id, [
                'status' => 'active',
                'commission_rate' => '0',
                'allow_negative' => '1',
            ])
            ->assertRedirect();

        $this->assertFalse((bool) $childWallet->fresh()->allow_negative);
        $this->assertTrue((bool) $ownerWallet->fresh()->allow_negative);

        $ownerWallet->fill(['allow_negative' => false])->save();
        $this->assertTrue((bool) $ownerWallet->fresh()->allow_negative);
    }

    public function test_owner_screens_hide_minus_and_empty_ledger_shows_zero_cards(): void
    {
        [$owner, $child] = $this->pair();

        $this->actingAs($owner)
            ->get('/panel/transactions')
            ->assertOk()
            ->assertSee(__('wallet.added'))
            ->assertSee(__('wallet.removed'))
            ->assertSee(__('wallet.difference'))
            ->assertSee(Money::format('0.00', Currency::Try), false);

        app(WalletService::class)->transfer($owner, $child, '5000.00', 'ui-load', $owner);

        $this->actingAs($owner)
            ->get('/panel')
            ->assertOk()
            ->assertSee(__('wallet.distributed_credit'))
            ->assertSee(Money::format('5000.00', Currency::Try), false)
            ->assertDontSee('-5.000', false)
            ->assertDontSee('-5000', false);

        $this->actingAs($owner)
            ->get('/panel/transactions')
            ->assertOk()
            ->assertSee(__('wallet.given'))
            ->assertSee(Money::format('5000.00', Currency::Try), false)
            ->assertDontSee('-5.000', false);
    }

    public function test_ledger_collapses_transfers_and_summarizes_from_the_viewer(): void
    {
        $owner = $this->owner('owner-ledger-sum');
        $hierarchy = app(HierarchyService::class);
        $child = $hierarchy->create($owner, $this->locale('sa-ledger-sum', 'TRY'));
        $bayi = $hierarchy->create($child, $this->locale('bayi-ledger-sum'));
        $service = app(WalletService::class);
        $service->transfer($owner, $child, '10000.00', 'sum-load', $owner, 'Test kredisi');
        $service->transfer($child, $owner, '2000.00', 'sum-back', $owner, 'Geri çekim');
        $service->transfer($child, $bayi, '500.00', 'sum-down', $child, 'İç transfer');

        $page = $this->actingAs($owner)->get('/panel/transactions');
        $page->assertOk();
        $html = $page->getContent();
        $load = $owner->username.' → '.$child->username;
        $back = $child->username.' → '.$owner->username;

        $this->assertSame(2, substr_count($html, $load));
        $this->assertSame(2, substr_count($html, $back));
        $page->assertDontSee($owner->username.' › '.$owner->username, false);
        $page->assertSee('Test kredisi', false);
        $page->assertSee('Geri çekim', false);
        $page->assertSee(Money::format('10000.00', Currency::Try), false);
        $page->assertSee(Money::format('2000.00', Currency::Try), false);
        $page->assertSee(Money::format('8000.00', Currency::Try), false);
        $page->assertSee('text-emerald-800', false);
        $page->assertSee('text-red-700', false);
        $page->assertSee(__('wallet.given'), false);
        $page->assertSee(__('wallet.taken_back'), false);

        $own = $this->actingAs($owner)->get('/panel/transactions?user=self');
        $own->assertOk();
        $own->assertDontSee($child->username.' → '.$bayi->username, false);
        $own->assertSee($load, false);

        $this->actingAs($owner)
            ->post('/panel/users/'.$child->id.'/balance', [
                'amount' => '25.00',
                'direction' => 'add',
                'note' => 'Form notu',
                'idempotency_key' => '66666666-6666-4666-8666-666666666601',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('Form notu', WalletTransaction::query()->where('note', 'Form notu')->value('note'));
        $this->actingAs($owner)->get('/panel/transactions')->assertSee('Form notu', false);
    }

    public function test_ledger_never_reveals_ancestor_balances(): void
    {
        $owner = $this->owner('owner-hide-bal');
        $hierarchy = app(HierarchyService::class);
        $superadmin = $hierarchy->create($owner, $this->locale('sa-hide', 'TRY'));
        $bayi = $hierarchy->create($superadmin, $this->locale('bayi-hide'));
        $uye = $hierarchy->create($bayi, $this->locale('uye-hide'));
        $service = app(WalletService::class);
        $service->transfer($owner, $superadmin, '8000.00', 'hide-fund', $owner);
        $service->transfer($superadmin, $bayi, '1000.00', 'hide-down', $superadmin);
        $service->transfer($bayi, $superadmin, '200.00', 'hide-back', $bayi);
        $service->transfer($bayi, $uye, '500.00', 'hide-uye', $bayi);
        $service->transfer($uye, $bayi, '200.00', 'hide-uye-back', $uye);

        $upper = __('wallet.upper_account');
        $page = $this->actingAs($bayi)->get('/panel/transactions');
        $page->assertOk();
        $html = $page->getContent();
        $page->assertDontSee('7.000', false);
        $page->assertDontSee('7.200', false);
        $page->assertDontSee('8.000', false);
        $page->assertDontSee($superadmin->username, false);
        $page->assertDontSee($owner->username, false);
        $page->assertSee($upper.' → '.$bayi->username, false);
        $page->assertSee($bayi->username.' → '.$upper, false);
        $page->assertSee($bayi->username.' → '.$uye->username, false);
        $page->assertSee($uye->username.' → '.$bayi->username, false);
        $page->assertSee(Money::format('500.00', Currency::Try), false);
        $page->assertSee(Money::format('200.00', Currency::Try), false);
        $page->assertSee(Money::format('300.00', Currency::Try), false);
        $this->assertLedgerBalances($html);

        $own = $this->actingAs($bayi)->get('/panel/transactions?user=self');
        $own->assertOk();
        $own->assertDontSee('7.000', false);
        $own->assertDontSee('7.200', false);
        $own->assertSee(__('wallet.from_upper'), false);
        $own->assertSee(__('wallet.to_upper'), false);
        $own->assertSee(Money::format('1000.00', Currency::Try), false);
        $own->assertSee(Money::format('200.00', Currency::Try), false);
        $own->assertSee(Money::format('800.00', Currency::Try), false);
        $this->assertLedgerBalances($own->getContent());

        $parentView = $this->actingAs($superadmin)->get('/panel/transactions');
        $parentView->assertOk();
        $parentView->assertDontSee($owner->username, false);
        $parentView->assertDontSee('-8.000', false);
        $this->assertLedgerBalances($parentView->getContent());
    }

    private function assertLedgerBalances(string $html): void
    {
        preg_match_all('/data-before="([^"]+)" data-amount="([^"]+)" data-after="([^"]+)"/', $html, $rows, PREG_SET_ORDER);
        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $this->assertSame(0, bccomp(bcadd($row[1], $row[2], 2), $row[3], 2));
        }
    }

    public function test_balance_form_shows_success_and_error_messages(): void
    {
        [$owner, $child] = $this->pair();

        $this->actingAs($owner)
            ->from('/panel/users')
            ->followingRedirects()
            ->post('/panel/users/'.$child->id.'/balance', [
                'direction' => 'add',
                'idempotency_key' => '22222222-2222-2222-2222-222222222222',
            ])
            ->assertSee(__('wallet.validation.amount_required'));

        $this->actingAs($owner)
            ->from('/panel/users')
            ->followingRedirects()
            ->post('/panel/users/'.$child->id.'/balance', [
                'amount' => '5.00',
                'direction' => 'add',
                'idempotency_key' => '33333333-3333-3333-3333-333333333333',
            ])
            ->assertSee(__('wallet.adjusted'))
            ->assertSee('bg-emerald-50', false);
    }

    public function test_mint_routes_are_removed(): void
    {
        [$owner] = $this->pair();

        $this->actingAs($owner)->get('/panel/mint')->assertNotFound();
        $this->actingAs($owner)->post('/panel/mint', [])->assertNotFound();
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function pair(): array
    {
        $owner = $this->owner('owner-pair-'.uniqid());
        $child = app(HierarchyService::class)->create($owner, $this->locale('sa-'.uniqid(), 'TRY'));

        return [$owner, $child];
    }

    private function owner(string $username): User
    {
        $owner = User::query()->create([
            'username' => $username,
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

        return $owner->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function locale(string $username, string $currency = 'TRY', string $language = 'tr'): array
    {
        return [
            'username' => $username,
            'password' => 'password',
            'commission_rate' => 0,
            'user_limit' => null,
            'note' => null,
            'language' => $language,
            'currency' => $currency,
            'timezone' => 'Europe/Istanbul',
        ];
    }

    /**
     * @param  list<string>  $arguments
     * @return array{code: int, output: string}
     */
    private function worker(array $arguments): array
    {
        $process = $this->openWorker($arguments);

        return ['code' => $this->finishWorker($process), 'output' => $process['output']];
    }

    /**
     * @param  list<string>  $arguments
     * @return array{process: resource, pipes: array<int, resource>, output: string}
     */
    private function openWorker(array $arguments): array
    {
        $command = array_merge([PHP_BINARY, base_path('tests/Support/wallet_worker.php')], $arguments);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $this->workerEnv());

        return ['process' => $process, 'pipes' => $pipes, 'output' => ''];
    }

    /**
     * @param  array{process: resource, pipes: array<int, resource>, output: string}  $process
     */
    private function finishWorker(array &$process): int
    {
        $process['output'] = stream_get_contents($process['pipes'][1]).stream_get_contents($process['pipes'][2]);
        fclose($process['pipes'][1]);
        fclose($process['pipes'][2]);

        return proc_close($process['process']);
    }

    /**
     * @return array<string, string>
     */
    private function workerEnv(): array
    {
        $env = [];

        foreach (array_merge($_SERVER, $_ENV) as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $env[$key] = $value;
            }
        }

        foreach (['DB_USERNAME', 'DB_PASSWORD', 'DB_HOST', 'DB_PORT'] as $key) {
            $value = $this->dbEnv($key);

            if ($value !== null) {
                $env[$key] = $value;
            }
        }

        $env['DB_CONNECTION'] = 'mysql';
        $env['DB_DATABASE'] = 'wallet_conc_test';
        $env['DB_SOCKET'] = '';
        unset($env['DB_URL']);

        return $env;
    }

    private function dbEnv(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return null;
        }

        return (string) $value;
    }

    private function concPdo(): \PDO
    {
        $host = $this->dbEnv('DB_HOST') ?: '127.0.0.1';
        $port = $this->dbEnv('DB_PORT') ?: '3306';

        return new \PDO(
            'mysql:host='.$host.';port='.$port.';dbname=wallet_conc_test',
            (string) $this->dbEnv('DB_USERNAME'),
            (string) $this->dbEnv('DB_PASSWORD'),
        );
    }
}
