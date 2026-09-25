<?php

namespace App\Http\Controllers\Panel;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\CasinoGame;
use App\Models\CasinoProvider;
use App\Models\GameRound;
use App\Models\GameSession;
use App\Models\User;
use App\Services\Casino\ProviderRegistry;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CasinoController extends Controller
{
    public function providers(): View
    {
        abort_unless(auth()->user()->role === UserRole::Owner, 404);

        return view('panel.casino.providers', ['providers' => CasinoProvider::query()->orderBy('name')->get()]);
    }

    public function updateProvider(Request $request, CasinoProvider $provider): RedirectResponse
    {
        abort_unless($request->user()->role === UserRole::Owner, 404);
        $provider->status = $request->string('status')->toString() === 'active' ? 'active' : 'passive';
        $provider->save();

        return back()->with('status', __('panel.user_updated'));
    }

    public function sync(Request $request, CasinoProvider $provider, ProviderRegistry $registry): RedirectResponse
    {
        abort_unless($request->user()->role === UserRole::Owner, 404);
        $count = $registry->get($provider->code)?->syncGames() ?? 0;

        return back()->with('status', __('site.synced', ['count' => $count]));
    }

    public function games(): View
    {
        abort_unless(auth()->user()->role === UserRole::Owner, 404);

        return view('panel.casino.games', [
            'games' => CasinoGame::query()->with('provider')->orderBy('sort_order')->limit(100)->get(),
        ]);
    }

    public function updateGame(Request $request, CasinoGame $game): RedirectResponse
    {
        abort_unless($request->user()->role === UserRole::Owner, 404);
        $game->is_active = $request->boolean('is_active');
        $game->is_popular = $request->boolean('is_popular');
        $game->sort_order = (int) $request->input('sort_order', $game->sort_order);
        $game->save();

        return back()->with('status', __('panel.user_updated'));
    }

    public function rounds(Request $request): View
    {
        [$ids, $query] = $this->scoped($request, GameRound::query()->with(['user', 'game']));
        $this->between($request, $query, 'created_at');
        $rows = $query->orderByDesc('created_at')->limit(100)->get();
        $bet = '0.00';
        $win = '0.00';

        foreach ($rows as $row) {
            $bet = bcadd($bet, (string) $row->bet, 2);
            $win = bcadd($win, (string) $row->win, 2);
        }

        return view('panel.casino.rounds', [
            'rows' => $rows,
            'bet' => Money::format($bet, $request->user()->currency),
            'win' => Money::format($win, $request->user()->currency),
            'net' => Money::format(bcsub($bet, $win, 2), $request->user()->currency),
        ]);
    }

    public function sessions(Request $request): View
    {
        [, $query] = $this->scoped($request, GameSession::query()->with(['user', 'game.provider']));
        $this->between($request, $query, 'opened_at');

        return view('panel.casino.sessions', [
            'rows' => $query->orderByDesc('opened_at')->limit(100)->get(),
        ]);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return array{0: \Illuminate\Support\Collection<int, int>, 1: \Illuminate\Database\Eloquent\Builder<covariant \Illuminate\Database\Eloquent\Model>}
     */
    private function scoped(Request $request, $query): array
    {
        $actor = $request->user();
        $ids = User::query()->subtreeOf($actor)->pluck('id');
        $query->whereIn('user_id', $ids);

        return [$ids, $query];
    }

    private function between(Request $request, $query, string $column): void
    {
        $zone = $request->user()->timezone;

        if ($request->filled('from')) {
            $query->where($column, '>=', Carbon::parse($request->query('from'), $zone)->startOfDay()->utc());
        }

        if ($request->filled('to')) {
            $query->where($column, '<=', Carbon::parse($request->query('to'), $zone)->endOfDay()->utc());
        }
    }
}
