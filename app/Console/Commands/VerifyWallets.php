<?php

namespace App\Console\Commands;

use App\Models\Wallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class VerifyWallets extends Command
{
    protected $signature = 'wallet:verify';

    protected $description = 'Check that each wallet balance matches its ledger';

    public function handle(): int
    {
        $problems = [];

        Wallet::query()->orderBy('id')->each(function (Wallet $wallet) use (&$problems): void {
            $expected = '0.00';
            $sum = '0.00';

            foreach ($wallet->transactions()->orderBy('created_at')->orderBy('id')->cursor() as $transaction) {
                $before = bcadd((string) $transaction->balance_before, '0', 2);
                $amount = bcadd((string) $transaction->amount, '0', 2);
                $after = bcadd((string) $transaction->balance_after, '0', 2);

                if (bccomp($before, $expected, 2) !== 0) {
                    $problems[] = "wallet {$wallet->id} transaction {$transaction->id}: balance_before {$before} != {$expected}";
                }

                $sum = bcadd($sum, $amount, 2);
                $expected = $after;
            }

            $balance = bcadd((string) $wallet->balance, '0', 2);

            if (bccomp($sum, $balance, 2) !== 0) {
                $problems[] = "wallet {$wallet->id}: sum {$sum} != balance {$balance}";
            }

            if (bccomp($expected, $balance, 2) !== 0) {
                $problems[] = "wallet {$wallet->id}: last balance_after {$expected} != balance {$balance}";
            }
        });

        if ($problems === []) {
            $message = 'wallet:verify ok';
            $this->info($message);
            Log::info($message);

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error($problem);
            Log::error('wallet:verify '.$problem);
        }

        return self::FAILURE;
    }
}
