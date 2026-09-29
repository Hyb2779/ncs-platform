<?php

namespace Tests\Feature;

use App\Models\CasinoGame;
use App\Models\CasinoProvider;
use App\Services\GameImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GameImagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_image_is_resized_to_webp_and_served(): void
    {
        $img = imagecreatetruecolor(800, 600);
        ob_start();
        imagepng($img);
        $png = ob_get_clean();
        Http::fake(['https://cdn.test/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);

        $provider = CasinoProvider::query()->create(['code' => 'imgtest', 'name' => 'ImgTest', 'status' => 'active', 'is_live' => false]);
        $game = CasinoGame::query()->create(['provider_id' => $provider->id, 'external_id' => 'g1', 'name' => 'Game', 'category' => 'slots',
            'image_url' => 'https://cdn.test/g1.png', 'is_live' => false, 'is_active' => true, 'sort_order' => 0]);

        $images = app(GameImages::class);
        $url = $images->url($game);
        @unlink(public_path($images->file($game)));

        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp');
        [$w] = getimagesize(public_path($images->file($game)));
        $this->assertSame(GameImages::WIDTH, $w);

        $this->get('/cache/g/'.$game->id.'-00000000.webp')->assertNotFound();
        @unlink(public_path($images->file($game)));
    }
}
