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
        $wallets->mint($owner, Currency::Try, '100.00', 'mint-1');

        [$out, $in] = $wallets->transfer($owner, $child, '40.00', 'move-1', $owner, 'load');

        $this->assertSame(WalletTransactionType::TransferOut, $out->type);
        $this->assertSame(WalletTransactionType::TransferIn, $in->type);
        $this->assertSame($in->id, $out->reference);
        $this->assertSame($out->id, $in->reference);
        $this->assertSame('60.00', $owner->wallets()->where('currency', 'TRY')->first()->balance);
        $this->assertSame('40.00', $child->wallet()->first()->balance);
        $this->assertSame('100.00', bcadd(
            (string) $owner->wallets()->where('currency', 'TRY')->first()->balance,
            (string) $child->wallet()->first()->balance,
            2,
        ));
    }

    public function test_debit_rejects_insufficient_balance_without_a_row(): void
    {
        [$owner] = $this->pair();
        $wallet = $owner->wallets()->where('currency', 'TRY')->first();

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
        $service->mint($owner, Currency::Eur, '30.00', 'mint-eur');
        $service->mint($owner, Currency::Try, '10.00', 'mint-try');

        try {
            $service->transfer($tryAdmin, $eurAdmin, '1.00', 'cross');
            $this->fail('cross currency transfer should fail');
        } catch (WalletException $exception) {
            $this->assertSame('wallet.currency_mismatch', $exception->translationKey);
        }

        $service->transfer($owner, $eurAdmin, '12.50', 'owner-eur', $owner);
        $this->assertSame('17.50', $owner->wallets()->where('currency', 'EUR')->first()->balance);
        $this->assertSame('10.00', $owner->wallets()->where('currency', 'TRY')->first()->balance);
        $this->assertSame('12.50', $eurAdmin->wallet()->first()->balance);
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
        $socket = '/run/mysqld/mysqld.sock';

        if (! file_exists($socket)) {
            $this->markTestSkipped('MariaDB socket is not available.');
        }

        $pdo = new \PDO('mysql:unix_socket='.$socket, 'root', '');
        $pdo->exec('DROP DATABASE IF EXISTS wallet_conc_test');
        $pdo->exec('CREATE DATABASE wallet_conc_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

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
        $check = new \PDO('mysql:unix_socket='.$socket.';dbname=wallet_conc_test', 'root', '');
        $balance = $check->query('SELECT balance FROM wallets WHERE id = '.(int) $walletId)->fetchColumn();
        $debits = $check->query("SELECT COUNT(*) FROM wallet_transactions WHERE type = 'adjustment'")->fetchColumn();
        $lowest = $check->query('SELECT MIN(balance_after) FROM wallet_transactions')->fetchColumn();

        $pdo->exec('DROP DATABASE IF EXISTS wallet_conc_test');

        $this->assertSame(
            '5',
            (string) $debits,
            'codes='.implode(',', $codes).' balance='.$balance.' lowest='.$lowest,
        );
        $this->assertSame(5, $success);
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
        $service->mint($owner, Currency::Eur, '20.00', 'mint-ledger');
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
        app(WalletService::class)->mint($owner, Currency::Try, '4.00', 'verify-mint');

        $this->artisan('wallet:verify')->assertOk();

        Wallet::query()->where('user_id', $owner->id)->where('currency', 'TRY')->update(['balance' => '9.00']);

        $this->artisan('wallet:verify')->assertFailed();
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

        return $env;
    }
}
