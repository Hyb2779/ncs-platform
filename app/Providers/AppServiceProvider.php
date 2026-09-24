<?php

namespace App\Providers;

use App\Models\User;
use App\Services\WalletProvisioner;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Route::bind('user', function (string $value) {
            $actor = auth()->user();
            abort_if($actor === null, 403);

            return User::query()->subtreeOf($actor)->whereKey($value)->firstOrFail();
        });

        User::created(function (User $user): void {
            app(WalletProvisioner::class)->openFor($user);
        });

        View::composer('layouts.panel', function ($view): void {
            $user = auth()->user();

            $view->with(
                'headerWallets',
                $user === null
                    ? collect()
                    : $user->wallets()->orderBy('currency')->get(),
            );
        });
    }
}
