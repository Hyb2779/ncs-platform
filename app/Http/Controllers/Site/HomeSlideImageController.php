<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\HomeSlide;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class HomeSlideImageController extends Controller
{
    public function __invoke(HomeSlide $slide): BinaryFileResponse
    {
        abort_unless($slide->image_path && Storage::disk('public')->exists($slide->image_path), 404);

        return response()->file(Storage::disk('public')->path($slide->image_path));
    }
}
