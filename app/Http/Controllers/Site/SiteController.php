<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\CasinoGame;
use App\Models\CasinoProvider;
use App\Models\GameSession;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Casino\DemoProvider;
use App\Services\Casino\GameLauncher;
use App\Services\WalletException;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class SiteController extends Controller
{
    public function home(\App\Services\HomeFeed $feed): View
    {
        $pool = $this->games('slot')->where('is_popular', true)->limit(60)->get();
        $seed = crc32(now()->toDateString());
        $daily = $pool->sortBy(fn ($game) => crc32($seed.'-'.$game->id))->take(6)->values();

        return view('site.home', $feed->build(auth()->user()) + [
            'dailyGames' => $daily,
            'slots' => $pool->reject(fn ($game) => $daily->contains('id', $game->id))->take(12)->values(),
            'live' => $this->games('live')->limit(12)->get(),
        ]);
    }

    public function slots(Request $request): View
    {
        return view('site.lobby', $this->lobby($request, 'slot'));
    }

    public function live(Request $request): View
    {
        return view('site.lobby', $this->lobby($request, 'live'));
    }

    public function mini(Request $request): View
    {
        return view('site.lobby', $this->lobby($request, 'mini'));
    }

    public function account(Request $request): View
    {
        $user = $request->user();
        abort_if($user === null, 403);
        $query = WalletTransaction::query()->with(['wallet', 'counterparty'])->where('user_id', $user->id)->orderByDesc('created_at');

        if ($request->filled('from')) {
            $query->where('created_at', '>=', Carbon::parse($request->query('from'), $user->timezone)->startOfDay()->utc());
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', Carbon::parse($request->query('to'), $user->timezone)->endOfDay()->utc());
        }

        $wallet = $user->wallet()->first();

        return view('site.account', [
            'headerBalance' => $wallet === null ? '' : Money::format((string) $wallet->balance, $wallet->currency),
            'rows' => $query->limit(50)->get()->map(fn (WalletTransaction $row) => [
                'when' => $row->created_at->timezone($user->timezone)->locale(app()->getLocale())->translatedFormat('d.m.Y H:i'),
                'party' => $this->party($row, $user),
                'before' => Money::format((string) $row->balance_before, $row->wallet->currency),
                'amount' => Money::formatSigned((string) $row->amount, $row->wallet->currency),
                'after' => Money::format((string) $row->balance_after, $row->wallet->currency),
                'note' => $row->note ?: __('panel.empty_value'),
            ]),
        ]);
    }

    public function password(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:4', 'confirmed'],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => __('site.password_wrong')]);
        }

        $user->password = $data['password'];
        $user->save();

        return back()->with('status', __('site.password_updated'));
    }

    public function balance(Request $request): JsonResponse
    {
        $wallet = $request->user()->wallet()->first();

        return response()->json([
            'balance' => Money::format((string) $wallet->balance, $wallet->currency),
        ]);
    }

    public function launch(Request $request, CasinoGame $game, GameLauncher $launcher): RedirectResponse|\Illuminate\Contracts\View\View
    {
        $device = $request->header('User-Agent') && preg_match('/Mobile|Android/i', (string) $request->userAgent()) ? 'mobile' : 'desktop';

        try {
            $url = $launcher->open($request->user(), $game, $device, $request->ip());
        } catch (WalletException $exception) {
            return back()->withErrors(['game' => __('wallet.errors.insufficient_balance')]);
        }

        // Oyun kendi ekranımızda (iframe + Geri Dön) açılır; üye siteden çıkmaz.
        $back = $game->is_live ? 'site.live_casino' : (str_starts_with((string) $game->vendor, 'mini-') ? 'site.mini' : 'site.slots');

        return view('site.play', [
            'gameUrl' => $url,
            'gameName' => (string) $game->name,
            'backUrl' => route($back),
            'isLive' => (bool) $game->is_live,
        ]);
    }

    public function favorite(Request $request, CasinoGame $game): RedirectResponse
    {
        $exists = DB::table('casino_favorites')->where('user_id', $request->user()->id)->where('game_id', $game->id)->exists();

        if ($exists) {
            DB::table('casino_favorites')->where('user_id', $request->user()->id)->where('game_id', $game->id)->delete();
        } else {
            DB::table('casino_favorites')->insert(['user_id' => $request->user()->id, 'game_id' => $game->id]);
        }

        return back();
    }

    public function demo(Request $request, CasinoGame $game): View
    {
        abort_if(app()->isProduction() || $game->provider?->code !== 'demo', 404);

        return view('site.demo', ['game' => $game]);
    }

    public function demoAction(Request $request, CasinoGame $game, DemoProvider $demo): RedirectResponse
    {
        abort_if(app()->isProduction() || $game->provider?->code !== 'demo', 404);
        $action = (string) $request->string('action');
        $amount = $action === 'win' ? '25.00' : '10.00';
        $transactionId = $action.'-'.$game->id.'-'.now()->getTimestampMs();

        if ($action === 'bet') {
            $request->session()->put('demo_bet_id', $transactionId);
        }

        $body = json_encode([
            'action' => $action,
            'user_code' => 'np_'.$request->user()->id,
            'transaction_id' => $transactionId,
            'bet_transaction_id' => $request->session()->get('demo_bet_id'),
            'amount' => $amount,
            'round_id' => 'round-'.$game->id,
            'game_code' => $game->external_id,
        ], JSON_THROW_ON_ERROR);
        $signed = Request::create('/api/casino/demo/callback', 'POST', content: $body);
        $signed->headers->set('Content-Type', 'application/json');
        $signed->headers->set('X-Demo-Signature', hash_hmac('sha256', $body, (string) config('casino.demo_secret')));
        $demo->handleCallback($signed);

        return back();
    }

    private function party(WalletTransaction $row, User $viewer): string
    {
        $other = $row->counterparty;

        if ($other === null) {
            return __('panel.empty_value');
        }

        if ($other->id !== $viewer->id && str_starts_with($viewer->path, $other->path)) {
            return __('wallet.upper_account');
        }

        return $other->username;
    }

    /**
     * @return array<string, mixed>
     */
    private function lobby(Request $request, string $mode): array
    {
        $query = $this->games($mode);

        if ($request->filled('q')) {
            $query->where('name', 'like', '%'.$request->string('q').'%');
        }

        if ($request->filled('provider')) {
            $query->where('provider_id', (int) $request->query('provider'));
        }

        $vendor = (string) $request->query('vendor', '');
        if ($vendor !== '') {
            $query->where('vendor', $vendor);
        }

        $list = (string) $request->query('list', 'all');
        if ($list === 'popular') {
            $query->where('is_popular', true);
        }

        if ($list === 'favorites' && $request->user()) {
            $ids = DB::table('casino_favorites')->where('user_id', $request->user()->id)->pluck('game_id');
            $query->whereIn('id', $ids);
        }

        if ($list === 'recent' && $request->user()) {
            $ids = GameSession::query()->where('user_id', $request->user()->id)->latest('opened_at')->limit(20)->pluck('game_id');
            $query->whereIn('id', $ids);
        }

        $plays = DB::table('game_rounds as r')
            ->join('casino_games as g', 'g.id', '=', 'r.game_id')
            ->where('r.created_at', '>=', now()->subDays(30))
            ->whereIn('r.game_id', $this->games($mode)->select('casino_games.id'))
            ->groupBy('g.vendor')
            ->selectRaw('g.vendor AS vendor, COUNT(*) AS c')
            ->pluck('c', 'vendor');

        $vendors = $this->games($mode)
            ->whereNotNull('vendor')
            ->groupBy('vendor')
            ->selectRaw('vendor, COUNT(*) AS c, SUM(CASE WHEN is_popular THEN 1 ELSE 0 END) AS p')
            ->get()
            ->map(fn ($row) => [
                'slug' => $row->vendor,
                'name' => \App\Support\Vendors::name($row->vendor),
                'count' => (int) $row->c,
                'plays' => (int) ($plays[$row->vendor] ?? 0),
                'popular' => (int) $row->p,
            ])
            ->sort(fn ($a, $b) => [$b['plays'], $b['popular'], \App\Support\Vendors::priority($a['slug'])] <=> [$a['plays'], $a['popular'], \App\Support\Vendors::priority($b['slug'])])
            ->values();

        return [
            'live' => $mode === 'live',
            'mode' => $mode,
            'total' => (clone $query)->count(),
            'games' => $query->orderByDesc('is_popular')->orderBy('sort_order')->orderBy('name')->limit($vendor !== '' ? 200 : 90)->get(),
            'providers' => CasinoProvider::query()->where('status', 'active')->orderBy('name')->get(),
            'vendors' => $vendors,
            'vendor' => $vendor,
            'list' => $list,
            'categories' => $this->games($mode)->distinct()->orderBy('category')->pluck('category'),
        ];
    }

    /** slot | live | virtual | mini — virtual ve mini (RomaSpin tip 3) slot listesine karışmaz. */
    private function games(string $mode)
    {
        $query = CasinoGame::query()
            ->with('provider')
            ->where('is_active', true)
            ->whereHas('provider', fn ($query) => $query->where('status', 'active'));

        // Admin engelleri (genel + uyenin superadmini) — oyun ac/kapat
        app(\App\Services\Casino\GameAvailability::class)->apply($query, auth()->user());

        return match ($mode) {
            'live' => $query->where('is_live', true),
            'virtual' => $query->where('category', 'virtual'),
            'mini' => $query->where('category', 'mini'),
            default => $query->where('is_live', false)->where(fn ($q) => $q->whereNull('category')->orWhereNotIn('category', ['virtual', 'mini'])),
        };
    }

    public function theme(\Illuminate\Http\Request $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate(['theme' => ['nullable', \Illuminate\Validation\Rule::in(\App\Enums\Theme::values())]]);
        $request->user()->forceFill(['theme' => $data['theme'] ?? null])->save();

        return back()->with('status', __('site.theme_saved'));
    }
}
