<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CasinoGame;
use App\Models\CasinoProvider;
use App\Models\CasinoProviderUser;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\HierarchyService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoldPalaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'casino.user_prefix' => 'np_',
            'casino.goldpalace.url' => 'https://gp.test',
            'casino.goldpalace.api_token' => 'gp-test-token',
            'casino.goldpalace.callback_token' => 'gp-test-token',
        ]);
    }

    public function test_authenticate_and_balance_use_ncs_vip_body(): void
    {
        $member = $this->fundedMember();

        $this->gp('authenticate', ['account' => 'np_'.$member->id])
            ->assertOk()
            ->assertExactJson([
                'result' => 0,
                'status' => 'OK',
                'data' => ['account' => 'np_'.$member->id, 'balance' => 100],
            ]);

        $this->gp('balance', ['account' => 'np_'.$member->id])
            ->assertOk()
            ->assertExactJson([
                'result' => 0,
                'status' => 'OK',
                'data' => ['balance' => 100],
            ]);
    }

    public function test_callback_debit_credit_via_account_and_user_code(): void
    {
        $member = $this->fundedMember();
        CasinoProviderUser::query()->create([
            'provider' => 'goldpalace',
            'user_id' => $member->id,
            'external_code' => '409777002',
        ]);

        $this->gp('bet', [
            'account' => 'np_'.$member->id,
            'trans_guid' => 't-bet-ncs-1',
            'amount' => 1.5,
        ])->assertOk()->assertJson(['result' => 0, 'status' => 'OK']);
        $this->assertSame('98.50', $member->wallet()->first()->fresh()->balance);

        $this->gp('win', [
            'account' => '409777002',
            'trans_guid' => 't-win-ncs-1',
            'amount' => 1.5,
        ])->assertOk()->assertJson(['result' => 0, 'status' => 'OK']);
        $this->assertSame('100.00', $member->wallet()->first()->fresh()->balance);
    }

    public function test_duplicate_bet_returns_same_balance(): void
    {
        $member = $this->fundedMember();
        $first = $this->gp('bet', [
            'account' => 'np_'.$member->id,
            'trans_guid' => 't-bet-dup',
            'amount' => 10,
        ])->assertOk()->assertJsonPath('result', 0);
        $second = $this->gp('bet', [
            'account' => 'np_'.$member->id,
            'trans_guid' => 't-bet-dup',
            'amount' => 10,
        ])->assertOk()->assertJsonPath('result', 0);

        $this->assertSame($first->json('data.balance'), $second->json('data.balance'));
        $this->assertSame(1, WalletTransaction::query()->where('type', 'bet')->count());
        $this->assertSame('90.00', $member->wallet()->first()->fresh()->balance);
    }

    public function test_insufficient_balance_uses_provider_status(): void
    {
        $member = $this->fundedMember('0.00');

        $this->gp('bet', [
            'account' => 'np_'.$member->id,
            'trans_guid' => 't-bet-empty',
            'amount' => 10,
        ])->assertOk()->assertExactJson([
            'result' => 1,
            'status' => 'INSUFFICIENT_BALANCE',
        ]);
        $this->assertSame(0, WalletTransaction::query()->count());
    }

    public function test_cancel_refunds_bet_and_is_idempotent(): void
    {
        $member = $this->fundedMember();
        $this->gp('bet', [
            'account' => 'np_'.$member->id,
            'trans_guid' => 't-bet-ncs-1',
            'amount' => 1.5,
            'round_id' => 'r-cancel',
        ])->assertOk();

        $this->gp('cancel', [
            'account' => 'np_'.$member->id,
            'trans_guid' => 't-cancel-ncs-1',
            'cancel_trans_guid' => 't-bet-ncs-1',
        ])->assertOk()->assertJson([
            'result' => 0,
            'status' => 'OK',
            'data' => ['balance' => 100],
        ]);
        $this->gp('cancel', [
            'account' => 'np_'.$member->id,
            'trans_guid' => 't-cancel-ncs-1',
            'cancel_trans_guid' => 't-bet-ncs-1',
        ])->assertOk()->assertJsonPath('result', 0);
        $this->assertSame('100.00', $member->wallet()->first()->fresh()->balance);
        $this->assertSame(1, WalletTransaction::query()->where('type', 'refund')->count());
    }

    public function test_cancel_without_bet_is_safe_noop(): void
    {
        $member = $this->fundedMember();

        $this->gp('cancel', [
            'account' => 'np_'.$member->id,
            'trans_guid' => 't-cancel-missing',
            'cancel_trans_guid' => 't-bet-missing',
        ])->assertOk()->assertJson([
            'result' => 0,
            'status' => 'OK',
            'data' => ['balance' => 100],
        ]);
        $this->assertSame(0, WalletTransaction::query()->where('type', 'refund')->count());
    }

    public function test_status_and_unknown_command(): void
    {
        $member = $this->fundedMember();
        $this->gp('bet', [
            'account' => 'np_'.$member->id,
            'trans_guid' => 't-status-1',
            'amount' => 2,
        ])->assertOk();

        $this->gp('status', [
            'account' => 'np_'.$member->id,
            'trans_guid' => 't-status-1',
        ])->assertOk()->assertJson([
            'result' => 0,
            'status' => 'OK',
            'data' => [
                'account' => 'np_'.$member->id,
                'trans_guid' => 't-status-1',
                'trans_status' => 'OK',
            ],
        ]);
        $this->gp('status', [
            'account' => 'np_'.$member->id,
            'trans_guid' => 'missing',
        ])->assertOk()->assertJson(['result' => 1, 'status' => 'TRANS_NOT_FOUND']);
        $this->gp('nope', ['account' => 'np_'.$member->id])
            ->assertOk()
            ->assertJson(['result' => 1, 'status' => 'UNKNOWN_COMMAND']);
    }

    public function test_wrong_prefix_and_missing_user_are_rejected(): void
    {
        $member = $this->fundedMember();

        $this->gp('authenticate', ['account' => 'xx_'.$member->id])
            ->assertOk()
            ->assertJson(['result' => 1, 'status' => 'USER_NOT_FOUND']);
        $this->gp('authenticate', ['account' => 'np_999999'])
            ->assertOk()
            ->assertJson(['result' => 1, 'status' => 'USER_NOT_FOUND']);
        $this->assertSame(0, WalletTransaction::query()->where('type', 'bet')->count());
    }

    public function test_bonus_call_is_treated_as_win(): void
    {
        $member = $this->fundedMember();

        $this->gp('bonuscall', [
            'account' => 'np_'.$member->id,
            'trans_guid' => 't-bonus-1',
            'amount' => 5,
        ])->assertOk()->assertJsonPath('result', 0);
        $this->assertSame('105.00', $member->wallet()->first()->fresh()->balance);
    }

    public function test_create_sends_prefixed_name_and_launch_maps_lang(): void
    {
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/v4/user/create')) {
                return Http::response([
                    'code' => 0,
                    'message' => 'OK',
                    'data' => ['user_code' => 409999001, 'is_new_user' => true],
                ], 200);
            }
            if (str_ends_with($path, '/v4/game/game-url')) {
                return Http::response(['code' => 0, 'data' => ['game_url' => 'https://gp.test/play']], 200);
            }

            return Http::response(['code' => 1], 500);
        });

        $member = $this->fundedMember();
        $member->language = Language::De;
        $member->save();
        $game = $this->goldpalaceGame();

        $this->actingAs($member)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile'])
            ->get('/play/'.$game->id)
            ->assertRedirect('https://gp.test/play');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v4/user/create')
            && ($request->data()['name'] ?? null) === 'np_'.$member->id);
        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), '/v4/game/game-url')
                && (int) ($body['lang'] ?? 0) === 10
                && (int) ($body['mobile'] ?? 0) === 1
                && (int) ($body['is_mobile'] ?? 0) === 1
                && ($body['game_symbol'] ?? null) === 'vs20fruitsw';
        });
    }

    public function test_language_map_matches_ncs_vip(): void
    {
        $cases = [
            Language::En->value => 1,
            Language::Tr->value => 7,
            Language::De->value => 10,
            Language::Ar->value => 15,
        ];
        $member = $this->fundedMember();
        CasinoProviderUser::query()->create([
            'provider' => 'goldpalace',
            'user_id' => $member->id,
            'external_code' => '409499073',
        ]);
        $game = $this->goldpalaceGame();

        foreach ($cases as $locale => $lang) {
            Http::fake([
                'https://gp.test/v4/game/game-url' => Http::response(['code' => 0, 'data' => ['game_url' => 'https://gp.test/play']], 200),
            ]);
            $member->language = Language::from($locale);
            $member->save();

            $this->actingAs($member)->get('/play/'.$game->id)->assertRedirect('https://gp.test/play');
            Http::assertSent(fn ($request) => str_contains($request->url(), '/v4/game/game-url')
                && (int) ($request->data()['lang'] ?? 0) === $lang
                && (int) ($request->data()['user_code'] ?? 0) === 409499073);
        }
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v4/user/create'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function gp(string $command, array $data)
    {
        return $this->withHeaders(['Callback-Token' => 'gp-test-token'])
            ->postJson('/api/casino/goldpalace/callback', [
                'command' => $command,
                'data' => $data,
            ]);
    }

    private function goldpalaceGame(): CasinoGame
    {
        $provider = CasinoProvider::query()->create([
            'code' => 'goldpalace',
            'name' => 'GoldPalace',
            'status' => 'active',
            'is_live' => false,
        ]);

        return CasinoGame::query()->create([
            'provider_id' => $provider->id,
            'external_id' => '1:vs20fruitsw',
            'name' => 'Sweet Bonanza',
            'category' => 'Slots',
            'is_live' => false,
            'is_active' => true,
            'sort_order' => 0,
            'is_popular' => true,
        ]);
    }

    private function fundedMember(string $amount = '100.00'): User
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
            'currency' => 'TRY',
            'timezone' => 'Europe/Istanbul',
        ]);

        if (bccomp($amount, '0', 2) === 1) {
            app(WalletService::class)->transfer($owner, $member, $amount, 'fund-'.uniqid(), $owner);
        }

        return $member;
    }
}
