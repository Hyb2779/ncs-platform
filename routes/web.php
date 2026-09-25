<?php

use App\Enums\UserRole;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Panel\CasinoController;
use App\Http\Controllers\Panel\DashboardController;
use App\Http\Controllers\Panel\SportAdminController;
use App\Http\Controllers\Panel\UserController;
use App\Http\Controllers\Panel\WalletController;
use App\Http\Controllers\Site\SiteController;
use App\Http\Controllers\Site\SportController;
use App\Http\Middleware\EnsurePanelUser;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $user = auth()->user();

    if ($user !== null && $user->role !== UserRole::Uye) {
        return redirect('/panel');
    }

    return app(SiteController::class)->home();
})->name('site.home');

Route::get('/slots', [SiteController::class, 'slots'])->name('site.slots');
Route::get('/live-casino', [SiteController::class, 'live'])->name('site.live_casino');
Route::redirect('/live', '/live-casino');
Route::get('/sport', [SportController::class, 'index'])->name('site.sport');
Route::get('/sport/live', [SportController::class, 'live'])->name('site.sport.live');
Route::get('/sport/results', [SportController::class, 'results'])->name('site.sport.results');
Route::get('/sport/fixtures/{fixture}', [SportController::class, 'show'])->name('site.sport.show');
Route::post('/sport/odds/{odd}', [SportController::class, 'add'])->name('site.sport.add');
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
    Route::post('/account/password', [SiteController::class, 'password'])->name('site.password');
    Route::get('/account/balance', [SiteController::class, 'balance'])->name('site.balance');
    Route::get('/play/{game}', [SiteController::class, 'launch'])->name('site.launch');
    Route::post('/play/{game}/favorite', [SiteController::class, 'favorite'])->name('site.favorite');
    Route::get('/play/{game}/demo', [SiteController::class, 'demo'])->name('site.demo');
    Route::post('/play/{game}/demo', [SiteController::class, 'demoAction'])->name('site.demo.action');
});

Route::middleware(['auth', EnsurePanelUser::class])->prefix('panel')->name('panel.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::post('/users/{user}/balance', [WalletController::class, 'adjust'])->name('wallets.adjust');
    Route::get('/transactions', [WalletController::class, 'transactions'])->name('transactions');
    Route::get('/casino/providers', [CasinoController::class, 'providers'])->name('casino.providers');
    Route::put('/casino/providers/{provider}', [CasinoController::class, 'updateProvider'])->name('casino.providers.update');
    Route::post('/casino/providers/{provider}/sync', [CasinoController::class, 'sync'])->name('casino.providers.sync');
    Route::get('/casino/games', [CasinoController::class, 'games'])->name('casino.games');
    Route::put('/casino/games/{game}', [CasinoController::class, 'updateGame'])->name('casino.games.update');
    Route::get('/casino/rounds', [CasinoController::class, 'rounds'])->name('casino.rounds');
    Route::get('/casino/sessions', [CasinoController::class, 'sessions'])->name('casino.sessions');
    Route::get('/sport/status', [SportAdminController::class, 'status'])->name('sport.status');
    Route::get('/sport/leagues', [SportAdminController::class, 'leagues'])->name('sport.leagues');
    Route::put('/sport/leagues/{league}', [SportAdminController::class, 'updateLeague'])->name('sport.leagues.update');
    Route::get('/sport/translations', [SportAdminController::class, 'translations'])->name('sport.translations');
    Route::put('/sport/translations', [SportAdminController::class, 'updateTranslation'])->name('sport.translations.update');
    Route::get('/sport/margins', [SportAdminController::class, 'margins'])->name('sport.margins');
    Route::post('/sport/margins', [SportAdminController::class, 'storeMargin'])->name('sport.margins.store');
});
