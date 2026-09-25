<?php

namespace App\Http\Controllers\Panel;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\SportCountry;
use App\Models\SportLeague;
use App\Models\SportMargin;
use App\Models\SportSyncState;
use App\Models\SportTeam;
use App\Models\SportTranslation;
use App\Services\Sport\FootballBudget;
use App\Services\Sport\SportTranslator;
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

    public function translations(Request $request, SportTranslator $translator): View
    {
        abort_unless(auth()->user()->role === UserRole::Owner, 404);
        $type = in_array($request->query('type'), ['team', 'league', 'country'], true) ? (string) $request->query('type') : 'team';
        $locale = in_array($request->query('locale'), ['tr', 'en', 'de', 'ar'], true) ? (string) $request->query('locale') : 'ar';
        $table = match ($type) {
            'league' => 'sport_leagues',
            'country' => 'sport_countries',
            default => 'sport_teams',
        };
        $query = match ($type) {
            'league' => SportLeague::query(),
            'country' => SportCountry::query(),
            default => SportTeam::query(),
        };

        if ($request->filled('q')) {
            $term = '%'.$request->string('q').'%';
            $query->where(function ($inner) use ($term, $type, $locale): void {
                $inner->where('name', 'like', $term)->orWhereExists(function ($exists) use ($term, $type, $locale): void {
                    $exists->selectRaw('1')->from('sport_translations')
                        ->whereColumn('sport_translations.entity_id', $type === 'league' ? 'sport_leagues.id' : ($type === 'country' ? 'sport_countries.id' : 'sport_teams.id'))
                        ->where('entity_type', $type)
                        ->where('locale', $locale)
                        ->where('name', 'like', $term);
                });
            });
        }

        if ($request->boolean('missing')) {
            $query->whereNotExists(function ($exists) use ($type, $locale, $table): void {
                $exists->selectRaw('1')->from('sport_translations')
                    ->whereColumn('sport_translations.entity_id', $table.'.id')
                    ->where('entity_type', $type)
                    ->where('locale', $locale);
            });
        }

        $rows = $query->orderBy('name')->limit(200)->get();
        $names = SportTranslation::query()
            ->where('entity_type', $type)
            ->where('locale', $locale)
            ->whereIn('entity_id', $rows->pluck('id'))
            ->pluck('name', 'entity_id');

        return view('panel.sport.translations', [
            'type' => $type,
            'locale' => $locale,
            'rows' => $rows,
            'names' => $names,
            'pending' => $translator->pendingCount(),
        ]);
    }

    public function updateTranslation(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === UserRole::Owner, 404);
        $data = $request->validate([
            'entity_type' => ['required', 'in:team,league,country'],
            'entity_id' => ['required', 'integer'],
            'locale' => ['required', 'in:tr,en,de,ar'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        SportTranslation::query()->updateOrCreate(
            [
                'entity_type' => $data['entity_type'],
                'entity_id' => $data['entity_id'],
                'locale' => $data['locale'],
            ],
            ['name' => $data['name'], 'source' => 'manual'],
        );

        return back();
    }
}
