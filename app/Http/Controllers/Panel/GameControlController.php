<?php

namespace App\Http\Controllers\Panel;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\CasinoGame;
use App\Models\CasinoProvider;
use App\Models\GameBlock;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Casino\GameAvailability;
use App\Support\Vendors;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Oyun Yonetimi: Owner genel, superadmin kendi agaci icin oyun ac/kapat. */
class GameControlController extends Controller
{
    public function __construct(
        private readonly GameAvailability $availability,
        private readonly ActivityLogger $activity,
    ) {}

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless(in_array($user->role, [UserRole::Owner, UserRole::Superadmin], true), 404);

        return $user;
    }

    /** Ayar yapilabilecek hedefler: aktorun agacindaki alt owner / superadmin / bayi (kendisi haric). */
    private function targets(User $actor)
    {
        return User::query()->subtreeOf($actor)->whereKeyNot($actor->id)
            ->whereIn('role', [UserRole::Owner, UserRole::Superadmin, UserRole::Bayi])
            ->orderBy('path')->get(['id', 'username', 'role', 'path', 'parent_id']);
    }

    /** Istekteki hedef (bos/kendisi = aktor). Agac disi 404. */
    private function target(Request $request, User $actor): User
    {
        $id = (int) $request->input('target', 0);
        if ($id <= 0 || $id === (int) $actor->id) {
            return $actor;
        }
        abort_if($this->targets($actor)->firstWhere('id', $id) === null, 404);

        return User::query()->findOrFail($id);
    }

    private function base(): Builder
    {
        return CasinoGame::query()
            ->where('is_active', true)
            ->whereHas('provider', fn ($q) => $q->where('status', 'active'));
    }

    private function inCategory(Builder $q, string $category): Builder
    {
        return match ($category) {
            'live' => $q->where('is_live', true),
            'mini' => $q->where('is_live', false)->where('category', 'mini'),
            default => $q->where('is_live', false)->where(fn ($w) => $w->whereNull('category')->orWhereNotIn('category', ['mini', 'virtual'])),
        };
    }

    public function index(Request $request): View
    {
        $user = $this->actor($request);
        $target = $this->target($request, $user);
        // Hedef kok owner ise engeller NULL (genel). Diger hedefler kendi id'si; ustlerin engelleri 'global' (kilitli) gorunur.
        $sid = $target->isRootOwner() ? null : (int) $target->id;
        $scopeIds = GameAvailability::scopeIdsFor($target);

        // durum[scope][value] = 'global' (Owner kapatti) | 'own' (bu superadmin kapatti)
        $state = array_fill_keys(GameAvailability::SCOPES, []);
        GameBlock::query()
            ->where(function ($q) use ($scopeIds) {
                $q->whereNull('superadmin_id');
                if ($scopeIds !== []) {
                    $q->orWhereIn('superadmin_id', $scopeIds);
                }
            })
            ->get(['superadmin_id', 'scope', 'value'])
            ->each(function ($b) use (&$state, $sid) {
                if (! isset($state[$b->scope])) {
                    return;
                }
                $kind = $sid !== null && $b->superadmin_id !== null && (int) $b->superadmin_id === $sid ? 'own' : 'global';
                if (($state[$b->scope][$b->value] ?? null) !== 'global') {
                    $state[$b->scope][$b->value] = $kind;
                }
            });

        $providers = CasinoProvider::query()->where('status', 'active')
            ->withCount(['games' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('name')->get()->filter(fn ($p) => $p->games_count > 0)->values();

        $categories = collect(GameAvailability::CATEGORIES)->map(fn ($c) => [
            'value' => $c,
            'count' => $this->inCategory($this->base(), $c)->count(),
        ]);

        $vendors = $this->base()->whereNotNull('vendor')
            ->selectRaw('vendor, COUNT(*) AS c')->groupBy('vendor')->orderByDesc('c')->get()
            ->map(fn ($r) => ['value' => $r->vendor, 'name' => Vendors::name($r->vendor) ?? $r->vendor, 'count' => (int) $r->c]);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'category' => ['nullable', Rule::in(GameAvailability::CATEGORIES)],
            'vendor' => ['nullable', 'string', 'max:32'],
            'state' => ['nullable', Rule::in(['open', 'closed'])],
        ]);

        $games = $this->base()->with('provider');
        if (($filters['q'] ?? '') !== '') {
            $games->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $filters['q']).'%');
        }
        if (! empty($filters['category'])) {
            $this->inCategory($games, $filters['category']);
        }
        if (! empty($filters['vendor'])) {
            $games->where('vendor', $filters['vendor']);
        }
        if (! empty($filters['state'])) {
            $ids = array_map('intval', array_keys($state['game']));
            $filters['state'] === 'closed' ? $games->whereIn('id', $ids ?: [0]) : $games->whereNotIn('id', $ids);
        }

        return view('panel.games.index', [
            'isOwner' => $target->isRootOwner(), // hedef genel kapsam: her engeli acip kapatabilir
            // 04.10 (Volkan/Blackeagle): kok owner disinda sadece kategori + urun (toptan ac/kapat); saglayici/marka/oyun yok.
            'limited' => ! $user->isRootOwner(),
            'targets' => $this->targets($user),
            'target' => $target,
            'targetParam' => $target->id === $user->id ? '' : (string) $target->id,
            'actorIsRoot' => $user->isRootOwner(),
            'actorId' => (int) $user->id,
            'canCurate' => $user->role === UserRole::Owner, // populer/sira (sistem ayari; alt owner da)
            'state' => $state,
            'providers' => $providers,
            'categories' => $categories,
            'vendors' => $vendors,
            'filters' => $filters,
            'games' => $games->orderByDesc('is_popular')->orderBy('sort_order')->orderBy('name')->paginate(50)->withQueryString(),
        ]);
    }

    public function toggle(Request $request): RedirectResponse
    {
        $user = $this->actor($request);
        $target = $this->target($request, $user);
        $data = $request->validate([
            'scope' => ['required', Rule::in(GameAvailability::SCOPES)],
            'value' => ['required', 'array', 'min:1', 'max:200'],
            'value.*' => ['required', 'string', 'max:64', 'distinct'],
            'blocked' => ['required', 'boolean'],
        ]);
        abort_if(! $user->isRootOwner() && ! in_array($data['scope'], ['category', 'product'], true), 403);
        $values = array_values($data['value']);

        $valid = match ($data['scope']) {
            'provider' => CasinoProvider::query()->whereIn('code', $values)->count(),
            'vendor' => CasinoGame::query()->whereIn('vendor', $values)->distinct()->count('vendor'),
            'category' => count(array_intersect($values, GameAvailability::CATEGORIES)),
            'product' => count(array_intersect($values, GameAvailability::PRODUCTS)),
            'game' => CasinoGame::query()->whereIn('id', array_map('intval', $values))->count(),
        };
        abort_unless($valid === count($values), 422);

        $sid = $target->isRootOwner() ? null : (int) $target->id;
        $blocked = (bool) $data['blocked'];

        DB::transaction(function () use ($user, $sid, $data, $values, $blocked) {
            // Ayni anda gelen istekler cift kayit uretmesin
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $mine = fn () => GameBlock::query()
                ->where('scope', $data['scope'])
                ->when($sid === null, fn ($q) => $q->whereNull('superadmin_id'), fn ($q) => $q->where('superadmin_id', $sid));

            if ($blocked) {
                $have = $mine()->whereIn('value', $values)->pluck('value')->all();
                foreach (array_diff($values, $have) as $value) {
                    GameBlock::query()->create([
                        'superadmin_id' => $sid,
                        'scope' => $data['scope'],
                        'value' => $value,
                        'created_by' => $user->id,
                    ]);
                }
            } else {
                // Superadmin sadece kendi kaydini siler; Owner'in genel engeli kalir
                $mine()->whereIn('value', $values)->delete();
            }
        });

        $this->availability->flush();
        $this->activity->write($user, $blocked ? 'casino.games_blocked' : 'casino.games_unblocked', null, [
            'scope' => $data['scope'],
            'values' => array_slice($values, 0, 50),
            'count' => count($values),
            'superadmin_id' => $sid,
            'target' => (int) $target->id,
        ]);

        return back()->with('status', __('panel.games_saved'));
    }
}
