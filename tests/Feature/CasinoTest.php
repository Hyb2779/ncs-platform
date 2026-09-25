<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CasinoGame;
use App\Models\CasinoProvider;
use App\Models\GameRound;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Casino\DemoProvider;
use App\Services\HierarchyService;
use App\Services\WalletService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CasinoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'casino.user_prefix' => 'np_',
            'casino.demo_secret' => 'demo-callback-secret',
            'casino.goldpalace.callback_token' => 'gp-test-token',
            'casino.onegamex.secret_key' => 'ogx-test-secret',
            'casino.onegamex.token_id' => 'ogx-test-id',
        ]);
        app(DemoProvider::class)->syncGames();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_duplicate_bet_debits_once(): void
    {
        $member = $this->fundedMember();
        $this->postCallback('demo', $this->demoBody($member, 'bet', 'bet-1', '10.00'));
        $this->postCallback('demo', $this->demoBody($member, 'bet', 'bet-1', '10.00'));

        $this->assertSame(1, WalletTransaction::query()->where('type', 'bet')->count());
        $this->assertSame('90.00', $member->wallet()->first()->fresh()->balance);
    }

    public function test_insufficient_bet_writes_nothing(): void
    {
        $member = $this->fundedMember('0.00');
        $this->postCallback('demo', $this->demoBody($member, 'bet', 'bet-empty', '10.00'))->assertStatus(422);

        $this->assertSame(0, WalletTransaction::query()->count());
        $this->assertSame(0, GameRound::query()->count());
    }

    public function test_bet_win_refund_keeps_the_ledger_sound(): void
    {
        $member = $this->fundedMember();
        Carbon::setTestNow(now()->addSecond());
        $this->postCallback('demo', $this->demoBody($member, 'bet', 'bet-2', '10.00'));
        Carbon::setTestNow(now()->addSecond());
        $this->postCallback('demo', $this->demoBody($member, 'win', 'win-2', '25.00'));
        Carbon::setTestNow(now()->addSecond());
        $this->postCallback('demo', $this->demoBody($member, 'refund', 'refund-2', '10.00', 'bet-2'));
        $this->postCallback('demo', $this->demoBody($member, 'refund', 'refund-2', '10.00', 'bet-2'));

        $this->assertSame('125.00', $member->wallet()->first()->fresh()->balance);
        $this->artisan('wallet:verify')->assertOk();
    }

    public function test_invalid_signatures_are_rejected(): void
    {
        $member = $this->fundedMember();
        $this->postJson('/api/casino/demo/callback', $this->demoBody($member, 'bet', 'x', '1.00'), ['X-Demo-Signature' => 'nope'])->assertStatus(401);
        $this->postJson('/api/casino/goldpalace/callback', ['user_code' => 'np_'.$member->id, 'transaction_type' => 1, 'transaction_id' => 'g1', 'amount' => 1], ['Callback-Token' => 'wrong'])->assertOk()->assertJsonPath('message', 'ERROR');
        $this->postJson('/api/casino/onegamex/callback', ['action' => 'bet', 'user_code' => 'np_'.$member->id, 'transaction_id' => 'o1', 'amount' => '1.00'], ['X-Token-Id' => 'ogx-test-id', 'X-Signature' => 'nope'])->assertStatus(401);
        $this->assertSame(0, WalletTransaction::query()->where('type', 'bet')->count());
    }

    public function test_wrong_user_prefix_is_rejected(): void
    {
        $member = $this->fundedMember();
        $body = $this->demoBody($member, 'bet', 'pref', '1.00');
        $body['user_code'] = 'xx_'.$member->id;
        $this->postCallback('demo', $body)->assertStatus(422);
        $this->assertSame(0, WalletTransaction::query()->where('type', 'bet')->count());
    }

    public function test_provider_examples_settle_bet_win_and_refund(): void
    {
        $member = $this->fundedMember();
        $this->withHeaders(['Callback-Token' => 'gp-test-token'])->postJson('/api/casino/goldpalace/callback', [
            'user_code' => 'np_'.$member->id,
            'transaction_type' => 1,
            'transaction_id' => 'gp-bet',
            'bet_transaction_id' => null,
            'amount' => 10,
            'round_id' => 'r1',
            'game_code' => 'slot-1',
        ])->assertJsonPath('code', 0);
        $this->withHeaders(['Callback-Token' => 'gp-test-token'])->postJson('/api/casino/goldpalace/callback', [
            'user_code' => 'np_'.$member->id,
            'transaction_type' => 2,
            'transaction_id' => 'gp-win',
            'amount' => 4,
            'round_id' => 'r1',
            'game_code' => 'slot-1',
        ])->assertJsonPath('code', 0);
        $this->withHeaders(['Callback-Token' => 'gp-test-token'])->postJson('/api/casino/goldpalace/callback', [
            'user_code' => 'np_'.$member->id,
            'transaction_type' => 16,
            'transaction_id' => 'gp-cancel',
            'bet_transaction_id' => 'gp-bet',
            'amount' => 10,
            'round_id' => 'r1',
        ])->assertJsonPath('code', 0);

        $payload = json_encode(['action' => 'bet', 'user_code' => 'np_'.$member->id, 'transaction_id' => 'ogx-bet', 'amount' => '1.00', 'round_id' => 'r2'], JSON_THROW_ON_ERROR);
        $this->call('POST', '/api/casino/onegamex/callback', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TOKEN_ID' => 'ogx-test-id',
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $payload, 'ogx-test-secret'),
        ], $payload)->assertOk();

        $this->assertSame('103.00', $member->wallet()->first()->fresh()->balance);
    }

    public function test_passive_game_cannot_launch(): void
    {
        $member = $this->fundedMember();
        $game = CasinoGame::query()->where('external_id', 'slot-1')->first();
        $game->is_active = false;
        $game->save();

        $this->actingAs($member)->get('/play/'.$game->id)->assertNotFound();
        $game->is_active = true;
        $game->save();
        $game->provider->status = 'passive';
        $game->provider->save();
        $this->actingAs($member)->get('/play/'.$game->id)->assertNotFound();
    }

    public function test_goldpalace_authenticate_resolves_account_name(): void
    {
        $member = $this->fundedMember();

        $this->withHeaders(['Callback-Token' => 'gp-test-token'])->postJson('/api/casino/goldpalace/callback', [
            'command' => 'authenticate',
            'data' => ['account' => 'np_'.$member->id],
            'timestamp' => 1,
            'check' => 'x',
        ])->assertJsonPath('code', 0)
            ->assertJsonPath('data.account', 'np_'.$member->id)
            ->assertJsonPath('data.balance', 100);
    }

    public function test_goldpalace_launch_redirects_to_provider_url(): void
    {
        config(['casino.goldpalace.url' => 'https://agent.example']);
        Http::fake([
            'https://agent.example/v4/user/create' => Http::response(['code' => 0, 'data' => ['user_code' => 400000001]], 200),
            'https://agent.example/v4/game/game-url' => Http::response(['code' => 0, 'data' => ['game_url' => 'https://games.example/play']], 200),
        ]);
        $member = $this->fundedMember();
        $provider = CasinoProvider::query()->create([
            'code' => 'goldpalace',
            'name' => 'GoldPalace',
            'status' => 'active',
            'is_live' => false,
        ]);
        $game = CasinoGame::query()->create([
            'provider_id' => $provider->id,
            'external_id' => '1:vs20fruitsw',
            'name' => 'Sweet Bonanza',
            'category' => 'Slots',
            'is_live' => false,
            'is_active' => true,
            'sort_order' => 0,
            'is_popular' => true,
        ]);

        $this->actingAs($member)->get('/play/'.$game->id)->assertRedirect('https://games.example/play');
    }

    public function test_bayi_cannot_see_another_branch_rounds(): void
    {
        $owner = $this->owner('owner-casino');
        $hierarchy = app(HierarchyService::class);
        $left = $hierarchy->create($owner, $this->locale('bayi-left'));
        $right = $hierarchy->create($owner, $this->locale('bayi-right'));
        $member = $hierarchy->create($right, $this->locale('uye-right'));
        app(WalletService::class)->transfer($owner, $member, '20.00', 'fund-right', $owner);
        $this->postCallback('demo', $this->demoBody($member, 'bet', 'branch-bet', '5.00'));

        $this->actingAs($left)->get('/panel/casino/rounds')->assertOk()->assertDontSee($member->username);
    }

    public function test_member_account_hides_the_upper_balance(): void
    {
        $owner = $this->owner('owner-acct');
        $member = app(HierarchyService::class)->create($owner, $this->locale('uye-acct'));
        app(WalletService::class)->transfer($owner, $member, '40.00', 'fund-acct', $owner);

        $page = $this->actingAs($member)->get('/account');
        $page->assertOk();
        $page->assertSee(__('wallet.upper_account'));
        $page->assertDontSee($owner->username);
        $page->assertDontSee('-40', false);
    }

    public function test_player_site_renders_in_four_languages(): void
    {
        foreach (['tr', 'en', 'de'] as $locale) {
            $this->get('/?lang='.$locale)->assertOk()->assertSee('dir="ltr"', false);
        }

        $this->get('/?lang=ar')->assertOk()->assertSee('dir="rtl"', false)->assertSee(__('site.slots', [], 'ar'), false);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function postCallback(string $provider, array $body)
    {
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $headers = ['CONTENT_TYPE' => 'application/json'];

        if ($provider === 'demo') {
            $headers['HTTP_X_DEMO_SIGNATURE'] = hash_hmac('sha256', $json, 'demo-callback-secret');
        }

        return $this->call('POST', '/api/casino/'.$provider.'/callback', [], [], [], $headers, $json);
    }

    /**
     * @return array<string, mixed>
     */
    private function demoBody(User $member, string $action, string $id, string $amount, ?string $betId = null): array
    {
        return [
            'action' => $action,
            'user_code' => 'np_'.$member->id,
            'transaction_id' => $id,
            'bet_transaction_id' => $betId,
            'amount' => $amount,
            'round_id' => 'round-1',
            'game_code' => 'slot-1',
        ];
    }

    private function fundedMember(string $amount = '100.00'): User
    {
        $owner = $this->owner('owner-'.uniqid());
        $member = app(HierarchyService::class)->create($owner, $this->locale('uye-'.uniqid()));

        if (bccomp($amount, '0', 2) === 1) {
            app(WalletService::class)->transfer($owner, $member, $amount, 'fund-'.uniqid(), $owner);
        }

        return $member;
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
    private function locale(string $username): array
    {
        return [
            'username' => $username,
            'password' => 'password',
            'commission_rate' => 0,
            'user_limit' => null,
            'note' => null,
            'language' => 'tr',
            'currency' => 'TRY',
            'timezone' => 'Europe/Istanbul',
        ];
    }
}
