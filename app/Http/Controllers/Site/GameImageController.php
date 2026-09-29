<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\CasinoGame;
use App\Services\GameImages;

class GameImageController extends Controller
{
    public function __invoke(string $file, GameImages $images)
    {
        if (! preg_match('/^(\d+)-([0-9a-f]{8})\.webp$/', $file, $m)) {
            abort(404);
        }

        $game = CasinoGame::query()->find((int) $m[1]);
        if ($game === null || $images->file($game) !== 'cache/g/'.$file) {
            abort(404);
        }

        if ($images->exists($game) || $images->generate($game)) {
            return response()->file(public_path($images->file($game)), [
                'Content-Type' => 'image/webp',
                'Cache-Control' => 'public, max-age=2592000, immutable',
            ]);
        }

        return redirect()->away((string) $game->image_url);
    }
}
