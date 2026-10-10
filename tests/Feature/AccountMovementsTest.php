<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\WalletProduct;
use App\Enums\WalletTransactionType;
use App\Models\CasinoGame;
use App\Models\CasinoProvider;
use App\Models\GameRound;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AccountMovementsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_member_cannot_see_another_members_rows_even_with_user_id_parameter(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-07 12:00:00', 'UTC'));
        [$bayi, $member, $other] = $this->pair();
        $wallets = app(WalletService::class);
        $when = Carbon::parse('2026-10-06 12:00:00', 'UTC');

        $wallets->credit($member->wallet, '15.50', WalletTransactionType::TransferIn, WalletProduct::Transfer, 'own-load', null, $bayi->id, 'note for '.$bayi->username, $bayi, null, null, $when);
        $wallets->credit($other->wallet, '8888.88', WalletTransactionType::TransferIn, WalletProduct::Transfer, 'other-load', null, $bayi->id, 'hidden '.$other->username, $bayi, null, null, $when);

        $response = $this->actingAs($member)->get('/account/movements?user_id='.$other->id);

        $response->assertOk();
        $response->assertSee('15,50');
        $response->assertSee(__('account.loaded'));
        $response->assertDontSee('8.888,88');
        $response->assertDontSee($other->username);
        $response->assertDontSee($bayi->username);
        $response->assertDontSee('user_id='.$other->id);
        $this->assertStringNotContainsString((string) $other->id, (string) $response->headers->get('Location'));
    }

    public function test_balance_filters_hide_the_actor_and_label_sport_without_tipo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-07 12:00:00', 'UTC'));
        [$bayi, $member] = array_slice($this->pair(), 0, 2);
        $wallets = app(WalletService::class);
        $when = Carbon::parse('2026-10-06 12:00:00', 'UTC');

        $wallets->credit($member->wallet, '15.50', WalletTransactionType::TransferIn, WalletProduct::Transfer, 'load-1', 'ref-load', $bayi->id, 'loaded by '.$bayi->username, $bayi, null, null, $when);
        $wallets->debit($member->wallet, '9.25', WalletTransactionType::TransferOut, WalletProduct::Transfer, 'draw-1', 'ref-draw', $bayi->id, 'taken by '.$bayi->username, $bayi, null, null, $when->copy()->addHour());
        $wallets->debit($member->wallet, '5.00', WalletTransactionType::Bet, WalletProduct::Sport, 'tipo:stake-1', 'TIPO-55', $bayi->id, 'Tipo kupon notu', $bayi, null, null, $when->copy()->addHours(2));

        $all = $this->actingAs($member)->get('/account/movements');
        $all->assertOk();
        $all->assertSee(__('account.loaded'));
        $all->assertSee(__('account.withdrawn'));
        $all->assertSee(brand()->name().' '.__('site.sport'));
        $all->assertSee('-5,00');
        $this->assertMatchesRegularExpression('/'.preg_quote(brand()->name().' '.__('site.sport'), '/').'[\s\S]{0,400}-5,00/', $all->getContent());
        $all->assertDontSee('Tipo');
        $all->assertDontSee('tipo:');
        $all->assertDontSee('TIPO-55');
        $all->assertDontSee($bayi->username);

        $incoming = $this->actingAs($member)->get('/account/movements?direction=in');
        $incoming->assertSee('15,50');
        $incoming->assertDontSee('9,25');
        $incoming->assertDontSee('-5,00');
        $incoming->assertDontSee(__('account.withdrawn'));

        $outgoing = $this->actingAs($member)->get('/account/movements?direction=out');
        $outgoing->assertSee('9,25');
        $outgoing->assertDontSee('15,50');
        $outgoing->assertDontSee('-5,00');
    }

    public function test_balance_rows_name_the_slot_or_live_game_and_hide_transfer_notes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-07 12:00:00', 'UTC'));
        [$bayi, $member] = array_slice($this->pair(), 0, 2);
        $wallets = app(WalletService::class);
        $when = Carbon::parse('2026-10-06 12:00:00', 'UTC');
        $provider = CasinoProvider::query()->create(['code' => 'prov-own', 'name' => 'Provider', 'status' => 'active', 'is_live' => false]);
        $slot = $this->game($provider, 'named-slot', '40 Super Hot', 'slot', false, null);

        $wallets->credit($member->wallet, '50.00', WalletTransactionType::TransferIn, WalletProduct::Transfer, 'load-secret', null, $bayi->id, 'loaded by '.$bayi->username, $bayi, null, null, $when);
        $wallets->debit($member->wallet, '4.00', WalletTransactionType::Bet, WalletProduct::Slot, 'prov-own:named-bet', 'round-slot', null, 'Sweet Bonanza 2500', $member, null, null, $when->copy()->addMinute());
        $wallets->credit($member->wallet, '9.00', WalletTransactionType::Win, WalletProduct::LiveCasino, 'prov-own:named-win', 'round-live', null, 'Lightning Dragon Tiger', $member, null, null, $when->copy()->addMinutes(2));
        $wallets->debit($member->wallet, '1.50', WalletTransactionType::Bet, WalletProduct::Slot, 'prov-own:old-'.$member->id, 'round-old', null, null, $member, null, null, $when->copy()->addMinutes(3));
        $this->round($member, $slot, 'old', '1.50', '0.00', $when->copy()->addMinutes(3));

        $response = $this->actingAs($member)->get('/account/movements');

        $response->assertOk();
        $response->assertSee('Sweet Bonanza 2500');
        $response->assertSee('Lightning Dragon Tiger');
        $response->assertSee('40 Super Hot');
        $response->assertSee(__('account.cat_slot').' · '.__('wallet.types.bet'));
        $response->assertSee(__('account.cat_live').' · '.__('wallet.types.win'));
        $response->assertDontSee($bayi->username);
        $response->assertDontSee('loaded by');
    }

    public function test_game_history_groups_rounds_and_hides_other_members(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-07 12:00:00', 'UTC'));
        [, $member, $other] = $this->pair();
        $provider = CasinoProvider::query()->create(['code' => 'prov-own', 'name' => 'Provider', 'status' => 'active', 'is_live' => false]);
        $slot = $this->game($provider, 'own-sweet', 'Own Sweet Game', 'slot', false, 'https://example.test/sweet.png');
        $live = $this->game($provider, 'own-live', 'Own Live Table', 'live', true, null);
        $mini = $this->game($provider, 'own-mini', 'Own Mini Crash', 'mini', false, null);
        $ghost = $this->game($provider, 'own-ghost', 'Zero Ghost', 'slot', false, null);
        $hidden = $this->game($provider, 'hidden-rival', 'Hidden Rival Game', 'slot', false, null);
        $when = Carbon::parse('2026-10-06 10:00:00', 'UTC');

        $this->round($member, $slot, 's1', '10.00', '0.00', $when);
        $this->round($member, $slot, 's2', '4.00', '25.00', $when->copy()->addMinute());
        $this->round($member, $live, 'l1', '3.00', '0.00', $when);
        $this->round($member, $mini, 'm1', '2.00', '5.00', $when);
        $this->round($member, $ghost, 'z1', '0.00', '0.00', $when);
        $this->round($other, $hidden, 'h1', '9999.00', '1.00', $when);

        $response = $this->actingAs($member)->get('/account/movements?tab=games');

        $response->assertOk();
        $response->assertSee('Own Sweet Game');
        $response->assertSee('14,00');
        $response->assertSee('25,00');
        $response->assertSee('+11,00');
        $response->assertSee('19,00');
        $response->assertSee('30,00');
        $response->assertSee(__('account.cat_slot'));
        $response->assertSee(__('account.cat_live'));
        $response->assertSee(__('account.cat_mini'));
        $response->assertSee('Own Live Table');
        $response->assertSee('Own Mini Crash');
        $response->assertSee('/cache/g/'.$slot->id.'-'.substr(md5('https://example.test/sweet.png'), 0, 8).'.webp', false);
        $response->assertDontSee('Zero Ghost');
        $response->assertDontSee('Hidden Rival Game');
        $response->assertDontSee('9.999,00');
        $response->assertDontSee('name="direction"', false);
        $this->assertSame(2, substr_count($response->getContent(), 'Own Sweet Game'));
    }

    public function test_default_window_is_seven_days_in_the_member_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-07 12:00:00', 'UTC'));
        [$bayi, $member] = array_slice($this->pair(), 0, 2);
        $bayi->timezone = 'America/New_York';
        $bayi->save();
        $member->timezone = 'America/New_York';
        $member->save();
        $wallets = app(WalletService::class);

        $inside = Carbon::parse('2026-10-08 01:30:00', 'UTC');
        $outside = Carbon::parse('2026-10-07 03:00:00', 'UTC');
        $old = Carbon::parse('2026-09-01 12:00:00', 'UTC');
        $wallets->credit($member->wallet, '20.00', WalletTransactionType::TransferIn, WalletProduct::Transfer, 'tz-in', null, null, null, null, null, null, $inside);
        $wallets->credit($member->wallet, '30.00', WalletTransactionType::TransferIn, WalletProduct::Transfer, 'tz-out', null, null, null, null, null, null, $outside);
        $wallets->credit($member->wallet, '40.00', WalletTransactionType::TransferIn, WalletProduct::Transfer, 'tz-old', null, null, null, null, null, null, $old);

        $today = $this->actingAs($member)->get('/account/movements?period=today');
        $today->assertSee($inside->copy()->timezone('America/New_York')->format('d.m.Y H:i'));
        $today->assertDontSee($outside->copy()->timezone('America/New_York')->format('d.m.Y H:i'));
        $today->assertDontSee('40,00');

        $week = $this->actingAs($member)->get('/account/movements');
        $week->assertSee('20,00');
        $week->assertSee('30,00');
        $week->assertDontSee('40,00');
        $week->assertSee('name="from"', false);
        $week->assertSee('value="2026-10-01"', false);
        $week->assertSee('value="2026-10-07"', false);
        $week->assertSee('name="direction"', false);
        $week->assertSee(__('panel.filter'), false);
        $week->assertDontSee('period=today', false);
        $week->assertDontSee('period=yesterday', false);
        $week->assertDontSee(__('account.back').'</a>', false);

        $custom = $this->actingAs($member)->get('/account/movements?period=custom&from=2026-09-01&to=2026-09-01');
        $custom->assertSee('40,00');
        $custom->assertDontSee('20,00');
    }

    public function test_load_more_returns_the_next_own_page_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-07 12:00:00', 'UTC'));
        [$bayi, $member, $other] = $this->pair();
        $wallets = app(WalletService::class);
        $start = Carbon::parse('2026-10-06 00:00:00', 'UTC');

        for ($i = 0; $i < 21; $i++) {
            $wallets->credit($member->wallet, '10.00', WalletTransactionType::TransferIn, WalletProduct::Transfer, 'page-'.$i, null, null, null, null, null, null, $start->copy()->addHours($i));
        }
        $wallets->credit($other->wallet, '8888.88', WalletTransactionType::TransferIn, WalletProduct::Transfer, 'page-other', null, null, null, null, null, null, $start->copy()->addMinutes(30));

        $oldest = $start->copy()->timezone('Europe/Istanbul')->format('d.m.Y H:i');
        $newest = $start->copy()->addHours(20)->timezone('Europe/Istanbul')->format('d.m.Y H:i');
        $foreign = $start->copy()->addMinutes(30)->timezone('Europe/Istanbul')->format('d.m.Y H:i');

        $first = $this->actingAs($member)->get('/account/movements');
        $first->assertSee($newest);
        $first->assertDontSee($oldest);
        $first->assertDontSee($foreign);
        $first->assertDontSee($other->username);
        $first->assertSee('data-load-more', false);

        $second = $this->actingAs($member)->getJson('/account/movements?page=2&user_id='.$other->id);
        $second->assertOk();
        $second->assertJsonPath('next', null);
        $body = $second->json('rows').$second->json('cards');
        $this->assertStringContainsString($oldest, $body);
        $this->assertStringNotContainsString($foreign, $body);
        $this->assertStringNotContainsString($other->username, $body);
        $this->assertStringNotContainsString('8.888,88', $body);
        $this->assertStringNotContainsString('user_id', $second->json('next') ?? '');
    }

    public function test_only_a_member_can_open_the_page_and_see_the_menu_link(): void
    {
        [$bayi, $member, $other, $owner] = $this->pair();

        $this->get('/account/movements')->assertRedirect(route('login'));
        $this->actingAs($bayi)->get('/account/movements')->assertNotFound();
        $this->actingAs($owner)->get('/account/movements')->assertNotFound();
        $this->actingAs($member)->get('/account/movements')->assertOk()->assertSee(__('account.empty'));

        $this->actingAs($member)->get('/account')
            ->assertSee(route('site.account.movements'), false)
            ->assertSee('account-menu', false)
            ->assertSee('account-logout', false)
            ->assertSee(route('logout'), false)
            ->assertSee(__('site.logout'), false)
            ->assertDontSee('site-footer', false)
            ->assertDontSee('name="from"', false)
            ->assertDontSee(__('site.language').':', false);
        $this->actingAs($member)->get('/account/movements')->assertOk()->assertDontSee('site-footer', false);
        $this->actingAs($bayi)->get('/account')->assertDontSee(route('site.account.movements'), false);

        $member->language = Language::Ar;
        $member->save();
        $this->actingAs($member)->get('/account/movements')
            ->assertSee('dir="rtl"', false)
            ->assertSee('حركات حسابي');

        $indexes = array_column(Schema::getIndexes('game_rounds'), 'name');
        $this->assertContains('game_rounds_user_created_idx', $indexes);

        $this->assertNotNull($other);
    }

    /**
     * @return array{0: User, 1: User, 2: User, 3: User}
     */
    private function pair(): array
    {
        $owner = User::query()->create([
            'username' => 'owner-movements',
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

        $hierarchy = app(HierarchyService::class);
        $payload = fn (string $username) => [
            'username' => $username,
            'password' => 'password',
            'commission_rate' => 0,
            'user_limit' => null,
            'note' => null,
            'language' => 'tr',
            'currency' => 'TRY',
        ];
        $superadmin = $hierarchy->create($owner->refresh(), $payload('sa-movements'));
        $bayi = $hierarchy->create($superadmin, $payload('dealer-secret-handle'));
        $member = $hierarchy->create($bayi, $payload('own-member-handle'));
        $other = $hierarchy->create($bayi, $payload('other-secret-handle'));

        return [$bayi->refresh(), $member->refresh(), $other->refresh(), $owner->refresh()];
    }

    private function game(CasinoProvider $provider, string $externalId, string $name, string $category, bool $live, ?string $image): CasinoGame
    {
        return CasinoGame::query()->create([
            'provider_id' => $provider->id,
            'external_id' => $externalId,
            'name' => $name,
            'category' => $category,
            'image_url' => $image,
            'is_live' => $live,
            'is_active' => true,
        ]);
    }

    private function round(User $user, CasinoGame $game, string $tx, string $bet, string $win, Carbon $at): void
    {
        GameRound::query()->create([
            'provider' => 'prov-own',
            'provider_transaction_id' => $tx.'-'.$user->id,
            'round_id' => $tx,
            'user_id' => $user->id,
            'game_id' => $game->id,
            'bet' => $bet,
            'win' => $win,
            'balance_before' => '0.00',
            'amount' => '0.00',
            'balance_after' => '0.00',
            'status' => 'settled',
            'payload' => [],
            'created_at' => $at,
        ]);
    }
}
