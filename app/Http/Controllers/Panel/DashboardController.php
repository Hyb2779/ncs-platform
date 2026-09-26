<?php

namespace App\Http\Controllers\Panel;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
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

        if ($actor->role === UserRole::Superadmin) {
            return view('panel.dashboard.superadmin', $dashboard->superadmin($actor));
        }

        return view('panel.dashboard.bayi', $dashboard->bayi($actor));
    }

    public function show(User $user, PanelDashboard $dashboard): View
    {
        $actor = request()->user();

        if ($user->role === UserRole::Superadmin) {
            abort_unless($actor->role === UserRole::Owner, 404);

            return view('panel.dashboard.account', $dashboard->account($actor, $user));
        }

        abort_unless($user->role === UserRole::Bayi, 404);
        abort_unless($actor->role !== UserRole::Bayi && $user->isInSubtreeOf($actor), 404);

        return view('panel.dashboard.account', $dashboard->account($actor, $user));
    }
}
