<?php

namespace Tests\Feature;

use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\Currency;
use App\Models\CasinoGame;
use App\Models\CasinoProvider;
use App\Models\User;
use App\Services\Casino\ProviderRegistry;
use App\Services\HierarchyService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RomaSpinProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'casino.user_prefix' => 'np_',
            'casino.romaspin.url' => 'https://romaspin.test/api/v2',
            'casino.romaspin.client_id' => 'wegas-test',
            'casino.romaspin.client_secret' => 'secret-test',
            'casino.romaspin.currency' => 'TRY',
        ]);
    }

    public function test_balance_requires_basic_auth(): void
    {
        $member = $this->fundedMember();

        $this->rs('balance', ['userCode' => 'np_'.$member->id])
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('errorCode', 0)->assertJsonPath('message', 100);

        $this->rs('balance', ['userCode' => 'np_'.$member->id], 'Basic '.base64_encode('wegas-test:wrong'))
            ->assertOk()->assertJsonPath('success', false)->assertJsonPath('errorCode', 401);

        $this->rs('balance', ['userCode' => 'np_999999'])->assertJsonPath('errorCode', 2);
    }

    public function test_bet_and_win_move_balance_once(): void
    {
        $member = $this->fundedMember();
        $bet = $this->tx($member, 'r1-debit', -10);

        $this->rs('transaction', $bet)->assertJsonPath('success', true)->assertJsonPath('message', 90);
        $this->rs('transaction', $bet)->assertJsonPath('success', true)->assertJsonPath('message', 90);
        $this->assertSame('90.00', $this->balance($member));

        $this->rs('transaction', $this->tx($member, 'r1-credit', 25))->assertJsonPath('message', 115);
        $this->assertSame('115.00', $this->balance($member));
    }

    public function test_debit_code_with_positive_amount_is_a_bet(): void
    {
        $member = $this->fundedMember();

        $this->rs('transaction', $this->tx($member, 'r2-debit', 10))->assertJsonPath('message', 90);
        $this->assertSame('90.00', $this->balance($member));
    }

    public function test_insufficient_balance_returns_error_4(): void
    {
        $member = $this->fundedMember('5.00');

        $this->rs('transaction', $this->tx($member, 'r3-debit', -10))
            ->assertJsonPath('success', false)->assertJsonPath('errorCode', 4);
        $this->assertSame('5.00', $this->balance($member));
    }

    public function test_cancel_refunds_the_bet(): void
    {
        $member = $this->fundedMember();
        $this->rs('transaction', $this->tx($member, 'r4-debit', -10))->assertJsonPath('message', 90);

        $this->rs('transaction', $this->tx($member, 'r4-debit', -10, ['isCanceled' => true]))
            ->assertJsonPath('success', true)->assertJsonPath('message', 100);
        $this->assertSame('100.00', $this->balance($member));
    }

    public function test_batch_transaction_applies_all(): void
    {
        $member = $this->fundedMember();

        $this->rs('batch-transaction', [
            'userCode' => 'np_'.$member->id,
            'transactions' => [$this->tx($member, 'r5-debit', -10), $this->tx($member, 'r5-credit', 4)],
        ])->assertJsonPath('success', true)->assertJsonPath('message', 94);
        $this->assertSame('94.00', $this->balance($member));
    }

    public function test_sync_takes_live_vendors_and_keeps_failed_vendor_games(): void
    {
        $provider = $this->provider();
        $sa = $this->game($provider, 'casino-sa|lobby', 'casino-sa');
        $old = $this->game($provider, 'casino-ezugi|old', 'casino-ezugi');

        Http::fake([
            '*/auth/createtoken' => Http::response(['token' => 'tkn', 'expiration' => time() + 3600]),
            '*/vendors/list' => Http::response(['success' => true, 'errorCode' => 0, 'message' => [
                ['vendorCode' => 'casino-ezugi', 'type' => 1, 'name' => 'Ezugi'],
                ['vendorCode' => 'casino-sa', 'type' => 1, 'name' => 'Sa Gaming'],
                ['vendorCode' => 'slot-pgsoft', 'type' => 2, 'name' => 'PGSoft'],
            ]]),
            '*/games/list' => function (HttpRequest $request) {
                if ($request['vendorCode'] === 'casino-sa') {
                    return Http::response('', 504);
                }

                return Http::response(['success' => true, 'errorCode' => 0, 'message' => [
                    ['vendorCode' => 'casino-ezugi', 'gameCode' => 'lobby', 'gameName' => 'lobby', 'thumbnail' => 'https://img.test/lobby.jpg', 'underMaintenance' => false],
                    ['vendorCode' => 'casino-ezugi', 'gameCode' => '1000', 'gameName' => 'Blackjack A', 'thumbnail' => 'https://img.test/bj.jpg', 'underMaintenance' => false],
                ]]);
            },
        ]);

        $count = app(ProviderRegistry::class)->get('romaspin')->syncGames();

        $this->assertSame(2, $count);
        $lobby = CasinoGame::query()->where('external_id', 'casino-ezugi|lobby')->firstOrFail();
        $this->assertSame('Ezugi Lobby', $lobby->name);
        $this->assertSame('casino-ezugi', $lobby->vendor);
        $this->assertTrue((bool) $lobby->is_live);
        $this->assertTrue((bool) $lobby->is_active);
        $this->assertTrue((bool) $sa->fresh()->is_active);
        $this->assertFalse((bool) $old->fresh()->is_active);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/games/list') && $r['vendorCode'] === 'slot-pgsoft');
    }

    public function test_launch_creates_user_when_missing(): void
    {
        $member = $this->fundedMember();
        $game = $this->game($this->provider(), 'casino-ezugi|1000', 'casino-ezugi');

        Http::fake([
            '*/auth/createtoken' => Http::response(['token' => 'tkn', 'expiration' => time() + 3600]),
            '*/user/create' => Http::response(['success' => true, 'errorCode' => 0, 'message' => 'User created successfully.']),
            '*/game/launch-url' => Http::sequence()
                ->push(['success' => false, 'errorCode' => 2, 'message' => 'User does not exist'])
                ->push(['success' => true, 'errorCode' => 0, 'message' => 'https://play.test/game?x=1']),
        ]);

        $url = app(ProviderRegistry::class)->get('romaspin')->launch($member, $game, 'desktop');

        $this->assertSame('https://play.test/game?x=1', $url);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/user/create') && $r['userCode'] === 'np_'.$member->id);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/game/launch-url')
            && $r['vendorCode'] === 'casino-ezugi' && $r['gameCode'] === '1000' && $r->hasHeader('Authorization', 'Bearer tkn'));
    }

    public function test_launch_rejects_non_try_member(): void
    {
        $member = $this->fundedMember('0.00', 'USD');
        $game = $this->game($this->provider(), 'casino-ezugi|1000', 'casino-ezugi');
        Http::fake();

        $this->expectException(\RuntimeException::class);
        app(ProviderRegistry::class)->get('romaspin')->launch($member, $game, 'desktop');
    }

    public function test_sync_takes_only_listed_slot_vendors(): void
    {
        config(['casino.romaspin.slot_vendors' => ['slot-novomatic']]);
        Http::fake([
            '*/auth/createtoken' => Http::response(['token' => 'tkn', 'expiration' => time() + 3600]),
            '*/vendors/list' => Http::response(['success' => true, 'errorCode' => 0, 'message' => [
                ['vendorCode' => 'slot-novomatic', 'type' => 2, 'name' => 'Novomatic'],
                ['vendorCode' => 'slot-pgsoft', 'type' => 2, 'name' => 'PGSoft'],
            ]]),
            '*/games/list' => Http::response(['success' => true, 'errorCode' => 0, 'message' => [
                ['vendorCode' => 'slot-novomatic', 'gameCode' => 'bookofra', 'gameName' => 'Book of Ra', 'thumbnail' => 'https://img.test/bor.jpg', 'underMaintenance' => false],
            ]]),
        ]);

        $this->assertSame(1, app(ProviderRegistry::class)->get('romaspin')->syncGames());
        $game = CasinoGame::query()->where('external_id', 'slot-novomatic|bookofra')->firstOrFail();
        $this->assertSame('slot', $game->category);
        $this->assertFalse((bool) $game->is_live);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/games/list') && $r['vendorCode'] === 'slot-pgsoft');
    }

    private function rs(string $action, array $body, ?string $auth = null): TestResponse
    {
        return $this->withHeaders(['Authorization' => $auth ?? 'Basic '.base64_encode('wegas-test:secret-test')])
            ->postJson('/api/casino/romaspin/api/'.$action, $body);
    }

    private function tx(User $member, string $code, float $amount, array $extra = []): array
    {
        return array_merge([
            'userCode' => 'np_'.$member->id,
            'vendorCode' => 'casino-ezugi',
            'gameCode' => '1000',
            'historyId' => 1,
            'roundId' => explode('-', $code)[0],
            'gameType' => 1,
            'transactionCode' => $code,
            'isFinished' => false,
            'isCanceled' => false,
            'amount' => $amount,
            'detail' => '',
            'createdAt' => '2026-09-30 18:00:00',
        ], $extra);
    }

    private function balance(User $member): string
    {
        return (string) $member->wallet()->first()->fresh()->balance;
    }

    private function provider(): CasinoProvider
    {
        return CasinoProvider::query()->create(['code' => 'romaspin', 'name' => 'RomaSpin', 'status' => 'active', 'is_live' => true]);
    }

    private function game(CasinoProvider $provider, string $externalId, string $vendor): CasinoGame
    {
        return CasinoGame::query()->create([
            'provider_id' => $provider->id,
            'external_id' => $externalId,
            'name' => $externalId,
            'category' => 'live',
            'is_live' => true,
            'is_active' => true,
            'vendor' => $vendor,
        ]);
    }

    private function fundedMember(string $amount = '100.00', string $currency = 'TRY'): User
    {
        $owner = User::query()->create([
            'username' => 'owner-'.uniqid(),
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
        $member = app(HierarchyService::class)->create($owner->refresh(), [
            'username' => 'uye-'.uniqid(),
            'password' => 'password',
            'commission_rate' => 0,
            'user_limit' => null,
            'note' => null,
            'language' => 'tr',
            'currency' => $currency,
            'timezone' => 'Europe/Istanbul',
        ]);

        if (bccomp($amount, '0', 2) === 1) {
            app(WalletService::class)->transfer($owner, $member, $amount, 'fund-'.uniqid(), $owner);
        }

        return $member;
    }
}
