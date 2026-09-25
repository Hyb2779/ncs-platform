<?php

namespace App\Services\Casino;

use App\Models\CasinoGame;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Support\Str;

class GameLauncher
{
    public function __construct(private readonly ProviderRegistry $providers) {}

    public function open(User $user, CasinoGame $game, string $device, ?string $ip): string
    {
        $provider = $game->provider;

        if (! $game->is_active || $provider === null || ! $provider->isActive()) {
            abort(404);
        }

        $driver = $this->providers->get($provider->code);

        if ($driver === null || ($provider->code === 'demo' && app()->isProduction())) {
            abort(404);
        }

        $url = $driver->launch($user, $game, $device);

        GameSession::query()->create([
            'user_id' => $user->id,
            'game_id' => $game->id,
            'provider' => $provider->code,
            'token' => (string) Str::uuid(),
            'opened_at' => now(),
            'ip' => $ip,
            'device' => $device,
        ]);

        return $url;
    }
}
