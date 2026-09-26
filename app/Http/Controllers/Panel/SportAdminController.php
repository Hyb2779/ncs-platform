<?php

namespace App\Http\Controllers\Panel;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\CouponSelection;
use App\Models\SportCountry;
use App\Models\SportFixture;
use App\Models\SportLeague;
use App\Models\SportLimit;
use App\Models\SportMargin;
use App\Models\SportSyncState;
use App\Models\SportTeam;
use App\Models\SportTranslation;
use App\Models\SportWarning;
use App\Services\Sport\CouponSettler;
use App\Services\Sport\FootballBudget;
use App\Services\Sport\SportLimits;
use App\Services\Sport\SportTranslator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class SportAdminController extends Controller
{
    public function status(FootballBudget $budget): View
    {
        abort_unless(auth()->user()->role === UserRole::Owner, 404);

        $voidStatuses = [...config('sport.void_statuses'), ...config('sport.wait_statuses')];
        $approachFrom = now()->subHours((int) config('sport.void_after_hours'))->addHours(6);

        return view('panel.sport.status', [
            'used' => $budget->used(),
            'remaining' => $budget->remaining(),
            'liveRequests' => $budget->usedChannel('live-sync'),
            'liveSync' => SportSyncState::query()->where('code', 'live-sync')->first(),
            'states' => SportSyncState::query()->where('code', '!=', 'live-sync')->orderBy('code')->get(),
            'settleCheck' => SportSyncState::query()->where('code', 'settle-check')->first(),
            'pendingSettlements' => CouponSelection::query()
                ->where('status', 'pending')
                ->where('kickoff_at', '<=', now()->subMinutes((int) config('sport.settle_after_minutes')))
                ->count(),
            'approaching' => CouponSelection::query()
                ->with(['fixture.home', 'fixture.away'])
                ->where('status', 'pending')
                ->where('kickoff_at', '<=', $approachFrom)
                ->whereHas('fixture', fn ($query) => $query->whereIn('status', $voidStatuses))
                ->orderBy('kickoff_at')
                ->limit(50)
                ->get(),
            'stale' => SportWarning::query()->open()->where('type', SportWarning::Stale)
                ->with(['fixture.home', 'fixture.away'])
                ->orderByDesc('id')
                ->get(),
            'overdrafts' => SportWarning::query()->open()->where('type', SportWarning::Overdraft)
                ->with('user')
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function overdrafts(Request $request): View
    {
        return view('panel.sport.overdrafts', [
            'overdrafts' => SportWarning::query()
                ->open()
                ->where('type', SportWarning::Overdraft)
                ->visibleTo($request->user())
                ->with('user')
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function fixture(SportFixture $fixture): View
    {
        abort_unless(auth()->user()->role === UserRole::Owner, 404);
        $fixture->load(['home', 'away', 'league']);

        return view('panel.sport.fixture', [
            'fixture' => $fixture,
            'coupons' => Coupon::query()
                ->whereHas('selections', fn ($query) => $query->where('fixture_id', $fixture->id))
                ->with(['user', 'selections'])
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function updateScore(Request $request, SportFixture $fixture, CouponSettler $settler): RedirectResponse
    {
        abort_unless($request->user()->role === UserRole::Owner, 404);
        $data = $request->validate([
            'ht_home' => ['required', 'integer', 'min:0', 'max:99'],
            'ht_away' => ['required', 'integer', 'min:0', 'max:99'],
            'ft_home' => ['required', 'integer', 'min:0', 'max:99'],
            'ft_away' => ['required', 'integer', 'min:0', 'max:99'],
            'played_at' => ['required', 'date'],
        ]);

        $fixture->ht_home = (string) $data['ht_home'];
        $fixture->ht_away = (string) $data['ht_away'];
        $fixture->ft_home = $data['ft_home'];
        $fixture->ft_away = $data['ft_away'];
        $fixture->played_at = Carbon::parse($data['played_at'], $request->user()->timezone)->utc();
        $fixture->status = 'FT';
        $fixture->score_source = 'manual';
        $fixture->settled_at = now();
        $fixture->save();

        SportWarning::query()
            ->where('type', SportWarning::Stale)
            ->where('fixture_id', $fixture->id)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now()]);

        $coupons = Coupon::query()
            ->whereNot('status', 'cancelled')
            ->whereHas('selections', fn ($query) => $query->where('fixture_id', $fixture->id))
            ->get();
        foreach ($coupons as $coupon) {
            $settler->correct($coupon, $request->user(), $fixture->id);
        }

        return back()->with('status', __('sport.panel.score_saved'));
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

    public function limits(SportLimits $limits): View
    {
        $user = auth()->user();
        abort_unless(in_array($user->role, [UserRole::Owner, UserRole::Superadmin], true), 404);
        $row = $user->role === UserRole::Owner
            ? $limits->global()
            : SportLimit::query()->firstOrNew(['superadmin_id' => $user->id], $limits->global()->only([
                'min_stake', 'max_stake', 'max_win', 'combo_min', 'combo_max', 'min_total_odds', 'min_odd', 'daily_max', 'cancel_minutes',
            ]));

        return view('panel.sport.limits', ['limit' => $row]);
    }

    public function updateLimits(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, [UserRole::Owner, UserRole::Superadmin], true), 404);
        $data = $request->validate([
            'min_stake' => ['required', 'numeric', 'min:0.01'],
            'max_stake' => ['required', 'numeric', 'min:0.01'],
            'max_win' => ['required', 'numeric', 'min:0.01'],
            'combo_min' => ['required', 'integer', 'min:2'],
            'combo_max' => ['required', 'integer', 'min:2'],
            'min_total_odds' => ['required', 'numeric', 'min:1.01'],
            'min_odd' => ['required', 'numeric', 'min:1.01'],
            'daily_max' => ['required', 'numeric', 'min:0.01'],
            'cancel_minutes' => ['required', 'integer', 'min:0'],
        ]);
        SportLimit::query()->updateOrCreate(
            ['superadmin_id' => $user->role === UserRole::Owner ? null : $user->id],
            $data,
        );

        return back()->with('status', __('sport.panel.saved'));
    }
}
