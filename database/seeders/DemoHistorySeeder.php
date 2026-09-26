<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\WalletProduct;
use App\Enums\WalletTransactionType;
use App\Models\Coupon;
use App\Models\CouponSelection;
use App\Models\SportFixture;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\HierarchyService;
use App\Services\Sport\CouponCalculator;
use App\Services\Sport\SelectionEvaluator;
use App\Services\WalletService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

class DemoHistorySeeder extends Seeder
{
    private const API_BASE = 9000000000000;

    private const FIXTURES_PER_DAY = 8;

    /** @var array<string, array<string, string>> base odds per market and outcome */
    private const ODDS = [
        '1X2' => ['home' => '2.10', 'draw' => '3.30', 'away' => '3.20'],
        'DC' => ['home_draw' => '1.35', 'home_away' => '1.30', 'draw_away' => '1.55'],
        'OU15' => ['over' => '1.30', 'under' => '3.20'],
        'OU25' => ['over' => '1.85', 'under' => '1.95'],
        'OU35' => ['over' => '2.90', 'under' => '1.40'],
        'BTTS' => ['yes' => '1.80', 'no' => '1.95'],
        'HT1X2' => ['home' => '2.80', 'draw' => '2.05', 'away' => '3.60'],
    ];

    /** @var list<array{league_id: int, home_team_id: int, away_team_id: int}>|null */
    private ?array $pool = null;

    /** @var array<string, list<SportFixture>> */
    private array $fixtures = [];

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
        $foreign = $wallet !== null && $wallet->transactions()->where('idempotency_key', 'not like', 'demo-stat:%')->where('idempotency_key', 'not like', 'coupon:%')->exists();
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
            $dayKey = 'demo-stat:'.$member->id.':'.$day->toDateString();

            $fixtures = $this->fixturesFor($superadmin, $day, $timezone);
            if ($fixtures === []) {
                // No synced fixtures to build from: fall back to a plain ledger bet.
                $sport = $this->post($wallets, $member, $sportStake, WalletTransactionType::Bet, WalletProduct::Sport, $dayKey.':sport:bet', $at);
                if ($sport === null) {
                    continue;
                }
                if ($mode % 3 === 0) {
                    $this->post($wallets, $member, bcmul($sportStake, '1.80', 2), WalletTransactionType::Win, WalletProduct::Sport, $dayKey.':sport:win', $at->copy()->addMinute());
                }
            } else {
                $this->coupon($wallets, $member, $fixtures, $sportStake, $dayKey, $day, $timezone);
            }

            $this->post($wallets, $member, $slotStake, WalletTransactionType::Bet, WalletProduct::Slot, $dayKey.':slot:bet', $at->copy()->addMinutes(2));
            $this->post($wallets, $member, $liveStake, WalletTransactionType::Bet, WalletProduct::LiveCasino, $dayKey.':live:bet', $at->copy()->addMinutes(3));
            // Daily return varies, but averages ~0.95 on slots and ~0.97 on live casino.
            $slotWin = bcmul($slotStake, $this->ratio($dayKey.':slot', 0.95), 2);
            $liveWin = bcmul($liveStake, $this->ratio($dayKey.':live', 0.97), 2);
            if (bccomp($slotWin, '0', 2) === 1) {
                $this->post($wallets, $member, $slotWin, WalletTransactionType::Win, WalletProduct::Slot, $dayKey.':slot:win', $at->copy()->addMinutes(4));
            }
            if (bccomp($liveWin, '0', 2) === 1) {
                $this->post($wallets, $member, $liveWin, WalletTransactionType::Win, WalletProduct::LiveCasino, $dayKey.':live:win', $at->copy()->addMinutes(5));
            }
        }
    }

    /**
     * @param  list<SportFixture>  $fixtures
     */
    private function coupon(WalletService $wallets, User $member, array $fixtures, string $stake, string $dayKey, Carbon $day, string $timezone): void
    {
        $betKey = $dayKey.':sport:bet';
        if (WalletTransaction::query()->where('idempotency_key', $betKey)->exists()) {
            return;
        }

        $seed = $this->hash($betKey);
        $legs = $seed % 10 < 6 ? 1 : 2 + ($seed % 2);
        $type = $legs === 1 ? 'single' : 'combo';
        $markets = array_keys(self::ODDS);
        $offset = $seed % count($fixtures);

        $rows = [];
        for ($leg = 0; $leg < $legs; $leg++) {
            $fixture = $fixtures[($offset + $leg * 3) % count($fixtures)];
            $legSeed = $this->hash($betKey.':'.$leg);
            $market = $markets[$legSeed % count($markets)];
            $outcome = $this->pickOutcome($market, $fixture, $legSeed);
            $shown = $this->jitter(self::ODDS[$market][$outcome], $legSeed);
            $rows[] = [
                'fixture' => $fixture,
                'market' => $market,
                'outcome' => $outcome,
                'shown' => $shown,
                'raw' => bcmul($shown, '1.05', 2),
            ];
        }

        $calculator = app(CouponCalculator::class);
        $evaluator = app(SelectionEvaluator::class);
        $odds = array_column($rows, 'shown');
        $placedAt = $day->copy()->setTime(11, 0)->addSeconds($member->id % 50)->utc();
        $settledAt = $day->copy()->setTime(15, 10)->addSeconds($member->id % 50)->utc();

        $coupon = Coupon::query()->create([
            'coupon_no' => $this->number(),
            'user_id' => $member->id,
            'superadmin_id' => $member->superadmin_id,
            'client_key' => 'demo-coupon:'.$betKey,
            'type' => $type,
            'stake' => $stake,
            'total_odds' => $calculator->total($type, $odds),
            'potential_win' => $calculator->payout($type, $stake, $odds),
            'status' => 'pending',
            'accept_odds_change' => false,
            'ip' => '127.0.0.1',
            'device' => 'demo',
            'placed_at' => $placedAt,
        ]);
        $coupon->forceFill(['created_at' => $placedAt, 'updated_at' => $placedAt])->save();

        $statuses = [];
        foreach ($rows as $row) {
            /** @var SportFixture $fixture */
            $fixture = $row['fixture'];
            $status = $evaluator->evaluate(
                $row['market'],
                $row['outcome'],
                $fixture->ft_home,
                $fixture->ft_away,
                $fixture->ht_home === null ? null : (int) $fixture->ht_home,
                $fixture->ht_away === null ? null : (int) $fixture->ht_away,
            ) ?? 'void';
            $statuses[] = $status;

            $selection = CouponSelection::query()->create([
                'coupon_id' => $coupon->id,
                'fixture_id' => $fixture->id,
                'market_code' => $row['market'],
                'outcome' => $row['outcome'],
                'odds' => $row['shown'],
                'raw_odds' => $row['raw'],
                'kickoff' => $fixture->starts_at,
                'kickoff_at' => $fixture->starts_at,
                'status' => $status,
                'placed_status' => 'NS',
                'placed_minute' => null,
                'placed_home' => null,
                'placed_away' => null,
            ]);
            $selection->forceFill(['settled_at' => $settledAt, 'created_at' => $placedAt, 'updated_at' => $settledAt])->save();
        }

        $this->post($wallets, $member, $stake, WalletTransactionType::Bet, WalletProduct::Sport, $betKey, $placedAt, $coupon->coupon_no, $member);

        $allVoid = ! in_array('won', $statuses, true) && ! in_array('lost', $statuses, true);
        if (in_array('lost', $statuses, true)) {
            $final = 'lost';
        } elseif ($allVoid) {
            $final = 'void';
        } else {
            $final = 'won';
        }

        if ($final !== 'lost') {
            $paidOdds = [];
            foreach ($rows as $i => $row) {
                $paidOdds[] = $statuses[$i] === 'void' ? '1.00' : $row['shown'];
            }
            $payout = $allVoid ? bcadd($stake, '0', 2) : $calculator->payout($type, $stake, $paidOdds);
            $coupon->refresh();
            $revision = (int) ($coupon->settlement_revision ?? 0);
            $this->post(
                $wallets,
                $member,
                $payout,
                $allVoid ? WalletTransactionType::Refund : WalletTransactionType::Win,
                WalletProduct::Sport,
                'coupon:'.$coupon->id.':settle:'.$revision,
                $settledAt,
                $coupon->coupon_no,
            );
            $coupon->total_odds = $calculator->total($type, $paidOdds);
            $coupon->potential_win = $payout;
        }

        $coupon->status = $final;
        $coupon->settled_at = $settledAt;
        $coupon->updated_at = $settledAt;
        $coupon->save();
    }

    private function pickOutcome(string $market, SportFixture $fixture, int $seed): string
    {
        $evaluator = app(SelectionEvaluator::class);
        $won = [];
        $lost = [];
        foreach (array_keys(self::ODDS[$market]) as $outcome) {
            $result = $evaluator->evaluate(
                $market,
                $outcome,
                $fixture->ft_home,
                $fixture->ft_away,
                $fixture->ht_home === null ? null : (int) $fixture->ht_home,
                $fixture->ht_away === null ? null : (int) $fixture->ht_away,
            );
            if ($result === 'won') {
                $won[] = $outcome;
            } elseif ($result === 'lost') {
                $lost[] = $outcome;
            }
        }

        // Hit chance follows the price (0.90 / odds): about a 10% house edge per leg.
        $hit = false;
        if ($won !== []) {
            $avg = array_sum(array_map(fn (string $o): float => (float) self::ODDS[$market][$o], $won)) / count($won);
            $hit = ($seed % 1000) < (int) round(1000 * 0.90 / $avg);
        }
        $list = $hit ? $won : $lost;
        if ($list === []) {
            $list = array_keys(self::ODDS[$market]);
        }

        return $list[$seed % count($list)];
    }

    /**
     * @return list<SportFixture>
     */
    private function fixturesFor(User $superadmin, Carbon $day, string $timezone): array
    {
        $cacheKey = $superadmin->id.':'.$day->toDateString();
        if (isset($this->fixtures[$cacheKey])) {
            return $this->fixtures[$cacheKey];
        }

        $pool = $this->pool();
        if ($pool === []) {
            return $this->fixtures[$cacheKey] = [];
        }

        $ymd = (int) $day->format('Ymd');
        $list = [];
        for ($k = 0; $k < self::FIXTURES_PER_DAY; $k++) {
            $apiId = self::API_BASE + $superadmin->id * 1000000000 + $ymd * 10 + $k;
            $fixture = SportFixture::query()->where('api_id', $apiId)->first();
            if ($fixture === null) {
                $seed = $this->hash('fixture:'.$apiId);
                $pair = $pool[$seed % count($pool)];
                $ftHome = $seed % 4;
                $ftAway = intdiv($seed, 7) % 3;
                $htHome = min($ftHome, intdiv($seed, 11) % 2);
                $htAway = min($ftAway, intdiv($seed, 13) % 2);
                $kickoff = $day->copy()->setTime(12, 0)->addMinutes($k * 5)->utc();
                $max = (int) SportFixture::query()->max('bulletin_code');

                $fixture = new SportFixture;
                $fixture->forceFill([
                    'api_id' => $apiId,
                    'league_id' => $pair['league_id'],
                    'home_team_id' => $pair['home_team_id'],
                    'away_team_id' => $pair['away_team_id'],
                    'starts_at' => $kickoff,
                    'played_at' => $kickoff,
                    'status' => 'FT',
                    'elapsed' => 90,
                    'score_home' => (string) $ftHome,
                    'score_away' => (string) $ftAway,
                    'ht_home' => (string) $htHome,
                    'ht_away' => (string) $htAway,
                    'ft_home' => $ftHome,
                    'ft_away' => $ftAway,
                    'settled_at' => $kickoff->copy()->addHours(2),
                    'score_source' => 'manual',
                    'bulletin_code' => $max === 0 ? 1001 : $max + 1,
                    'created_at' => $kickoff->copy()->subDay(),
                    'updated_at' => $kickoff->copy()->addHours(2),
                ])->save();
            }
            $list[] = $fixture;
        }

        return $this->fixtures[$cacheKey] = $list;
    }

    /**
     * Real league and team pairs taken from synced (non-demo) fixtures.
     *
     * @return list<array{league_id: int, home_team_id: int, away_team_id: int}>
     */
    private function pool(): array
    {
        if ($this->pool !== null) {
            return $this->pool;
        }

        return $this->pool = SportFixture::query()
            ->where('api_id', '<', self::API_BASE)
            ->orderBy('id')
            ->get(['league_id', 'home_team_id', 'away_team_id'])
            ->map(fn (SportFixture $f): array => [
                'league_id' => (int) $f->league_id,
                'home_team_id' => (int) $f->home_team_id,
                'away_team_id' => (int) $f->away_team_id,
            ])
            ->values()
            ->all();
    }

    private function jitter(string $base, int $seed): string
    {
        $pct = (($seed % 21) - 10) / 100;
        $value = max(1.05, round((float) $base * (1 + $pct), 2));

        return number_format($value, 2, '.', '');
    }

    private function ratio(string $key, float $center): string
    {
        $spread = (($this->hash($key) % 61) - 30) / 100;

        return number_format(max(0.0, $center + $spread), 2, '.', '');
    }

    private function hash(string $value): int
    {
        return (int) sprintf('%u', crc32($value));
    }

    private function number(): string
    {
        do {
            $number = (string) random_int(10000000, 99999999);
        } while (Coupon::query()->where('coupon_no', $number)->exists());

        return $number;
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

    private function post(WalletService $wallets, User $member, string $amount, WalletTransactionType $type, WalletProduct $product, string $key, Carbon $at, ?string $reference = null, ?User $actor = null): ?WalletTransaction
    {
        if (WalletTransaction::query()->where('idempotency_key', $key)->exists()) {
            return WalletTransaction::query()->where('idempotency_key', $key)->first();
        }

        $wallet = $wallets->walletFor($member, $member->currency);
        $at = $this->notBeforeLast($wallet->id, $at);

        if ($type === WalletTransactionType::Bet) {
            return $wallets->debit($wallet, $amount, $type, $product, $key, $reference, null, null, $actor, null, null, $at);
        }

        return $wallets->credit($wallet, $amount, $type, $product, $key, $reference, null, null, $actor, null, null, $at);
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
