<?php

namespace App\Http\Controllers\Panel;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\CasinoGame;
use App\Models\HomeSlide;
use App\Services\HomeSlides;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class HomeSlideController extends Controller
{
    public function __construct(private readonly HomeSlides $slides) {}

    public function index(Request $request): View
    {
        $this->owner($request);
        $this->slides->ensureDefaults();
        $q = trim((string) $request->query('q', ''));
        $found = $q === '' ? collect() : CasinoGame::query()
            ->with('provider')
            ->where('is_active', true)
            ->where('name', 'like', '%'.$q.'%')
            ->where(fn ($query) => $query->where('is_live', true)->orWhere('category', 'mini')->orWhere(fn ($slot) => $slot->where('is_live', false)->where(fn ($w) => $w->whereNull('category')->orWhere('category', '!=', 'virtual'))))
            ->whereHas('provider', fn ($query) => $query->where('status', 'active'))
            ->orderBy('name')
            ->limit(12)
            ->get();

        return view('panel.home-slides.index', [
            'slides' => HomeSlide::query()->with('game.provider')->orderBy('sort_order')->orderBy('id')->get(),
            'found' => $found,
            'q' => $q,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->owner($request);
        $data = $request->validate(['game_id' => ['required', 'integer']]);
        $game = CasinoGame::query()->whereKey($data['game_id'])->where('is_active', true)->first();
        abort_unless($game !== null && $game->category !== 'virtual', 422);

        if (HomeSlide::query()->where('game_id', $game->id)->whereNull('key')->exists()) {
            return back()->withErrors(['game_id' => __('panel.home_slides_duplicate')]);
        }

        $max = (int) HomeSlide::query()->max('sort_order');
        HomeSlide::query()->create([
            'game_id' => $game->id,
            'sort_order' => $max + 10,
            'is_active' => true,
        ]);
        $this->slides->forget();

        return back()->with('status', __('panel.home_slides_saved'));
    }

    public function active(Request $request, HomeSlide $slide): RedirectResponse
    {
        $this->owner($request);
        $slide->is_active = ! $slide->is_active;
        $slide->save();
        $this->slides->forget();

        return back()->with('status', __('panel.home_slides_saved'));
    }

    public function move(Request $request, HomeSlide $slide): RedirectResponse
    {
        $this->owner($request);
        $data = $request->validate(['direction' => ['required', 'in:up,down']]);
        $neighbor = HomeSlide::query()
            ->where('sort_order', $data['direction'] === 'up' ? '<' : '>', $slide->sort_order)
            ->orderBy('sort_order', $data['direction'] === 'up' ? 'desc' : 'asc')
            ->orderBy('id', $data['direction'] === 'up' ? 'desc' : 'asc')
            ->first();

        if ($neighbor !== null) {
            $order = $slide->sort_order;
            $slide->sort_order = $neighbor->sort_order;
            $neighbor->sort_order = $order;
            $slide->save();
            $neighbor->save();
            $this->slides->forget();
        }

        return back()->with('status', __('panel.home_slides_saved'));
    }

    public function image(Request $request, HomeSlide $slide): RedirectResponse
    {
        $this->owner($request);
        $data = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);
        if ($slide->image_path) {
            Storage::disk('public')->delete($slide->image_path);
        }
        $slide->image_path = $data['image']->store('home-slides', 'public');
        $slide->save();
        $this->slides->forget();

        return back()->with('status', __('panel.home_slides_saved'));
    }

    public function clearImage(Request $request, HomeSlide $slide): RedirectResponse
    {
        $this->owner($request);
        if ($slide->image_path) {
            Storage::disk('public')->delete($slide->image_path);
            $slide->image_path = null;
            $slide->save();
            $this->slides->forget();
        }

        return back()->with('status', __('panel.home_slides_saved'));
    }

    public function destroy(Request $request, HomeSlide $slide): RedirectResponse
    {
        $this->owner($request);
        abort_if($slide->isSystem(), 404);
        if ($slide->image_path) {
            Storage::disk('public')->delete($slide->image_path);
        }
        $slide->delete();
        $this->slides->forget();

        return back()->with('status', __('panel.home_slides_saved'));
    }

    private function owner(Request $request): void
    {
        abort_unless($request->user()?->role === UserRole::Owner, 404);
    }
}
