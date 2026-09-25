<?php

namespace App\Http\Controllers\Panel;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\SportLeague;
use App\Models\SportMargin;
use App\Models\SportSyncState;
use App\Services\Sport\FootballBudget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SportAdminController extends Controller
{
    public function status(FootballBudget $budget): View
    {
        abort_unless(auth()->user()->role === UserRole::Owner, 404);

        return view('panel.sport.status', [
            'used' => $budget->used(),
            'remaining' => $budget->remaining(),
            'states' => SportSyncState::query()->orderBy('code')->get(),
        ]);
    }

    public function leagues(): View
    {
        abort_unless(auth()->user()->role === UserRole::Owner, 404);

        return view('panel.sport.leagues', [
            'leagues' => SportLeague::query()->with('country')->orderBy('sort_order')->get(),
        ]);
    }

    public function updateLeague(Request $request, SportLeague $league): RedirectResponse
    {
        abort_unless($request->user()->role === UserRole::Owner, 404);
        $league->is_active = $request->boolean('is_active');
        $league->is_featured = $request->boolean('is_featured');
        $league->sort_order = (int) $request->input('sort_order', $league->sort_order);
        $league->save();

        return back();
    }

    public function margins(): View
    {
        abort_unless(auth()->user()->role === UserRole::Owner, 404);

        return view('panel.sport.margins', [
            'margins' => SportMargin::query()->orderBy('id')->get(),
        ]);
    }

    public function storeMargin(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === UserRole::Owner, 404);
        SportMargin::query()->updateOrCreate(
            [
                'superadmin_id' => $request->input('superadmin_id') ?: null,
                'layer' => $request->string('layer')->toString(),
                'league_id' => $request->input('league_id') ?: null,
                'fixture_id' => $request->input('fixture_id') ?: null,
                'market_code' => $request->input('market_code') ?: null,
            ],
            [
                'margin' => bcdiv((string) $request->input('margin_percent', '0'), '100', 4),
                'max_odd' => $request->input('max_odd') ?: null,
            ],
        );

        return back();
    }
}
