<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Route;
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
    }
}
