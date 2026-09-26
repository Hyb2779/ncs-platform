<?php

namespace App\Http\Controllers\Panel;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\SportWarning;
use App\Models\User;
use App\Services\Sport\FootballBudget;
use App\Services\Stats\PanelDashboard;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(PanelDashboard $dashboard, FootballBudget $budget): View
    {
        $actor = request()->user();

        if ($actor->role === UserRole::Owner) {
            return view('panel.dashboard.owner', $dashboard->owner($actor, $budget));
        }

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

    public function show(User $user, PanelDashboard $dashboard): View
    {
        $actor = request()->user();
        abort_unless($actor->role === UserRole::Owner, 404);
        abort_unless($user->role === UserRole::Superadmin, 404);

        return view('panel.dashboard.account', $dashboard->account($actor, $user));
    }
}
