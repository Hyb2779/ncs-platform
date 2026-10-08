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
use App\Models\GameBlock;
use App\Models\GameRound;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HomeWinnersTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_home_ticker_shows_recent_game_wins_with_a_small_image(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-08 18:00:00', 'UTC'));
        $provider = CasinoProvider::query()->create(['code' => 'prov-own', 'name' => 'Provider', 'status' => 'active', 'is_live' => false]);
        $slot = $this->game($provider, 'sweet', 'Sweet Bonanza 2500', 'https://cdn.test/sweet.png');
        $hidden = $this->game($provider, 'hidden', 'Hidden Dragon', 'https://cdn.test/hidden.png');
        $ancient = $this->game($provider, 'ancient', 'Ancient Game', null);
        [$member, $other] = $this->members();
        $wallets = app(WalletService::class);
        $now = Carbon::parse('2026-10-08 17:00:00', 'UTC');

        $this->win($wallets, $member, $slot, '80.00', 'big', $now);
        $this->win($wallets, $member, $slot, '10.00', 'tiny', $now->copy()->addMinute());
        $this->win($wallets, $member, $ancient, '200.00', 'old', $now->copy()->subDays(3));
        $wallets->credit($member->wallet, '500.00', WalletTransactionType::Win, WalletProduct::Sport, 'sport:cup', null, null, 'kupon', $member, null, null, $now);
        $this->win($wallets, $other, $hidden, '90.00', 'foreign', $now->copy()->addMinutes(2));
        GameBlock::query()->create(['superadmin_id' => null, 'scope' => 'game', 'value' => (string) $hidden->id, 'created_by' => $member->id]);

        $guest = $this->get('/');
        $guest->assertOk();
        $html = $guest->getContent();
        $this->assertSame(1, preg_match('/data-win-ticker[\s\S]*?<\/section>/', $html, $found));
        $ticker = $found[0];
        $this->assertStringContainsString(__('home.winners'), $ticker);
        $this->assertStringContainsString('Sweet Bonanza 2500', $ticker);
        $this->assertStringContainsString('al***ne', $ticker);
        $this->assertStringContainsString('+80,00', $ticker);
        $this->assertStringContainsString('/cache/g/'.$slot->id.'-'.substr(md5('https://cdn.test/sweet.png'), 0, 8).'.webp', $ticker);
        $this->assertStringContainsString('win-chip-art', $ticker);
        $this->assertStringNotContainsString('alphaone', $ticker);
        $this->assertStringNotContainsString('Hidden Dragon', $ticker);
        $this->assertStringNotContainsString('Ancient Game', $ticker);
        $this->assertStringNotContainsString('be***wo', $ticker);
        $this->assertStringNotContainsString('+10,00', $ticker);
        $this->assertStringNotContainsString('+500,00', $ticker);

        $at = strpos($html, 'data-win-ticker');
        $matches = strpos($html, 'data-home-matches');
        if ($matches !== false) {
            $this->assertLessThan($matches, $at);
        }
        $live = strpos($html, 'data-home-rail="live"');
        if ($live !== false) {
            $this->assertLessThan($at, $live);
        }

        $own = $this->actingAs($member)->get('/');
        $own->assertSee('Sweet Bonanza 2500');
        $own->assertDontSee('Hidden Dragon');
        $own->assertDontSee('be***wo');
        $own->assertSee(route('site.launch', $slot), false);
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function members(): array
    {
        $owner = User::query()->create([
            'username' => 'owner-wins',
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
        $left = $hierarchy->create($owner->refresh(), $payload('sa-left'));
        $right = $hierarchy->create($owner->refresh(), $payload('sa-right'));
        $member = $hierarchy->create($hierarchy->create($left, $payload('bayi-left')), $payload('alphaone'));
        $other = $hierarchy->create($hierarchy->create($right, $payload('bayi-right')), $payload('betatwo'));

        return [$member->refresh(), $other->refresh()];
    }

    private function game(CasinoProvider $provider, string $externalId, string $name, ?string $image): CasinoGame
    {
        return CasinoGame::query()->create([
            'provider_id' => $provider->id,
            'external_id' => $externalId,
            'name' => $name,
            'category' => 'slot',
            'image_url' => $image,
            'is_live' => false,
            'is_active' => true,
        ]);
    }

    private function win(WalletService $wallets, User $member, CasinoGame $game, string $amount, string $tx, Carbon $at): void
    {
        $wallets->credit($member->wallet, $amount, WalletTransactionType::Win, WalletProduct::Slot, 'prov-own:'.$tx, $tx, null, $game->name, $member, null, null, $at);
        GameRound::query()->create([
            'provider' => 'prov-own',
            'provider_transaction_id' => $tx,
            'round_id' => $tx,
            'user_id' => $member->id,
            'game_id' => $game->id,
            'bet' => '0.00',
            'win' => $amount,
            'balance_before' => '0.00',
            'amount' => $amount,
            'balance_after' => $amount,
            'status' => 'win',
            'payload' => [],
            'created_at' => $at,
        ]);
    }
}
