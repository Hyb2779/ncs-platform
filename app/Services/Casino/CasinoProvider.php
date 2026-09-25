<?php

namespace App\Services\Casino;

use App\Models\CasinoGame;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

interface CasinoProvider
{
    public function code(): string;

    public function syncGames(): int;

    public function launch(User $user, CasinoGame $game, string $device): string;

    public function handleCallback(Request $request): Response;
}
