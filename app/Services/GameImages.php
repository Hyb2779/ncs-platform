<?php

namespace App\Services;

use App\Models\CasinoGame;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sağlayıcı oyun görsellerini küçültüp WebP olarak public/cache/g altında saklar.
 * Dosya varsa nginx doğrudan verir; yoksa ilk istekte GameImageController üretir.
 */
class GameImages
{
    public const WIDTH = 360;

    public const MAX_BYTES = 8 * 1024 * 1024;

    public function file(CasinoGame $game): string
    {
        return 'cache/g/'.$game->id.'-'.substr(md5((string) $game->image_url), 0, 8).'.webp';
    }

    public function url(CasinoGame $game): ?string
    {
        return $game->image_url ? '/'.$this->file($game) : null;
    }

    public function exists(CasinoGame $game): bool
    {
        return is_file(public_path($this->file($game)));
    }

    public function generate(CasinoGame $game): bool
    {
        if (! $game->image_url) {
            return false;
        }

        try {
            $response = Http::timeout(15)->withOptions(['stream' => false])->get($game->image_url);
            $body = $response->successful() ? $response->body() : '';
            if ($body === '' || strlen($body) > self::MAX_BYTES) {
                return false;
            }

            $src = @imagecreatefromstring($body);
            if ($src === false) {
                return false;
            }

            $w = imagesx($src);
            $dst = $w > self::WIDTH ? imagescale($src, self::WIDTH, -1, IMG_BICUBIC) : $src;
            imagepalettetotruecolor($dst);
            imagealphablending($dst, true);
            imagesavealpha($dst, true);

            $target = public_path($this->file($game));
            $tmp = $target.'.tmp'.getmypid();
            $ok = imagewebp($dst, $tmp, 72);
            if ($dst !== $src) {
                imagedestroy($dst);
            }
            imagedestroy($src);

            if (! $ok || ! @rename($tmp, $target)) {
                @unlink($tmp);

                return false;
            }
            @chmod($target, 0644);

            return true;
        } catch (\Throwable $e) {
            Log::warning('casino.image.generate_failed', ['game' => $game->id, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
