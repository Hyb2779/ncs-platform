<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\SportWarning;
use App\Models\User;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $actor = request()->user();

        return view('panel.dashboard', [
            'childCount' => User::query()->subtreeOf($actor)->where('parent_id', $actor->id)->count(),
            'overdrafts' => SportWarning::query()
                ->open()
                ->where('type', SportWarning::Overdraft)
                ->visibleTo($actor)
                ->with('user')
                ->orderByDesc('id')
                ->limit(20)
                ->get(),
        ]);
    }
}
