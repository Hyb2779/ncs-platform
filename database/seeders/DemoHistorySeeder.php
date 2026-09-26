<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\WalletProduct;
use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\HierarchyService;
use App\Services\WalletService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

class DemoHistorySeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new \RuntimeException('DemoHistorySeeder cannot run in production.');
        }

        $wallets = app(WalletService::class);
        $hierarchy = app(HierarchyService::class);

        User::query()->where('role', UserRole::Superadmin)->where('username', 'like', 'demo-%')->orderBy('id')->each(function (User $superadmin) use ($wallets, $hierarchy): void {
            $bayi = $superadmin->children()->where('role', UserRole::Bayi)->orderBy('id')->first();
            if ($bayi === null) {
                return;
            }

            foreach ([10, 20] as $daysAgo) {
                $username = $superadmin->username.'-uye-extra-'.(int) ($daysAgo / 10);
                $member = User::query()->where('username', $username)->first();
                if ($member === null) {
                    $member = $hierarchy->create($bayi, [
                        'username' => $username,
                        'password' => 'password',
                        'commission_rate' => 0,
                        'user_limit' => null,
                        'note' => null,
                    ]);
                    $member->forceFill([
                        'created_at' => now($superadmin->timezone)->subDays($daysAgo)->setTime(9, 0),
                    ])->save();
                }
            }

            $members = User::query()
                ->where('superadmin_id', $superadmin->id)
                ->where('role', UserRole::Uye)
                ->orderBy('id')
                ->get();

            foreach ($members as $member) {
                $this->seedMember($wallets, $superadmin, $member);
                $this->balanceMix($wallets, $superadmin, $member);
            }
        });

        Artisan::call('sport:stats-backfill');
    }

    private function seedMember(WalletService $wallets, User $superadmin, User $member): void
    {
        $timezone = $superadmin->timezone ?: 'UTC';
        $end = now($timezone)->startOfDay();
        $start = $end->copy()->subDays(29);
        $done = 'demo-stat:'.$member->id.':'.$end->toDateString().':sport:bet';

        if (WalletTransaction::query()->where('idempotency_key', $done)->exists()) {
            return;
        }

        $wallet = $member->wallet()->first();
        $foreign = $wallet !== null && $wallet->transactions()->where('idempotency_key', 'not like', 'demo-stat:%')->exists();
        if ($foreign) {
            return;
        }

        if ($wallet === null || ! $wallet->transactions()->exists()) {
            $this->fund($wallets, $member, $start->copy()->subDay()->setTime(8, 0)->utc(), '20000.00', 'demo-stat:fund:'.$member->id);
        }

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $at = $day->copy()->setTime(15, 0)->addSeconds($member->id % 50)->utc();
            $scale = bcadd('1.00', bcdiv((string) ($day->day % 5), '10', 2), 2);
            $sportStake = bcmul('35.00', $scale, 2);
            $slotStake = bcmul('50.00', $scale, 2);
            $liveStake = bcmul('15.00', $scale, 2);
            $mode = ($day->day + $member->id) % 10;
            $sport = $this->post($wallets, $member, $sportStake, WalletTransactionType::Bet, WalletProduct::Sport, 'demo-stat:'.$member->id.':'.$day->toDateString().':sport:bet', $at);
            if ($sport === null) {
                continue;
            }
            if ($mode % 3 === 0) {
                $this->post($wallets, $member, bcmul($sportStake, '1.80', 2), WalletTransactionType::Win, WalletProduct::Sport, 'demo-stat:'.$member->id.':'.$day->toDateString().':sport:win', $at->copy()->addMinute());
            }
            $this->post($wallets, $member, $slotStake, WalletTransactionType::Bet, WalletProduct::Slot, 'demo-stat:'.$member->id.':'.$day->toDateString().':slot:bet', $at->copy()->addMinutes(2));
            $this->post($wallets, $member, $liveStake, WalletTransactionType::Bet, WalletProduct::LiveCasino, 'demo-stat:'.$member->id.':'.$day->toDateString().':live:bet', $at->copy()->addMinutes(3));
            if ($mode % 2 === 0) {
                $this->post($wallets, $member, bcmul($slotStake, '0.80', 2), WalletTransactionType::Win, WalletProduct::Slot, 'demo-stat:'.$member->id.':'.$day->toDateString().':slot:win', $at->copy()->addMinutes(4));
                $this->post($wallets, $member, bcmul($liveStake, '0.90', 2), WalletTransactionType::Win, WalletProduct::LiveCasino, 'demo-stat:'.$member->id.':'.$day->toDateString().':live:win', $at->copy()->addMinutes(5));
            }
        }
    }

    private function fund(WalletService $wallets, User $member, Carbon $memberAt, string $amount, string $prefix): void
    {
        $bayi = $member->parent;
        $superadmin = $bayi?->parent;
        $owner = $superadmin?->parent;
        if ($bayi === null || $superadmin === null || $owner === null) {
            return;
        }

        $this->move($wallets, $owner, $superadmin, $amount, $prefix.':sa');
        $this->move($wallets, $superadmin, $bayi, $amount, $prefix.':bayi');
        $this->move($wallets, $bayi, $member, $amount, $prefix.':member', $memberAt);
    }

    private function balanceMix(WalletService $wallets, User $superadmin, User $member): void
    {
        $zone = $superadmin->timezone ?: 'UTC';
        $from = now($zone)->startOfDay()->subDays(29)->utc();
        $until = now($zone)->addDay()->startOfDay()->utc();
        $totals = ['sport' => '0.00', 'slot' => '0.00', 'live_casino' => '0.00'];
        $rows = WalletTransaction::query()
            ->where('user_id', $member->id)
            ->whereIn('type', [WalletTransactionType::Bet, WalletTransactionType::Refund])
            ->whereIn('product', [WalletProduct::Sport, WalletProduct::Slot, WalletProduct::LiveCasino])
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $until)
            ->get(['product', 'amount']);

        foreach ($rows as $row) {
            $key = $row->product instanceof WalletProduct ? $row->product->value : (string) $row->product;
            if (! array_key_exists($key, $totals)) {
                continue;
            }
            $totals[$key] = bcsub($totals[$key], (string) $row->amount, 2);
        }

        foreach ($totals as $key => $value) {
            if (bccomp($value, '0', 2) === -1) {
                $totals[$key] = '0.00';
            }
        }

        $adds = $this->mixAdds($totals['sport'], $totals['slot'], $totals['live_casino']);
        $need = '0.00';
        foreach ($adds as $add) {
            $need = bcadd($need, $add, 2);
        }
        if (bccomp($need, '0', 2) !== 1) {
            return;
        }

        $wallet = $wallets->walletFor($member, $member->currency);
        if (bccomp((string) $wallet->balance, $need, 2) === -1) {
            $gap = bcadd(bcsub($need, (string) $wallet->balance, 2), '1.00', 2);
            $this->fund($wallets, $member, now(), $gap, 'demo-stat:fund:'.$member->id.':mix');
        }

        $products = [
            'sport' => WalletProduct::Sport,
            'slot' => WalletProduct::Slot,
            'live_casino' => WalletProduct::LiveCasino,
        ];
        foreach ($products as $key => $product) {
            if (bccomp($adds[$key], '0', 2) !== 1) {
                continue;
            }
            $this->post($wallets, $member, $adds[$key], WalletTransactionType::Bet, $product, 'demo-stat:'.$member->id.':mix:v2:'.$key, now());
        }
    }

    /**
     * @return array{sport: string, slot: string, live_casino: string}
     */
    private function mixAdds(string $sport, string $slot, string $live): array
    {
        $bases = [
            bccomp($sport, '0', 2) === 1 ? bcdiv($sport, '0.35', 4) : '0',
            bccomp($slot, '0', 2) === 1 ? bcdiv($slot, '0.50', 4) : '0',
            bccomp($live, '0', 2) === 1 ? bcdiv($live, '0.15', 4) : '0',
        ];
        $target = '0';
        foreach ($bases as $base) {
            if (bccomp($base, $target, 4) === 1) {
                $target = $base;
            }
        }

        if (bccomp($target, '0', 4) !== 1) {
            return ['sport' => '35.00', 'slot' => '50.00', 'live_casino' => '15.00'];
        }

        $want = [
            'sport' => bcmul($target, '0.35', 2),
            'slot' => bcmul($target, '0.50', 2),
            'live_casino' => bcmul($target, '0.15', 2),
        ];
        $current = ['sport' => $sport, 'slot' => $slot, 'live_casino' => $live];
        $adds = [];
        foreach ($want as $key => $amount) {
            $gap = bcsub($amount, $current[$key], 2);
            $adds[$key] = bccomp($gap, '0', 2) === 1 ? $gap : '0.00';
        }

        return $adds;
    }

    private function move(WalletService $wallets, User $from, User $to, string $amount, string $key, ?Carbon $creditAt = null): void
    {
        $currency = $to->currency;
        $fromWallet = $wallets->walletFor($from, $from->role === UserRole::Owner ? $currency : $from->currency);
        $toWallet = $wallets->walletFor($to, $currency);
        $wallets->debit(
            $fromWallet,
            $amount,
            WalletTransactionType::TransferOut,
            WalletProduct::Transfer,
            $key.':out',
            null,
            $to->id,
            null,
            $from,
            null,
            null,
            $this->afterLast($fromWallet->id),
        );
        $wallets->credit(
            $toWallet,
            $amount,
            WalletTransactionType::TransferIn,
            WalletProduct::Transfer,
            $key.':in',
            null,
            $from->id,
            null,
            $from,
            null,
            null,
            $creditAt ?? $this->afterLast($toWallet->id),
        );
    }

    private function post(WalletService $wallets, User $member, string $amount, WalletTransactionType $type, WalletProduct $product, string $key, Carbon $at): ?WalletTransaction
    {
        if (WalletTransaction::query()->where('idempotency_key', $key)->exists()) {
            return WalletTransaction::query()->where('idempotency_key', $key)->first();
        }

        $wallet = $wallets->walletFor($member, $member->currency);
        $at = $this->notBeforeLast($wallet->id, $at);

        if ($type === WalletTransactionType::Bet) {
            return $wallets->debit($wallet, $amount, $type, $product, $key, null, null, null, null, null, null, $at);
        }

        return $wallets->credit($wallet, $amount, $type, $product, $key, null, null, null, null, null, null, $at);
    }

    private function afterLast(int $walletId): Carbon
    {
        $latest = WalletTransaction::query()->where('wallet_id', $walletId)->max('created_at');

        return $latest === null ? now() : Carbon::parse($latest)->addSecond();
    }

    private function notBeforeLast(int $walletId, Carbon $at): Carbon
    {
        $latest = WalletTransaction::query()->where('wallet_id', $walletId)->max('created_at');
        if ($latest === null) {
            return $at;
        }

        $floor = Carbon::parse($latest)->addSecond();

        return $at->gt($floor) ? $at : $floor;
    }
}
