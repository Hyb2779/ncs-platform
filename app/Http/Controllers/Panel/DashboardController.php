<?php

namespace App\Http\Controllers\Panel;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Sport\FootballBudget;
use App\Services\Stats\PanelDashboard;
use App\Services\Stats\TodaySummary;
use App\Support\GgrPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, PanelDashboard $dashboard, FootballBudget $budget, TodaySummary $today): View|JsonResponse
    {
        $actor = $request->user();
        $data = match ($actor->role) {
            UserRole::Owner => $dashboard->owner($actor, $budget),
            UserRole::Superadmin => $dashboard->superadmin($actor),
            default => $dashboard->bayi($actor),
        };
        $data['today'] = $today->for($actor, $data['currency'] ?? null);

        if ($this->wantsData($request)) {
            return $this->json($actor, $data, $request->boolean('export') ? 'dashboard.json' : null);
        }

        $view = match ($actor->role) {
            UserRole::Owner => 'panel.dashboard.owner',
            UserRole::Superadmin => 'panel.dashboard.superadmin',
            default => 'panel.dashboard.bayi',
        };

        return view($view, $data);
    }

    public function show(Request $request, User $user, PanelDashboard $dashboard, TodaySummary $today): View|JsonResponse
    {
        $actor = $request->user();

        if ($user->role === UserRole::Superadmin) {
            abort_unless($actor->role === UserRole::Owner && $user->isInSubtreeOf($actor), 404);
        } else {
            abort_unless($user->role === UserRole::Bayi, 404);
            abort_unless($actor->role !== UserRole::Bayi && $user->isInSubtreeOf($actor), 404);
        }

        $data = $dashboard->account($actor, $user);
        $data['today'] = $today->for($actor, $data['currency'] ?? null);

        if ($this->wantsData($request)) {
            return $this->json($actor, $data, $request->boolean('export') ? 'dashboard.json' : null);
        }

        return view('panel.dashboard.account', $data);
    }

    /** @param  array<string, mixed>  $data */
    private function json(User $actor, array $data, ?string $download): JsonResponse
    {
        $payload = GgrPayload::present($actor, [
            'cards' => $data['cards'] ?? [],
            'line' => $data['line'] ?? [],
            'products' => $data['products'] ?? [],
            'rank' => $data['rank'] ?? null,
            'players' => $data['players'] ?? [],
            'columns' => $data['columns'] ?? [],
            'rows' => $data['rows'] ?? [],
            'today' => $data['today'] ?? [],
        ]);
        $response = response()->json($payload);
        if ($download !== null) {
            $response->header('Content-Disposition', 'attachment; filename="'.$download.'"');
        }

        return $response;
    }

    private function wantsData(Request $request): bool
    {
        return $request->wantsJson() || $request->boolean('export');
    }
}
