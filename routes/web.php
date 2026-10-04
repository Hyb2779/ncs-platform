<?php

use App\Enums\UserRole;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Panel\CasinoController;
use App\Http\Controllers\Panel\CouponController as PanelCouponController;
use App\Http\Controllers\Panel\DashboardController;
use App\Http\Controllers\Panel\SportAdminController;
use App\Http\Controllers\Panel\UserController;
use App\Http\Controllers\Panel\WalletController;
use App\Http\Controllers\Site\CouponController;
use App\Http\Controllers\Site\SiteController;
use App\Http\Controllers\Site\SportController;
use App\Http\Middleware\EnsurePanelUser;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $user = auth()->user();

    if ($user !== null && $user->role !== UserRole::Uye) {
        return redirect('/panel');
    }

    return app()->call([app(SiteController::class), 'home']);
})->name('site.home');

Route::get('/slots', [SiteController::class, 'slots'])->name('site.slots');
Route::get('/live-casino', [SiteController::class, 'live'])->name('site.live_casino');
Route::get('/mini', [SiteController::class, 'mini'])->name('site.mini');
Route::redirect('/virtual', '/mini')->name('site.virtual');
Route::redirect('/live', '/live-casino');
Route::get('/sport', [SportController::class, 'index'])->name('site.sport');
Route::get('/sport/live', [SportController::class, 'live'])->name('site.sport.live');
Route::get('/sport/live/data', [SportController::class, 'liveData'])->name('site.sport.live.data');
Route::get('/sport/results', [SportController::class, 'results'])->name('site.sport.results');
Route::get('/sport/fixtures/{fixture}', [SportController::class, 'show'])->name('site.sport.show');
Route::post('/sport/odds/{odd}', [SportController::class, 'add'])->name('site.sport.add');
Route::post('/sport/combo', [SportController::class, 'combo'])->name('site.sport.combo');
Route::post('/sport/coupon', [SportController::class, 'update'])->name('site.sport.coupon');
Route::post('/sport/coupon/{odd}/remove', [SportController::class, 'remove'])->name('site.sport.coupon.remove');
Route::post('/sport/coupon/clear', [SportController::class, 'clear'])->name('site.sport.coupon.clear');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/account', [SiteController::class, 'account'])->name('site.account');
    Route::get('/account/coupons', [CouponController::class, 'index'])->name('site.coupons');
    Route::get('/account/coupons/live', [CouponController::class, 'live'])->name('site.coupons.live');
    Route::get('/account/coupons/{coupon}', [CouponController::class, 'show'])->name('site.coupons.show');
    Route::post('/account/coupons/{coupon}/cancel', [CouponController::class, 'cancel'])->name('site.coupons.cancel');
    Route::post('/sport/coupon/place', [SportController::class, 'place'])->name('site.sport.coupon.place');
    Route::post('/account/password', [SiteController::class, 'password'])->name('site.password');
    Route::post('/account/theme', [SiteController::class, 'theme'])->name('site.theme');
    Route::get('/wegas-spor', [\App\Http\Controllers\Site\WegasSportController::class, 'show'])->name('site.wegas_sport');
    Route::get('/wegas-spor/kuponlarim', [\App\Http\Controllers\Site\WegasCouponController::class, 'index'])->name('site.wegas_coupons');
    Route::get('/wegas-spor/kuponlarim/{tipoCoupon}', [\App\Http\Controllers\Site\WegasCouponController::class, 'show'])->whereNumber('tipoCoupon')->name('site.wegas_coupons.show');
    Route::get('/account/balance', [SiteController::class, 'balance'])->name('site.balance');
    Route::get('/play/{game}', [SiteController::class, 'launch'])->name('site.launch');
    Route::post('/play/{game}/favorite', [SiteController::class, 'favorite'])->name('site.favorite');
    Route::get('/play/{game}/demo', [SiteController::class, 'demo'])->name('site.demo');
    Route::post('/play/{game}/demo', [SiteController::class, 'demoAction'])->name('site.demo.action');
});

Route::middleware(['auth', EnsurePanelUser::class])->prefix('panel')->name('panel.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/network/{user}', [DashboardController::class, 'show'])->name('network.show');
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::post('/credit-fees/payments', [\App\Http\Controllers\Panel\CreditFeeController::class, 'store'])->name('credit-fees.payments.store');
    Route::get('/reports', [\App\Http\Controllers\Panel\ReportController::class, 'index'])->name('reports.index');
    Route::get('/online', [\App\Http\Controllers\Panel\OnlineController::class, 'index'])->name('online.index');
    Route::get('/password', [\App\Http\Controllers\Panel\PasswordController::class, 'edit'])->name('password.edit');
    Route::post('/password', [\App\Http\Controllers\Panel\PasswordController::class, 'update'])->name('password.update');
    Route::post('/preferences/currency', [\App\Http\Controllers\Panel\PanelPreferenceController::class, 'currency'])->name('preferences.currency');
    Route::post('/preferences/language', [\App\Http\Controllers\Panel\PanelPreferenceController::class, 'language'])->name('preferences.language');
    Route::get('/logs', [\App\Http\Controllers\Panel\LogController::class, 'index'])->name('logs.index');
    Route::get('/logs/logins', [\App\Http\Controllers\Panel\LogController::class, 'logins'])->name('logs.logins');
    Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::get('/users/{user}', [\App\Http\Controllers\Panel\MemberProfileController::class, 'show'])->whereNumber('user')->name('users.show');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::post('/users/{user}/balance', [WalletController::class, 'adjust'])->name('wallets.adjust');
    Route::post('/users/{user}/status', [UserController::class, 'toggleStatus'])->name('users.status');
    Route::post('/users/{user}/password', [UserController::class, 'resetPassword'])->name('users.password');
    Route::get('/transactions', [WalletController::class, 'transactions'])->name('transactions');
    Route::get('/balance', [WalletController::class, 'page'])->name('balance');
    Route::get('/casino/providers', [CasinoController::class, 'providers'])->name('casino.providers');
    Route::put('/casino/providers/{provider}', [CasinoController::class, 'updateProvider'])->name('casino.providers.update');
    Route::post('/casino/providers/{provider}/sync', [CasinoController::class, 'sync'])->name('casino.providers.sync');
    Route::get('/casino/games', [CasinoController::class, 'games'])->name('casino.games');
    Route::put('/casino/games/{game}', [CasinoController::class, 'updateGame'])->name('casino.games.update');
    Route::get('/casino/rounds', [CasinoController::class, 'rounds'])->name('casino.rounds');
    Route::get('/casino/sessions', [CasinoController::class, 'sessions'])->name('casino.sessions');
    Route::get('/games', [\App\Http\Controllers\Panel\GameControlController::class, 'index'])->name('games.index');
    Route::post('/games/block', [\App\Http\Controllers\Panel\GameControlController::class, 'toggle'])->name('games.block');
    Route::get('/coupons', [PanelCouponController::class, 'index'])->name('coupons.index');
    Route::get('/coupons/lookup', [PanelCouponController::class, 'lookup'])->name('coupons.lookup');
    Route::get('/coupons/risky', [PanelCouponController::class, 'risky'])->name('coupons.risky');
    Route::get('/coupons/density', [\App\Http\Controllers\Panel\TipoCouponController::class, 'density'])->name('coupons.density');
    Route::get('/coupons/{coupon}', [PanelCouponController::class, 'show'])->name('coupons.show');
    Route::get('/coupons/tipo/{tipoCoupon}', [\App\Http\Controllers\Panel\TipoCouponController::class, 'show'])->name('coupons.tipo');
    Route::post('/coupons/{coupon}/cancel', [PanelCouponController::class, 'cancel'])->name('coupons.cancel');
    Route::get('/sport/limits', [SportAdminController::class, 'limits'])->name('sport.limits');
    Route::get('/theme', [\App\Http\Controllers\Panel\ThemeController::class, 'edit'])->name('theme');
    Route::post('/theme', [\App\Http\Controllers\Panel\ThemeController::class, 'update'])->name('theme.update');
    Route::put('/sport/limits', [SportAdminController::class, 'updateLimits'])->name('sport.limits.update');
    Route::post('/sport/limits/restore', [SportAdminController::class, 'restoreLimits'])->name('sport.limits.restore');
    Route::get('/sport/status', [SportAdminController::class, 'status'])->name('sport.status');
    Route::get('/sport/overdrafts', [SportAdminController::class, 'overdrafts'])->name('sport.overdrafts');
    Route::get('/sport/fixtures/{fixture}', [SportAdminController::class, 'fixture'])->name('sport.fixtures.show');
    Route::post('/sport/fixtures/{fixture}/score', [SportAdminController::class, 'updateScore'])->name('sport.fixtures.score');
    Route::get('/sport/leagues', [SportAdminController::class, 'leagues'])->name('sport.leagues');
    Route::put('/sport/leagues/{league}', [SportAdminController::class, 'updateLeague'])->name('sport.leagues.update');
    Route::get('/sport/translations', [SportAdminController::class, 'translations'])->name('sport.translations');
    Route::put('/sport/translations', [SportAdminController::class, 'updateTranslation'])->name('sport.translations.update');
    Route::get('/sport/margins', [SportAdminController::class, 'margins'])->name('sport.margins');
    Route::post('/sport/margins', [SportAdminController::class, 'storeMargin'])->name('sport.margins.store');
});

Route::get('/cache/g/{file}', \App\Http\Controllers\Site\GameImageController::class)->where('file', '[0-9]+-[0-9a-f]{8}\.webp')->name('game.image');
