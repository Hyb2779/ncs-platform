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
    public function home(): View
    {
        return view('site.home', [
            'slots' => $this->games(false)->where('is_popular', true)->limit(12)->get(),
            'live' => $this->games(true)->limit(12)->get(),
        ]);
    }

    public function slots(Request $request): View
    {
        return view('site.lobby', $this->lobby($request, false));
    }

    public function live(Request $request): View
    {
        return view('site.lobby', $this->lobby($request, true));
    }

    public function sport(): View
    {
        return view('site.sport');
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
            'password' => ['required', 'string', 'min:8', 'confirmed'],
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

    public function launch(Request $request, CasinoGame $game, GameLauncher $launcher): RedirectResponse
    {
        $device = $request->header('User-Agent') && preg_match('/Mobile|Android/i', (string) $request->userAgent()) ? 'mobile' : 'desktop';
        $url = $launcher->open($request->user(), $game, $device, $request->ip());

        return redirect()->away($url);
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
    private function lobby(Request $request, bool $live): array
    {
        $query = $this->games($live);

        if ($request->filled('q')) {
            $query->where('name', 'like', '%'.$request->string('q').'%');
        }

        if ($request->filled('provider')) {
            $query->where('provider_id', (int) $request->query('provider'));
        }

        if ($request->query('list') === 'favorites' && $request->user()) {
            $ids = DB::table('casino_favorites')->where('user_id', $request->user()->id)->pluck('game_id');
            $query->whereIn('id', $ids);
        }

        if ($request->query('list') === 'recent' && $request->user()) {
            $ids = GameSession::query()->where('user_id', $request->user()->id)->latest('opened_at')->limit(20)->pluck('game_id');
            $query->whereIn('id', $ids);
        }

        return [
            'live' => $live,
            'games' => $query->orderBy('sort_order')->limit(60)->get(),
            'providers' => CasinoProvider::query()->where('status', 'active')->orderBy('name')->get(),
            'categories' => CasinoGame::query()->where('is_live', $live)->where('is_active', true)->distinct()->orderBy('category')->pluck('category'),
        ];
    }

    private function games(bool $live)
    {
        return CasinoGame::query()
            ->with('provider')
            ->where('is_live', $live)
            ->where('is_active', true)
            ->whereHas('provider', fn ($query) => $query->where('status', 'active'));
    }
}
