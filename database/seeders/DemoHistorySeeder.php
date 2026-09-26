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
            $index = (int) $start->diffInDays($day);
            $factor = $this->factor($index, $member->id);
            $sportStake = bcmul('35.00', $factor, 2);
            $slotStake = bcmul('50.00', $factor, 2);
            $liveStake = bcmul('15.00', $factor, 2);
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

        $this->move($wallets, $owner, $superadmin, $amount, $prefix.':sa', $memberAt);
        $this->move($wallets, $superadmin, $bayi, $amount, $prefix.':bayi', $memberAt);
        $this->move($wallets, $bayi, $member, $amount, $prefix.':member', $memberAt);
    }

    private function factor(int $index, int $memberId): string
    {
        $wave = sin($index * 0.45) * 0.22 + sin($index * 0.17 + 0.8) * 0.10;
        $nudge = (($memberId % 5) - 2) * 0.02;
        $factor = 1 + $wave + $nudge;

        return number_format(max($factor, 0.60), 2, '.', '');
    }

    private function move(WalletService $wallets, User $from, User $to, string $amount, string $key, ?Carbon $creditAt = null): void
    {
        $currency = $to->currency;
        $fromWallet = $wallets->walletFor($from, $from->role === UserRole::Owner ? $currency : $from->currency);
        $toWallet = $wallets->walletFor($to, $currency);
        $when = $creditAt ?? now();
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
            $this->notBeforeLast($fromWallet->id, $when),
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
            $this->notBeforeLast($toWallet->id, $when),
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
