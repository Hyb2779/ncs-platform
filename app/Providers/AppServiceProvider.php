<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Money;
use App\Services\Casino\DemoProvider;
use App\Services\Casino\GoldPalaceProvider;
use App\Services\Casino\OneGameXProvider;
use App\Services\Casino\ProviderRegistry;
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
        $this->app->singleton(ProviderRegistry::class, function ($app): ProviderRegistry {
            $providers = [
                'goldpalace' => $app->make(GoldPalaceProvider::class),
                'onegamex' => $app->make(OneGameXProvider::class),
            ];

            if (! $app->isProduction()) {
                $providers['demo'] = $app->make(DemoProvider::class);
            }

            return new ProviderRegistry($providers);
        });
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

        View::composer('layouts.site', function ($view): void {
            $user = auth()->user();
            $wallet = $user?->wallet()->first();
            $view->with('headerBalance', $wallet === null ? '' : Money::format((string) $wallet->balance, $wallet->currency));
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
