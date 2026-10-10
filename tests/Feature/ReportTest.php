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
use App\Services\Stats\TodaySummary;
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
        // 04.10: bayi kendi raporunu gorur (oyuncu kirilimi), baska bayiyi goremez.
        $this->actingAs($this->bayi1)->get('http://panel.test/panel/reports?period=this_month')->assertOk()->assertSee('oyuncu_one')->assertDontSee('oyuncu_two');
        $this->actingAs($this->bayi1)->get('http://panel.test/panel/reports?user='.$this->bayi2->id)->assertNotFound();
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
        $this->actingAs($this->owner)->get('http://panel.test/panel/reports?period=custom&from=bozuk&to=x')->assertOk()
            ->assertSee('2026-09-29', false);
        $page = $this->actingAs($this->owner)->get('http://panel.test/panel/reports');
        $page->assertOk()
            ->assertSee(__('panel.reports_this_week'), false)
            ->assertSee('2026-09-29', false)
            ->assertSee('2026-10-05', false)
            ->assertDontSee('<select', false)
            ->assertDontSee(__('panel.reports_period_yesterday'), false);
        $this->actingAs($this->owner)->get('http://panel.test/panel/member-movements')->assertOk()
            ->assertSee(__('panel.reports_period_yesterday'), false);
    }

    public function test_today_summary_and_dashboard_block(): void
    {
        $this->play($this->m1, T::Bet, WalletProduct::Sport, '50.00');
        $this->play($this->m1, T::Win, WalletProduct::Sport, '80.00');
        $this->play($this->m2, T::Bet, WalletProduct::Slot, '20.00');

        $s = app(TodaySummary::class)->for($this->owner, 'TRY');
        $this->assertSame([1, '50.00', 1, '80.00'], [$s['sport_bets']['count'], $s['sport_bets']['amount'], $s['sport_wins']['count'], $s['sport_wins']['amount']]);
        $this->assertSame(['-30.00', '20.00', '-10.00', 2], [$s['sport_ggr'], $s['casino_turnover'], $s['today_ggr'], $s['players']]);
        $this->assertSame(['oyuncu_one', '30.00'], [$s['top_winner']['name'], $s['top_winner']['net']]);
        $this->assertSame(['oyuncu_two', '20.00'], [$s['top_loser']['name'], $s['top_loser']['net']]);

        $sa2 = app(TodaySummary::class)->for($this->sa2, 'TRY');
        $this->assertSame(['20.00', null], [$sa2['casino_turnover'], $sa2['top_winner']]); // sadece kendi agaci

        $this->actingAs($this->owner)->get('http://panel.test/panel')->assertOk()->assertSee(__('panel.today_title'))->assertSee(__('panel.today_coupons', ['count' => 1]));
        $this->actingAs($this->sa1)->get('http://panel.test/panel')->assertOk()->assertSee(__('panel.week_top_winner'))->assertSee('oyuncu_one');
    }

    public function test_json_and_export_omit_ggr_for_superadmin_and_bayi(): void
    {
        $this->play($this->m1, T::Bet, WalletProduct::Slot, '100.00');
        $this->play($this->m1, T::Win, WalletProduct::Slot, '40.00');
        $urls = [
            'http://panel.test/panel',
            'http://panel.test/panel?export=1',
            'http://panel.test/panel/reports?period=custom&from=2020-01-01&to=2026-10-03',
            'http://panel.test/panel/reports?export=1&period=custom&from=2020-01-01&to=2026-10-03',
            'http://panel.test/panel/users/'.$this->m1->id,
            'http://panel.test/panel/users/'.$this->m1->id.'?export=1',
        ];

        foreach ([$this->sa1, $this->bayi1] as $actor) {
            foreach ($urls as $url) {
                $response = $this->actingAs($actor)->getJson($url);
                $response->assertOk();
                $this->assertSame([], $this->ggrKeys($response->getContent(), str_contains($url, '/reports')), $actor->username.' '.$url);
            }
        }

        $ownerReport = $this->actingAs($this->owner)->getJson('http://panel.test/panel/reports?period=custom&from=2020-01-01&to=2026-10-03');
        $ownerReport->assertOk();
        $this->assertNotNull($ownerReport->json('rows.0.general'));
        $this->assertNotNull($ownerReport->json('totals.commission'));
        $this->assertNotSame([], $this->ggrKeys($ownerReport->getContent(), true));
        $this->actingAs($this->owner)->getJson('http://panel.test/panel')->assertOk();
        $this->assertNotSame([], $this->ggrKeys($this->actingAs($this->owner)->getJson('http://panel.test/panel')->getContent(), false));
    }

    /** @return list<string> */
    private function ggrKeys(string $json, bool $houseNet): array
    {
        $found = [];
        $walk = function (mixed $node) use (&$walk, &$found, $houseNet): void {
            if (! is_array($node)) {
                return;
            }
            foreach ($node as $key => $value) {
                $name = strtolower((string) $key);
                $house = in_array($name, ['general', 'commission'], true) || ($houseNet && $name === 'net');
                if ($house || str_contains($name, 'ggr') || str_contains($name, 'net_gaming') || str_contains($name, 'house_edge')) {
                    $found[] = $name;
                }
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk(json_decode($json, true));

        return $found;
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

    public function test_settlement_rows_show_credit_from_above_only(): void
    {
        $from = \Illuminate\Support\Carbon::parse('2020-01-01')->utc();
        $to = now()->addDay()->utc();
        $svc = app(\App\Services\Stats\SettlementReport::class);

        $saRows = collect($svc->build($this->sa1, $from, $to, 'TRY')['rows']);
        $bayi = $saRows->first(fn ($x) => $x['user']->id === $this->bayi1->id);
        $this->assertNotNull($bayi);
        $this->assertSame('1000.00', $bayi['given']);
        $this->assertSame('0.00', $bayi['withdrawn']);
        $this->assertFalse($saRows->contains(fn ($x) => $x['user']->id === $this->bayi2->id));

        $member = collect($svc->build($this->bayi1, $from, $to, 'TRY')['rows'])->first(fn ($x) => $x['user']->id === $this->m1->id);
        $this->assertNotNull($member);
        $this->assertSame('1000.00', $member['given']);
        $this->assertSame(bcsub(bcsub($member['staked'], $member['won'], 2), $member['pending'], 2), $member['general']);
    }

    public function test_detail_week_is_tuesday_to_monday_and_other_reports_stay_on_monday(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 12:00', 'Europe/Istanbul'));
        $detail = \App\Support\ReportPeriod::resolveDetail(\Illuminate\Http\Request::create('/panel/reports'), 'Europe/Istanbul');
        $this->assertSame(['this_week', '2026-09-29', '2026-10-05'], [$detail[0], $detail[1]->toDateString(), $detail[2]->toDateString()]);

        $this->travelTo(Carbon::parse('2026-10-06 12:00', 'Europe/Istanbul'));
        $tuesday = \App\Support\ReportPeriod::resolveDetail(\Illuminate\Http\Request::create('/panel/reports'), 'Europe/Istanbul');
        $this->assertSame(['2026-10-06', '2026-10-12'], [$tuesday[1]->toDateString(), $tuesday[2]->toDateString()]);

        $kept = \App\Support\ReportPeriod::resolveDetail(\Illuminate\Http\Request::create('/panel/reports', 'GET', [
            'period' => 'today', 'from' => '2026-09-01', 'to' => '2026-10-05',
        ]), 'Europe/Istanbul');
        $this->assertSame(['custom', '2026-09-01', '2026-10-05'], [$kept[0], $kept[1]->toDateString(), $kept[2]->toDateString()]);

        $monday = \App\Support\ReportPeriod::resolve(\Illuminate\Http\Request::create('/panel/member-movements', 'GET', ['period' => 'this_week']), 'Europe/Istanbul');
        $this->assertSame(['2026-10-05', '2026-10-06'], [$monday[1]->toDateString(), $monday[2]->toDateString()]);
    }

    public function test_player_rows_skip_commission_and_dealer_rows_keep_it(): void
    {
        $this->m1->forceFill(['commission_rate' => '1.00'])->save();
        $this->bayi1->forceFill(['commission_rate' => '30.00'])->save();
        app(WalletService::class)->transfer($this->owner, $this->m1, '200.00', 'extra-stake', $this->owner);
        $this->play($this->m1, T::Bet, WalletProduct::Slot, '1140.05');

        $from = Carbon::parse('2020-01-01')->utc();
        $to = now()->addDay()->utc();
        $svc = app(\App\Services\Stats\SettlementReport::class);

        $dealer = collect($svc->build($this->sa1, $from, $to, 'TRY')['rows'])->first(fn ($x) => $x['user']->id === $this->bayi1->id);
        $this->assertSame('1140.05', $dealer['general']);
        $this->assertSame('342.02', $dealer['commission']);
        $this->assertSame('798.03', $dealer['net']);
        $this->assertTrue($dealer['show_commission']);

        $players = $svc->build($this->bayi1, $from, $to, 'TRY');
        $player = collect($players['rows'])->first(fn ($x) => $x['user']->id === $this->m1->id);
        $this->assertFalse($player['show_commission']);
        $this->assertSame('0.00', $player['commission']);
        $this->assertSame($player['general'], $player['net']);
        $this->assertSame('1140.05', $player['net']);
        $this->assertFalse($players['show_commission']);

        $this->actingAs($this->sa1)->get('http://panel.test/panel/reports?user='.$this->bayi1->id.'&period=custom&from=2020-01-01&to=2026-10-03')
            ->assertOk()
            ->assertDontSee('rep-commission', false)
            ->assertDontSee(__('panel.rep_hint'), false)
            ->assertDontSee('%1', false)
            ->assertSee(\App\Support\Money::format('1140.05', Currency::Try), false);
        $this->actingAs($this->sa1)->get('http://panel.test/panel/reports?period=custom&from=2020-01-01&to=2026-10-03')
            ->assertOk()
            ->assertDontSee('rep-commission', false)
            ->assertDontSee(__('panel.rep_hint'), false)
            ->assertDontSee(\App\Support\Money::format('342.02', Currency::Try), false)
            ->assertDontSee(\App\Support\Money::format('798.03', Currency::Try), false);
        $this->actingAs($this->owner)->get('http://panel.test/panel/reports?user='.$this->sa1->id.'&period=custom&from=2020-01-01&to=2026-10-03')
            ->assertOk()
            ->assertSee('rep-commission', false)
            ->assertSee('%30', false)
            ->assertSee(\App\Support\Money::format('342.02', Currency::Try), false);
    }

    public function test_credit_lines_show_on_player_cards_only(): void
    {
        $from = Carbon::parse('2020-01-01')->utc();
        $to = now()->addDay()->utc();
        $svc = app(\App\Services\Stats\SettlementReport::class);

        $dealers = $svc->build($this->sa1, $from, $to, 'TRY');
        $bayi = collect($dealers['rows'])->first(fn ($x) => $x['user']->id === $this->bayi1->id);
        $this->assertFalse($bayi['show_credit']);
        $this->assertSame('1000.00', $bayi['given']);
        $this->assertFalse($dealers['show_credit']);
        $this->assertSame('0.00', $dealers['totals']['given']);
        $this->assertSame('0.00', $dealers['totals']['withdrawn']);
        $this->assertSame('0.00', $dealers['totals']['general']);

        $players = $svc->build($this->bayi1, $from, $to, 'TRY');
        $player = collect($players['rows'])->first(fn ($x) => $x['user']->id === $this->m1->id);
        $this->assertTrue($player['show_credit']);
        $this->assertSame('1000.00', $player['given']);
        $this->assertTrue($players['show_credit']);
        $this->assertSame('1000.00', $players['totals']['given']);

        $credit = \App\Support\Money::format('1000.00', Currency::Try);
        $this->actingAs($this->sa1)->get('http://panel.test/panel/reports?period=custom&from=2020-01-01&to=2026-10-03')
            ->assertOk()
            ->assertSee('bayi_one', false)
            ->assertDontSee(__('panel.rep_given'), false)
            ->assertDontSee(__('panel.rep_withdrawn'), false)
            ->assertDontSee($credit, false)
            ->assertSee('Bayi hareketleri ekranında görülür', false);

        $this->actingAs($this->bayi1)->get('http://panel.test/panel/reports?period=custom&from=2020-01-01&to=2026-10-03')
            ->assertOk()
            ->assertSee('oyuncu_one', false)
            ->assertSee(__('panel.rep_given'), false)
            ->assertSee(__('panel.rep_withdrawn'), false)
            ->assertSee($credit, false);
    }
}
