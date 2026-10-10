<?php

namespace App\Http\Controllers\Site;

use App\Enums\Language;
use App\Enums\Theme;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\SetLocale;
use App\Models\CasinoGame;
use App\Models\CasinoProvider;
use App\Models\GameSession;
use App\Services\Casino\CatalogCache;
use App\Services\Casino\DemoProvider;
use App\Services\Casino\GameAvailability;
use App\Services\Casino\GameCatalog;
use App\Services\Casino\GameLauncher;
use App\Services\Casino\GameSuggest;
use App\Services\Casino\HomeCasinoRails;
use App\Services\HomeCategoryImages;
use App\Services\HomeFeed;
use App\Services\HomeSlides;
use App\Services\Sport\HomeMatches;
use App\Services\WalletException;
use App\Support\GameSearch;
use App\Support\Money;
use App\Support\Vendors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SiteController extends Controller
{
    public function home(HomeFeed $feed, HomeCasinoRails $rails, HomeSlides $slides, HomeCategoryImages $images, HomeMatches $matches): View
    {
        $user = auth()->user();
        $data = $feed->build($user);

        return view('site.home', $data + [
            'popularSlots' => $rails->popularSlots($user),
            'liveTables' => $rails->liveTables($user),
            'slides' => $slides->forViewer($user),
            'categoryImages' => $images->urls($user),
            'homeMatches' => site_sport_link($user) !== null ? $matches->present($user) : null,
        ]);
    }

    public function homeMatches(HomeMatches $matches): JsonResponse
    {
        $user = auth()->user();
        if (site_sport_link($user) === null) {
            return response()->json(['live' => [], 'upcoming' => []])->header('Cache-Control', 'no-store');
        }

        $data = $matches->present($user);

        return response()->json([
            'live' => $data['live'],
            'upcoming' => $data['upcoming'],
        ])->header('Cache-Control', 'no-store');
    }

    public function slots(Request $request): View
    {
        return view('site.lobby', $this->lobby($request, 'slot'));
    }

    public function suggest(Request $request, GameSuggest $suggest): JsonResponse
    {
        $mode = (string) $request->query('mode', 'slot');
        $term = mb_substr(trim((string) $request->query('q', '')), 0, 80);

        return response()->json(
            $suggest->search($request->user(), $mode, $term, (string) $request->query('vendor', ''))
        )->header('Cache-Control', 'no-store');
    }

    public function live(Request $request): View
    {
        return view('site.lobby', $this->lobby($request, 'live'));
    }

    public function mini(Request $request): View
    {
        return view('site.lobby', $this->lobby($request, 'mini'));
    }

    public function license(): View
    {
        return view('site.license', [
            'domain' => GameCatalog::domain(),
            'licenseNo' => GameCatalog::LICENSE_NO,
            'companyNo' => GameCatalog::COMPANY_NO,
        ]);
    }

    public function account(Request $request): View
    {
        $user = $request->user();
        abort_if($user === null, 403);
        $wallet = $user->wallet()->first();

        return view('site.account', [
            'headerBalance' => $wallet === null ? '' : Money::format((string) $wallet->balance, $wallet->currency),
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

        if (Hash::check($data['password'], $user->password)) {
            return back()->withErrors(['password' => __('site.password_same')]);
        }

        $user->password = $data['password'];
        $user->must_change_password = false;
        $user->save();
        EnsureAccountActive::remember($request, $user);

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
        return view('site.play', [
            'gameUrl' => $url,
            'gameName' => (string) $game->name,
            'backUrl' => $this->gameReturnUrl($request),
            'isLive' => (bool) $game->is_live,
        ]);
    }

    /**
     * Oyuna gelinen sayfa. Referrer yoksa, başka bir domainse veya sağlayıcı adresiyse ana sayfa.
     */
    private function gameReturnUrl(Request $request): string
    {
        $home = route('site.home');
        $referer = trim((string) $request->headers->get('referer', ''));
        $parts = parse_url($referer);
        if (! is_array($parts)) {
            return $home;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || $host !== strtolower($request->getHost())) {
            return $home;
        }

        $path = (string) ($parts['path'] ?? '/');
        if ($path === '' || str_contains($path, '\\') || str_starts_with($path, '//') || preg_match('#^/(play|api)(?:/|$)#', $path) === 1) {
            return $home;
        }

        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return $scheme.'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '').($path === '' ? '/' : $path).$query;
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

    /**
     * @return array<string, mixed>
     */
    private function lobby(Request $request, string $mode): array
    {
        $query = $this->games($mode);

        $needle = GameSearch::like((string) $request->query('q', ''));
        if ($needle !== '%%') {
            $query->where('name_folded', 'like', $needle);
        }

        if ($request->filled('provider')) {
            $query->where('provider_id', (int) $request->query('provider'));
        }

        $vendor = (string) $request->query('vendor', '');
        if ($vendor !== '') {
            $query->where('vendor', $vendor);
        }

        $list = (string) $request->query('list', 'all');
        if ($list === 'popular' && $mode !== 'slot') {
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

        $plays = $this->lobbyPlays($mode);

        $availability = app(GameAvailability::class);
        $viewer = $request->user();
        $counts = [];
        foreach (app(CatalogCache::class)->rows() as $row) {
            if (! $this->inLobbyMode($row, $mode) || ! $availability->visible($row, $viewer) || $row['vendor'] === null) {
                continue;
            }
            $slug = $row['vendor'];
            $counts[$slug] ??= ['slug' => $slug, 'name' => Vendors::name($slug), 'count' => 0, 'popular' => 0];
            $counts[$slug]['count']++;
            if ($row['is_popular']) {
                $counts[$slug]['popular']++;
            }
        }
        $vendors = collect($counts)
            ->map(function (array $row) use ($plays) {
                $row['plays'] = (int) ($plays[$row['slug']] ?? 0);

                return $row;
            })
            ->sort(fn ($a, $b) => [$b['plays'], $b['popular'], Vendors::priority($a['slug'])] <=> [$a['plays'], $a['popular'], Vendors::priority($b['slug'])])
            ->values();

        $limit = $vendor !== '' ? 200 : 90;
        $games = ($list === 'popular' && $mode === 'slot')
            ? app(HomeCasinoRails::class)->ranked($query, $limit)
            : $query->orderByDesc('is_popular')->orderBy('sort_order')->orderBy('name')->limit($limit)->get();

        return [
            'live' => $mode === 'live',
            'mode' => $mode,
            'total' => (clone $query)->count(),
            'games' => $games,
            'providers' => CasinoProvider::query()->where('status', 'active')->orderBy('name')->get(),
            'vendors' => $vendors,
            'vendor' => $vendor,
            'list' => $list,
            'categories' => $this->games($mode)->distinct()->orderBy('category')->pluck('category'),
        ];
    }

    /**
     * Sağlayıcı oynanma sayıları. Önbellek düz dizi tutar: Collection, serializable_classes
     * kapalıyken __PHP_Incomplete_Class olup lobiyi 500'e düşürür.
     *
     * @return array<string, int>
     */
    private function lobbyPlays(string $mode): array
    {
        $key = 'casino:lobby-plays:'.$mode;
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        if ($cached !== null) {
            Cache::forget($key);
        }

        $plays = DB::table('game_rounds as r')
            ->join('casino_games as g', 'g.id', '=', 'r.game_id')
            ->where('r.created_at', '>=', now()->subDays(30))
            ->whereIn('r.game_id', $this->games($mode, false)->select('casino_games.id'))
            ->groupBy('g.vendor')
            ->selectRaw('g.vendor AS vendor, COUNT(*) AS c')
            ->pluck('c', 'vendor')
            ->map(fn ($count) => (int) $count)
            ->all();

        Cache::put($key, $plays, CatalogCache::TTL);

        return $plays;
    }

    /** slot | live | virtual | mini — virtual ve mini (RomaSpin tip 3) slot listesine karışmaz. */
    private function games(string $mode, bool $blocks = true)
    {
        $query = CasinoGame::query()
            ->with('provider')
            ->where('is_active', true)
            ->whereHas('provider', fn ($query) => $query->where('status', 'active'));

        if ($blocks) {
            app(GameAvailability::class)->apply($query, auth()->user());
        }

        return match ($mode) {
            'live' => $query->where('is_live', true),
            'virtual' => $query->where('category', 'virtual'),
            'mini' => $query->where('category', 'mini'),
            default => $query->where('is_live', false)->where(fn ($q) => $q->whereNull('category')->orWhereNotIn('category', ['virtual', 'mini'])),
        };
    }

    /**
     * @param  array{category: ?string, is_live: bool}  $row
     */
    private function inLobbyMode(array $row, string $mode): bool
    {
        $category = $row['category'] ?? null;

        return match ($mode) {
            'live' => $row['is_live'],
            'virtual' => $category === 'virtual',
            'mini' => $category === 'mini',
            default => ! $row['is_live'] && ! in_array($category, ['virtual', 'mini'], true),
        };
    }

    public function locale(Request $request): RedirectResponse
    {
        $language = (string) $request->input('language');
        abort_unless(in_array($language, SetLocale::LOCALES, true), 422);
        abort_unless($request->user()?->role === UserRole::Uye, 403);

        $request->user()->forceFill(['language' => Language::from($language)])->save();
        $request->session()->put('locale', $language);

        return back();
    }

    public function theme(Request $request): RedirectResponse
    {
        $data = $request->validate(['theme' => ['nullable', Rule::in(Theme::values())]]);
        $request->user()->forceFill(['theme' => $data['theme'] ?? null])->save();

        return back()->with('status', __('site.theme_saved'));
    }
}
