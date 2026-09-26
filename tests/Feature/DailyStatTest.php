<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\WalletProduct;
use App\Enums\WalletTransactionType;
use App\Models\DailyStat;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\HierarchyService;
use App\Services\Stats\DailyStatWriter;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DailyStatTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_reversed_win_matches_the_ledger_and_leaves_ggr_unpaid(): void
    {
        [$superadmin, $bayi, $member] = $this->tree();
        $wallets = app(WalletService::class);
        $day = Carbon::parse('2026-06-01 10:00:00', 'Europe/Istanbul');

        $this->fund($wallets, $member, '1000.00', $day);
        $this->move($wallets, $member, '100.00', WalletTransactionType::Bet, WalletProduct::Sport, $day->copy()->addHour());
        $this->move($wallets, $member, '250.00', WalletTransactionType::Win, WalletProduct::Sport, $day->copy()->addHours(2));
        $this->move($wallets, $member, '250.00', WalletTransactionType::Adjustment, WalletProduct::Sport, $day->copy()->addHours(3));
        $this->move($wallets, $member, '50.00', WalletTransactionType::Adjustment, WalletProduct::Adjustment, $day->copy()->addHours(4));
        $this->move($wallets, $member, '20.00', WalletTransactionType::Bonus, WalletProduct::Bonus, $day->copy()->addHours(5));

        app(DailyStatWriter::class)->rewriteTree($superadmin, '2026-06-01');

        $stat = $this->stat($superadmin, '2026-06-01', 'all');
        $expected = $this->ledger($member, '2026-06-01', 'Europe/Istanbul');

        $this->assertSame($expected['turnover'], $stat->turnover);
        $this->assertSame($expected['payout'], $stat->payout);
        $this->assertSame('100.00', $stat->turnover);
        $this->assertSame('0.00', $stat->payout);
        $this->assertSame('100.00', $stat->ggr);
        $this->assertSame('100.00', $this->stat($bayi, '2026-06-01', 'all')->turnover);
    }

    public function test_a_refund_is_booked_on_the_movement_day_and_turnover_can_be_negative(): void
    {
        [$superadmin, $bayi, $member] = $this->tree();
        $wallets = app(WalletService::class);
        $first = Carbon::parse('2026-06-01 12:00:00', 'Europe/Istanbul');
        $second = Carbon::parse('2026-06-02 12:00:00', 'Europe/Istanbul');

        $this->fund($wallets, $member, '500.00', $first->copy()->subHour());
        $this->move($wallets, $member, '80.00', WalletTransactionType::Bet, WalletProduct::Sport, $first);
        $this->move($wallets, $member, '80.00', WalletTransactionType::Refund, WalletProduct::Sport, $second);

        $writer = app(DailyStatWriter::class);
        $writer->rewriteTree($superadmin, '2026-06-01');
        $writer->rewriteTree($superadmin, '2026-06-02');

        $this->assertSame('80.00', $this->stat($superadmin, '2026-06-01', 'sport')->turnover);
        $this->assertSame('-80.00', $this->stat($superadmin, '2026-06-02', 'sport')->turnover);
        $this->assertSame('80.00', $this->stat($bayi, '2026-06-01', 'sport')->turnover);
    }

    public function test_a_correction_on_a_closed_day_recomputes_that_day(): void
    {
        [$superadmin, , $member] = $this->tree();
        $wallets = app(WalletService::class);
        $at = Carbon::parse('2026-06-01 12:00:00', 'Europe/Istanbul');

        $this->fund($wallets, $member, '500.00', $at->copy()->subHour());
        $this->move($wallets, $member, '40.00', WalletTransactionType::Bet, WalletProduct::Sport, $at);

        $writer = app(DailyStatWriter::class);
        $writer->close($superadmin, '2026-06-01');
        $this->assertNotNull($this->stat($superadmin, '2026-06-01', 'all')->closed_at);
        $this->assertSame('40.00', $this->stat($superadmin, '2026-06-01', 'all')->turnover);

        $this->move($wallets, $member, '40.00', WalletTransactionType::Refund, WalletProduct::Sport, $at->copy()->addHour());

        $stat = $this->stat($superadmin, '2026-06-01', 'all');
        $this->assertSame('0.00', $stat->turnover);
        $this->assertNotNull($stat->closed_at);
    }

    public function test_refresh_covers_today_and_yesterday_and_backfill_matches_the_writer(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-26 15:00:00', 'Europe/Istanbul'));
        [$superadmin, , $member] = $this->tree();
        $wallets = app(WalletService::class);
        $this->fund($wallets, $member, '500.00', now('Europe/Istanbul')->subDays(5));
        $this->move($wallets, $member, '10.00', WalletTransactionType::Bet, WalletProduct::Slot, now('Europe/Istanbul')->subDays(3));
        $this->move($wallets, $member, '15.00', WalletTransactionType::Bet, WalletProduct::Slot, now('Europe/Istanbul')->subDay());
        $this->move($wallets, $member, '25.00', WalletTransactionType::Bet, WalletProduct::Slot, now('Europe/Istanbul'));

        Artisan::call('sport:stats-refresh');

        $this->assertNotNull($this->stat($superadmin, '2026-09-26', 'all'));
        $this->assertSame('15.00', $this->stat($superadmin, '2026-09-25', 'slot')->turnover);
        $this->assertNull(DailyStat::query()->where('user_id', $superadmin->id)->whereDate('stat_date', '2026-09-23')->first());

        $direct = app(DailyStatWriter::class);
        $direct->rewriteTree($superadmin, '2026-09-23');
        $written = $this->stat($superadmin, '2026-09-23', 'slot')->turnover;

        Artisan::call('sport:stats-backfill', ['--from' => '2026-09-23', '--to' => '2026-09-26']);

        $this->assertSame($written, $this->stat($superadmin, '2026-09-23', 'slot')->turnover);
        $this->assertNotNull($this->stat($superadmin, '2026-09-23', 'all')->closed_at);
        $this->assertNull($this->stat($superadmin, '2026-09-26', 'all')->closed_at);
        $this->assertNull($this->stat($superadmin, '2026-09-25', 'all')->closed_at);
    }

    public function test_player_counts_are_not_doubled_across_products_or_bayi_rows(): void
    {
        [$superadmin, $bayi, $member] = $this->tree();
        $secondBayi = app(HierarchyService::class)->create($superadmin, $this->payload('bayi-2'));
        $second = app(HierarchyService::class)->create($secondBayi, $this->payload('uye-2'));
        $created = Carbon::parse('2026-06-01 09:00:00', 'Europe/Istanbul');
        $member->forceFill(['created_at' => $created])->save();
        $second->forceFill(['created_at' => $created->copy()->subDays(10)])->save();

        $wallets = app(WalletService::class);
        $this->fund($wallets, $member, '500.00', $created->copy()->addHour());
        $this->fund($wallets, $second, '500.00', $created->copy()->subDays(9));
        $this->move($wallets, $member, '10.00', WalletTransactionType::Bet, WalletProduct::Sport, $created->copy()->addHours(2));
        $this->move($wallets, $member, '10.00', WalletTransactionType::Bet, WalletProduct::Slot, $created->copy()->addHours(3));
        $this->move($wallets, $second, '30.00', WalletTransactionType::Bet, WalletProduct::Sport, $created->copy()->addHours(4));

        app(DailyStatWriter::class)->rewriteTree($superadmin, '2026-06-01');

        $all = $this->stat($superadmin, '2026-06-01', 'all');
        $this->assertSame('50.00', $all->turnover);
        $this->assertSame(2, $all->active_players);
        $this->assertSame(1, $all->new_players);
        $this->assertSame(0, $this->stat($superadmin, '2026-06-01', 'sport')->new_players);
        $this->assertSame(1, $this->stat($superadmin, '2026-06-01', 'slot')->active_players);
        $this->assertSame('20.00', $this->stat($bayi, '2026-06-01', 'all')->turnover);
        $this->assertSame('30.00', $this->stat($secondBayi, '2026-06-01', 'all')->turnover);
        $this->assertSame(1, $this->stat($bayi, '2026-06-01', 'all')->new_players);

        $doubled = bcadd($all->turnover, $this->stat($bayi, '2026-06-01', 'all')->turnover, 2);
        $doubled = bcadd($doubled, $this->stat($secondBayi, '2026-06-01', 'all')->turnover, 2);
        $this->assertSame('100.00', $doubled);
        $this->assertNotSame($doubled, $all->turnover);
    }

    private function stat(User $user, string $date, string $product): DailyStat
    {
        return DailyStat::query()
            ->where('user_id', $user->id)
            ->whereDate('stat_date', $date)
            ->where('product', $product)
            ->firstOrFail();
    }

    /**
     * @return array{turnover: string, payout: string}
     */
    private function ledger(User $member, string $date, string $timezone): array
    {
        $start = Carbon::parse($date, $timezone)->startOfDay()->utc();
        $end = Carbon::parse($date, $timezone)->addDay()->startOfDay()->utc();
        $turnover = '0.00';
        $payout = '0.00';

        $rows = WalletTransaction::query()
            ->where('user_id', $member->id)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->get();

        foreach ($rows as $row) {
            $product = $row->product->value;
            if (! in_array($product, ['sport', 'slot', 'live_casino'], true)) {
                continue;
            }
            $amount = bcadd((string) $row->amount, '0', 2);
            if ($row->type === WalletTransactionType::Bet || $row->type === WalletTransactionType::Refund) {
                $turnover = bcsub($turnover, $amount, 2);
            }
            if ($row->type === WalletTransactionType::Win || ($row->type === WalletTransactionType::Adjustment && in_array($product, ['sport', 'slot', 'live_casino'], true))) {
                $payout = bcadd($payout, $amount, 2);
            }
        }

        return ['turnover' => $turnover, 'payout' => $payout];
    }

    private function fund(WalletService $wallets, User $member, string $amount, Carbon $at): void
    {
        $owner = User::query()->where('role', UserRole::Owner)->firstOrFail();
        $wallets->debit(
            $wallets->walletFor($owner, $member->currency),
            $amount,
            WalletTransactionType::TransferOut,
            WalletProduct::Transfer,
            'fund:'.$member->id.':'.$at->timestamp.':out',
            null,
            $member->id,
            null,
            $owner,
            null,
            null,
            $at->copy()->subMinute(),
        );
        $wallets->credit(
            $wallets->walletFor($member, $member->currency),
            $amount,
            WalletTransactionType::TransferIn,
            WalletProduct::Transfer,
            'fund:'.$member->id.':'.$at->timestamp.':in',
            null,
            $owner->id,
            null,
            $owner,
            null,
            null,
            $at,
        );
    }

    private function move(WalletService $wallets, User $member, string $amount, WalletTransactionType $type, WalletProduct $product, Carbon $at): void
    {
        $wallet = $wallets->walletFor($member, $member->currency);
        $key = $type->value.':'.$product->value.':'.$member->id.':'.$at->timestamp;
        if ($type === WalletTransactionType::Bet || $type === WalletTransactionType::Adjustment && $product === WalletProduct::Sport) {
            $wallets->debit($wallet, $amount, $type, $product, $key, null, null, null, null, null, null, $at);

            return;
        }

        $wallets->credit($wallet, $amount, $type, $product, $key, null, null, null, null, null, null, $at);
    }

    /**
     * @return array{0: User, 1: User, 2: User}
     */
    private function tree(): array
    {
        $owner = $this->owner();
        $hierarchy = app(HierarchyService::class);
        $superadmin = $hierarchy->create($owner, $this->payload('sa', [
            'language' => 'tr',
            'currency' => 'TRY',
            'timezone' => 'Europe/Istanbul',
        ]));
        $bayi = $hierarchy->create($superadmin, $this->payload('bayi'));
        $member = $hierarchy->create($bayi, $this->payload('uye'));
        $member->forceFill(['created_at' => Carbon::parse('2026-01-01 00:00:00')])->save();

        return [$superadmin, $bayi, $member];
    }

    private function owner(): User
    {
        $owner = User::query()->create([
            'username' => 'owner',
            'password' => 'password',
            'role' => UserRole::Owner,
            'parent_id' => null,
            'path' => '/',
            'depth' => 0,
            'superadmin_id' => null,
            'language' => Language::Tr,
            'currency' => Currency::Try,
            'timezone' => 'UTC',
            'commission_rate' => 0,
            'status' => UserStatus::Active,
        ]);
        $owner->path = '/'.$owner->id.'/';
        $owner->save();

        return $owner->refresh();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(string $username, array $extra = []): array
    {
        return array_merge([
            'username' => $username,
            'password' => 'password',
            'commission_rate' => 0,
            'user_limit' => null,
            'note' => null,
        ], $extra);
    }
}
