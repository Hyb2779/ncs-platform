<?php

use App\Enums\UserRole;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Panel\DashboardController;
use App\Http\Controllers\Panel\UserController;
use App\Http\Middleware\EnsurePanelUser;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $user = auth()->user();

    if ($user !== null && $user->role !== UserRole::Uye) {
        return redirect('/panel');
    }

    return view('welcome');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware(['auth', EnsurePanelUser::class])->prefix('panel')->name('panel.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
});
