<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\WalletProduct;
use App\Enums\WalletTransactionType as T;
use App\Models\CasinoGame;
use App\Models\CasinoProvider;
use App\Models\GameRound;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\Stats\PeriodReport;
use App\Services\WalletProvisioner;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Faz 6: donem raporu (defterden), agac kapsami, saglayici kirilimi, Istanbul gun siniri. */
class ReportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner, $sa1, $sa2, $bayi1, $bayi2, $m1, $m2;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-03 15:00', 'Europe/Istanbul'));

        $owner = User::query()->create([
            'username' => 'owner', 'password' => 'password', 'role' => UserRole::Owner, 'parent_id' => null,
            'path' => '/', 'depth' => 0, 'superadmin_id' => null, 'language' => Language::Tr, 'currency' => Currency::Try,
            'timezone' => 'Europe/Istanbul', 'commission_rate' => 0, 'status' => UserStatus::Active,
        ]);
        $owner->path = '/'.$owner->id.'/';
        $owner->save();
        app(WalletProvisioner::class)->openFor($owner->refresh());

        $h = app(HierarchyService::class);
        $base = ['password' => 'password', 'commission_rate' => 0, 'user_limit' => null, 'note' => null, 'language' => 'tr', 'currency' => 'TRY'];
        $this->owner = $owner->refresh();
        $this->sa1 = $h->create($owner, ['username' => 'sa_one'] + $base)->refresh();
        $this->sa2 = $h->create($owner, ['username' => 'sa_two'] + $base)->refresh();
        $this->bayi1 = $h->create($this->sa1, ['username' => 'bayi_one'] + $base)->refresh();
        $this->bayi2 = $h->create($this->sa2, ['username' => 'bayi_two'] + $base)->refresh();
        $this->m1 = $h->create($this->bayi1, ['username' => 'oyuncu_one'] + $base)->refresh();
        $this->m2 = $h->create($this->bayi2, ['username' => 'oyuncu_two'] + $base)->refresh();

        $w = app(WalletService::class);
        foreach ([[$this->sa1, $this->bayi1, $this->m1], [$this->sa2, $this->bayi2, $this->m2]] as [$sa, $bayi, $m]) {
            $w->transfer($this->owner, $sa, '1000.00', 'f6-a'.$sa->id, $this->owner, null, null, Currency::Try);
            $w->transfer($sa, $bayi, '1000.00', 'f6-b'.$bayi->id, $sa, null, null, Currency::Try);
            $w->transfer($bayi, $m, '1000.00', 'f6-c'.$m->id, $bayi, null, null, Currency::Try);
        }
    }

    public function test_totals_follow_stat_rule_and_tree_scope(): void
    {
        $this->play($this->m1, T::Bet, WalletProduct::Slot, '100.00');
        $this->play($this->m1, T::Win, WalletProduct::Slot, '40.00');
        $this->play($this->m1, T::Bet, WalletProduct::LiveCasino, '50.00');
        $this->play($this->m1, T::Refund, WalletProduct::LiveCasino, '10.00');
        $this->play($this->m2, T::Bet, WalletProduct::Sport, '30.00');

        [$from, $to] = $this->day('2026-10-03');
        $r = app(PeriodReport::class);

        $all = $r->build($this->owner, $from, $to)['totals']['TRY']['all'];
        $this->assertSame(['170.00', '40.00', '130.00', 3, 2], [$all['turnover'], $all['payout'], $all['ggr'], $all['bet_count'], $all['players']]);

        $sa1 = $r->build($this->sa1, $from, $to);
        $this->assertSame('140.00', $sa1['totals']['TRY']['all']['turnover']);
        $this->assertSame('40.00', $sa1['totals']['TRY']['live_casino']['turnover']);
        $this->assertSame(['100.00'], [$sa1['children'][$this->bayi1->id]['TRY']['all']['ggr']]);
        $this->assertArrayNotHasKey('sport', $sa1['totals']['TRY']);

        $owner = $r->build($this->owner, $from, $to)['children'];
        $this->assertSame('30.00', $owner[$this->sa2->id]['TRY']['sport']['ggr']);
    }

    public function test_drill_down_and_access(): void
    {
        $this->play($this->m1, T::Bet, WalletProduct::Slot, '10.00');

        $this->actingAs($this->owner)->get('http://panel.test/panel/reports')->assertOk()
            ->assertSee(__('panel.reports_title'))->assertSee('sa_one')->assertSee('sa_two');
        $this->actingAs($this->owner)->get('http://panel.test/panel/reports?user='.$this->sa1->id)->assertOk()
            ->assertSee('bayi_one')->assertDontSee('bayi_two');
        $this->actingAs($this->sa1)->get('http://panel.test/panel/reports?user='.$this->bayi1->id)->assertOk()
            ->assertSee('oyuncu_one');
        $this->actingAs($this->sa2)->get('http://panel.test/panel/reports?user='.$this->bayi1->id)->assertNotFound();
        $this->actingAs($this->owner)->get('http://panel.test/panel/reports?user='.$this->m1->id)->assertNotFound();
        $this->actingAs($this->bayi1)->get('http://panel.test/panel/reports')->assertNotFound();
    }

    public function test_provider_breakdown_separates_mini_and_maps_refunds(): void
    {
        $gold = CasinoProvider::query()->create(['code' => 'goldpalace', 'name' => 'GoldPalace', 'status' => 'active', 'is_live' => false]);
        $roma = CasinoProvider::query()->create(['code' => 'romaspin', 'name' => 'RomaSpin', 'status' => 'active', 'is_live' => false]);
        $slot = $this->game($gold, 'Sweet', 'pp', 'slot', false);
        $mini = $this->game($roma, 'Aviator', 'mini-spribe', 'mini', false);
        $live = $this->game($roma, 'Roulette', 'casino-evolution', 'live', true);

        $this->play($this->m1, T::Bet, WalletProduct::Slot, '20.00', 'goldpalace', $slot);
        $this->play($this->m1, T::Win, WalletProduct::Slot, '5.00', 'goldpalace', $slot);
        $this->play($this->m1, T::Bet, WalletProduct::Slot, '8.00', 'romaspin', $mini);
        $this->play($this->m1, T::Bet, WalletProduct::LiveCasino, '50.00', 'romaspin', $live);
        $this->play($this->m1, T::Refund, WalletProduct::LiveCasino, '50.00', 'romaspin', $live, ':cancel');

        [$from, $to] = $this->day('2026-10-03');
        $rows = collect(app(PeriodReport::class)->providers($this->owner, $from, $to))->keyBy(fn ($r) => $r['provider'].'|'.$r['category']);

        $this->assertSame(['15.00', 1], [$rows['goldpalace|slot']['ggr'], $rows['goldpalace|slot']['bet_count']]);
        $this->assertSame(['8.00', 'mini-spribe'], [$rows['romaspin|mini']['turnover'], $rows['romaspin|mini']['vendor']]);
        $this->assertSame('0.00', $rows['romaspin|live']['turnover']); // iade ayni oyuna eslendi

        $this->actingAs($this->owner)->get('http://panel.test/panel/reports?tab=providers')->assertOk()
            ->assertSee('RomaSpin')->assertSee(__('panel.reports_category_mini'));
    }

    public function test_istanbul_day_boundary(): void
    {
        $this->travelTo(Carbon::parse('2026-10-03 00:30', 'Europe/Istanbul')); // UTC 2 Ekim 21:30
        $this->play($this->m1, T::Bet, WalletProduct::Slot, '12.00');

        $r = app(PeriodReport::class);
        $this->assertSame('12.00', $r->build($this->owner, ...$this->day('2026-10-03'))['totals']['TRY']['all']['turnover']);
        $this->assertSame([], $r->build($this->owner, ...$this->day('2026-10-02'))['totals']);
    }

    public function test_periods_and_custom_range_render(): void
    {
        foreach (['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month'] as $p) {
            $this->actingAs($this->owner)->get('http://panel.test/panel/reports?period='.$p)->assertOk();
        }
        $this->actingAs($this->owner)->get('http://panel.test/panel/reports?period=custom&from=2026-01-01&to=2026-10-03')->assertOk()
            ->assertSee('2026-07-04'); // 92 gun siniri
        $this->actingAs($this->owner)->get('http://panel.test/panel/reports?period=custom&from=bozuk&to=x')->assertOk();
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function day(string $date): array
    {
        $from = Carbon::parse($date, 'Europe/Istanbul')->startOfDay()->utc();

        return [$from, $from->copy()->addDay()];
    }

    private function game(CasinoProvider $p, string $name, string $vendor, string $category, bool $live): CasinoGame
    {
        return CasinoGame::query()->create([
            'provider_id' => $p->id, 'external_id' => 't:'.$name, 'name' => $name, 'vendor' => $vendor,
            'category' => $category, 'is_live' => $live, 'is_active' => true, 'sort_order' => 0, 'is_popular' => false,
        ]);
    }

    private function play(User $m, T $type, WalletProduct $product, string $amount, string $provider = 'tipo', ?CasinoGame $game = null, string $suffix = ''): void
    {
        $txId = 'tx'.(++$this->seq).$suffix;
        $key = $provider.':'.$txId;
        $wallet = $m->wallet()->first();
        $w = app(WalletService::class);
        $tx = $type === T::Bet
            ? $w->debit($wallet, $amount, $type, $product, $key, null, null, null, $m, null)
            : $w->credit($wallet, $amount, $type, $product, $key, null, null, null, $m, null);

        if ($game !== null) {
            GameRound::query()->create([
                'provider' => $provider, 'provider_transaction_id' => $txId, 'round_id' => null, 'user_id' => $m->id,
                'game_id' => $game->id, 'bet' => $type === T::Bet ? $amount : '0.00', 'win' => $type === T::Bet ? '0.00' : $amount,
                'balance_before' => $tx->balance_before, 'amount' => $tx->amount, 'balance_after' => $tx->balance_after,
                'status' => $type->value, 'payload' => [], 'created_at' => now(),
            ]);
        }
    }
}
