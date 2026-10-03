<?php

namespace App\Http\Controllers\Panel;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use App\Services\HierarchyException;
use App\Services\HierarchyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request, HierarchyService $hierarchy): View
    {
        $actor = $request->user();
        $parent = $actor;

        if ($request->filled('parent')) {
            $parent = $hierarchy->findInSubtree($actor, (int) $request->query('parent'));
        }

        // Faz 4: Kullanicilar (sadece oyuncular) / Bayiler (superadmin + bayi; kok owner alt owner'i da gorur).
        $showTabs = $actor->role !== \App\Enums\UserRole::Bayi;
        $tab = $showTabs && $request->query('tab') === 'dealers' ? 'dealers' : 'members';
        $dealerRoles = match (true) {
            $actor->isRootOwner() => ['owner', 'superadmin', 'bayi'],
            $actor->role === \App\Enums\UserRole::Owner => ['superadmin', 'bayi'],
            default => ['bayi'],
        };

        $balance = \App\Models\Wallet::query()
            ->select('balance')
            ->whereColumn('wallets.user_id', 'users.id')
            ->whereColumn('wallets.currency', 'users.currency')
            ->limit(1);

        $base = User::query()
            ->subtreeOf($actor)
            ->where('users.path', 'like', $parent->path.'%')
            ->where('users.id', '!=', $parent->id)
            ->when($tab === 'members', fn ($query) => $query->where('role', 'uye'))
            ->when($tab === 'dealers', fn ($query) => $query->whereIn('role', $dealerRoles))
            ->when($request->filled('q'), function ($query) use ($request) {
                $query->where('username', 'like', '%'.$request->string('q').'%');
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $status = $request->string('status')->toString();
                if (in_array($status, array_column(UserStatus::cases(), 'value'), true)) {
                    $query->where('status', $status);
                }
            })
            ->when($request->filled('from'), function ($query) use ($request, $actor) {
                $query->where('created_at', '>=', Carbon::parse($request->query('from'), $actor->timezone)->startOfDay()->utc());
            })
            ->when($request->filled('to'), function ($query) use ($request, $actor) {
                $query->where('created_at', '<=', Carbon::parse($request->query('to'), $actor->timezone)->endOfDay()->utc());
            })
            ->when($request->boolean('funded'), fn ($query) => $query->where(clone $balance, '>', 0))
            ->when($request->boolean('idle'), fn ($query) => $query->where(
                fn ($inner) => $inner->whereNull('last_login_at')->orWhere('last_login_at', '<', now()->subDays(7))
            ));

        $summary = [
            'total' => (clone $base)->count(),
            'active' => (clone $base)->where('status', UserStatus::Active->value)->count(),
            'balances' => \Illuminate\Support\Facades\DB::table('wallets')
                ->join('users', 'users.id', '=', 'wallets.user_id')
                ->whereColumn('wallets.currency', 'users.currency')
                ->whereIn('users.id', (clone $base)->select('users.id'))
                ->groupBy('wallets.currency')
                ->selectRaw('wallets.currency AS currency, SUM(wallets.balance) AS total')
                ->pluck('total', 'currency'),
        ];

        $sort = in_array($request->query('sort'), ['username', 'balance', 'login'], true) ? $request->query('sort') : 'username';

        $users = (clone $base)
            ->with('wallets')
            ->withCount('children')
            ->when($sort === 'balance', fn ($query) => $query->orderByDesc(clone $balance))
            ->when($sort === 'login', fn ($query) => $query->orderByRaw('last_login_at IS NULL')->orderByDesc('last_login_at'))
            ->when($tab === 'dealers' && ! $request->filled('sort'), fn ($query) => $query->orderBy('path'))
            ->orderBy('username')
            ->paginate(50)
            ->withQueryString();

        [$parentNames, $memberCounts, $turnovers] = $this->extras($actor, $tab, $users->getCollection());
        $child = $actor->role->childRole();
        $createTab = ($child instanceof \BackedEnum ? $child->value : $child) === 'uye' ? 'members' : 'dealers';

        return view('panel.users.index', [
            'parent' => $parent,
            'tab' => $tab,
            'showTabs' => $showTabs,
            'parentNames' => $parentNames,
            'memberCounts' => $memberCounts,
            'turnovers' => $turnovers,
            'users' => $users,
            'summary' => $summary,
            'sort' => $sort,
            'breadcrumb' => $this->breadcrumb($actor, $parent),
            'canCreate' => $actor->role->childRole() !== null && ($parent->id === $actor->id || $parent->role->childRole() !== null) && $parent->role->childRole() !== null && $this->actorCreatesHere($actor, $parent) && $tab === $createTab,
        ]);
    }

    /**
     * Sayfadaki satirlar icin: oyuncunun bayi adi; bayilerde oyuncu sayisi ve bu ayin cirosu (daily_stats, para birimi bazinda).
     *
     * @return array{0: \Illuminate\Support\Collection, 1: array<int, int>, 2: array<int, string>}
     */
    private function extras(User $actor, string $tab, \Illuminate\Support\Collection $rows): array
    {
        $parentNames = User::query()->whereIn('id', $rows->pluck('parent_id')->filter()->unique()->values()->all() ?: [0])->pluck('username', 'id');
        $memberCounts = [];
        $turnovers = [];
        if ($tab !== 'dealers' || $rows->isEmpty()) {
            return [$parentNames, $memberCounts, $turnovers];
        }

        $statIds = [];
        foreach ($rows as $row) {
            $memberCounts[$row->id] = User::query()->where('role', 'uye')->where('path', 'like', $row->path.'%')->count();
            // Alt owner'in kendi daily_stats satiri yok: altindaki superadminlerin toplami
            $statIds[$row->id] = $row->role === \App\Enums\UserRole::Owner
                ? User::query()->where('parent_id', $row->id)->where('role', 'superadmin')->pluck('id')->all()
                : [$row->id];
        }
        $from = now($actor->timezone ?: 'Europe/Istanbul')->startOfMonth()->toDateString();
        $to = now($actor->timezone ?: 'Europe/Istanbul')->toDateString();
        $sums = \Illuminate\Support\Facades\DB::table('daily_stats')
            ->whereIn('user_id', array_merge(...array_values($statIds)) ?: [0])
            ->whereBetween('stat_date', [$from, $to])
            ->groupBy('user_id', 'currency')
            ->selectRaw('user_id, currency, SUM(turnover) AS total')
            ->get();
        foreach ($rows as $row) {
            $byCurrency = [];
            foreach ($sums as $sum) {
                if (in_array((int) $sum->user_id, $statIds[$row->id], true)) {
                    $byCurrency[$sum->currency] = bcadd($byCurrency[$sum->currency] ?? '0', (string) $sum->total, 2);
                }
            }
            $turnovers[$row->id] = $byCurrency === []
                ? \App\Support\Money::format('0', $row->currency)
                : collect($byCurrency)->map(fn ($total, $cur) => \App\Support\Money::format($total, \App\Enums\Currency::from($cur)))->implode(' · ');
        }

        return [$parentNames, $memberCounts, $turnovers];
    }

    public function toggleStatus(Request $request, User $user, HierarchyService $hierarchy): RedirectResponse
    {
        $actor = $request->user();
        $target = $hierarchy->findInSubtree($actor, $user->id);
        abort_if($target->id === $actor->id, 404);

        $next = match ($target->status) {
            UserStatus::Active => UserStatus::Passive->value,
            UserStatus::Passive => UserStatus::Active->value,
            default => null,
        };

        if ($next === null) {
            return back()->withErrors(['status' => __('panel.status_toggle_banned')]);
        }

        try {
            $hierarchy->update($actor, $target, ['status' => $next]);
        } catch (HierarchyException $exception) {
            return back()->withErrors(['status' => __($exception->translationKey)]);
        }

        return back()->with('status', __('panel.user_updated'));
    }

    public function resetPassword(Request $request, User $user, HierarchyService $hierarchy): RedirectResponse
    {
        $actor = $request->user();
        $target = $hierarchy->findInSubtree($actor, $user->id);
        abort_if($target->id === $actor->id, 404);

        $data = $request->validate(
            ['password' => ['nullable', 'string', 'min:4', 'max:255']],
            ['password.min' => __('panel.validation.password_min')],
        );
        $password = $data['password'] ?? \Illuminate\Support\Str::password(10, symbols: false);

        try {
            $hierarchy->update($actor, $target, ['password' => $password]);
        } catch (HierarchyException $exception) {
            return back()->withErrors(['status' => __($exception->translationKey)]);
        }

        $target->forceFill(['remember_token' => \Illuminate\Support\Str::random(60)])->save();

        if (config('session.driver') === 'database') {
            \Illuminate\Support\Facades\DB::table(config('session.table', 'sessions'))->where('user_id', $target->id)->delete();
        }

        return back()
            ->with('status', __('panel.password_reset_done'))
            ->with('reset_password', ['username' => $target->username, 'password' => $password]);
    }

    public function create(Request $request): View
    {
        $actor = $request->user();
        $roles = $this->creatableRoles($actor);
        abort_if($roles === [], 404);

        return view('panel.users.create', [
            'roles' => $roles,
            'defaultRole' => $roles[0],
            'parents' => $this->parentOptions($actor),
            'breadcrumb' => $this->breadcrumb($actor, $actor),
        ]);
    }

    public function store(StoreUserRequest $request, HierarchyService $hierarchy): RedirectResponse
    {
        $actor = $request->user();
        $roles = $this->creatableRoles($actor);
        $role = $request->validated('role') ?? ($roles[0] ?? null);
        abort_unless($role !== null && in_array($role, $roles, true), 404);

        $parent = $this->resolveParent($actor, $role, $request->integer('parent'));
        if ($parent === null) {
            return back()->withInput()->withErrors(['parent' => __('panel.create_parent_invalid')]);
        }

        try {
            $hierarchy->create($parent, $request->safe()->except(['role', 'parent']), $actor);
        } catch (HierarchyException $exception) {
            return back()->withInput()->withErrors(['username' => __($exception->translationKey, $exception->replace)]);
        }

        return redirect()
            ->route('panel.users.index', ['tab' => $this->tabFor($role)])
            ->with('status', __('panel.user_created'));
    }

    public function edit(Request $request, User $user, HierarchyService $hierarchy): View
    {
        $actor = $request->user();
        $target = $hierarchy->findInSubtree($actor, $user->id);

        try {
            $hierarchy->assertManageable($actor, $target);
        } catch (HierarchyException $exception) {
            abort(403, __($exception->translationKey));
        }

        return view('panel.users.edit', [
            'user' => $target,
            'breadcrumb' => $this->breadcrumb($actor, $target->parent ?? $actor),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user, HierarchyService $hierarchy): RedirectResponse
    {
        $actor = $request->user();
        $target = $hierarchy->findInSubtree($actor, $user->id);

        try {
            $hierarchy->update($actor, $target, $request->validated());
        } catch (HierarchyException $exception) {
            return back()->withErrors(['status' => __($exception->translationKey)]);
        }

        return redirect()
            ->route('panel.users.index', ['tab' => $this->tabFor($target->role), 'parent' => $target->parent_id === $actor->id ? null : $target->parent_id])
            ->with('status', __('panel.user_updated'));
    }

    /** Bayi → süperadmin altına, üye → bayi altına açılır. */
    private const PARENT_ROLE = ['bayi' => 'superadmin', 'uye' => 'bayi'];

    /** @return list<string> İlki bir alt seviye (üst hesap = kendisi). */
    private function creatableRoles(User $actor): array
    {
        return match ($actor->role) {
            \App\Enums\UserRole::Owner => ['superadmin', 'bayi', 'uye'],
            \App\Enums\UserRole::Superadmin => ['bayi', 'uye'],
            \App\Enums\UserRole::Bayi => ['uye'],
            default => [],
        };
    }

    private function resolveParent(User $actor, string $role, int $parentId): ?User
    {
        if ($actor->role->childRole()?->value === $role) {
            return $actor;
        }

        $parentRole = self::PARENT_ROLE[$role] ?? null;
        if ($parentRole === null || $parentId <= 0) {
            return null;
        }

        return User::query()->subtreeOf($actor)->whereKey($parentId)
            ->where('role', $parentRole)->where('status', 'active')->first();
    }

    /** @return array<string, list<array{id: int, label: string}>> */
    private function parentOptions(User $actor): array
    {
        $options = [];
        foreach (self::PARENT_ROLE as $role => $parentRole) {
            if (! in_array($role, $this->creatableRoles($actor), true) || $actor->role->childRole()?->value === $role) {
                continue;
            }

            $rows = User::query()->subtreeOf($actor)->where('role', $parentRole)->where('status', 'active')
                ->orderBy('path')->get(['id', 'username', 'superadmin_id', 'path']);
            $heads = $parentRole === 'bayi'
                ? User::query()->whereIn('id', $rows->pluck('superadmin_id')->filter()->unique())->pluck('username', 'id')
                : collect();

            $options[$role] = $rows->map(fn (User $row) => [
                'id' => $row->id,
                'label' => $row->username.($heads->has($row->superadmin_id) ? ' · '.$heads[$row->superadmin_id] : ''),
            ])->values()->all();
        }

        return $options;
    }

    /** Oyuncu → Kullanıcılar sekmesi, diğer roller → Bayiler sekmesi. */
    private function tabFor(mixed $role): string
    {
        return ($role instanceof \BackedEnum ? $role->value : $role) === 'uye' ? 'members' : 'dealers';
    }

    private function actorCreatesHere(User $actor, User $parent): bool
    {
        return $parent->id === $actor->id && $actor->role->childRole() !== null;
    }

    /**
     * @return list<User>
     */
    private function breadcrumb(User $actor, User $node): array
    {
        $ids = array_map('intval', array_values(array_filter(explode('/', trim($node->path, '/')))));
        $start = array_search($actor->id, $ids, true);

        if ($start === false) {
            abort(404);
        }

        $ids = array_slice($ids, $start);

        return User::query()
            ->subtreeOf($actor)
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (User $user) => array_search($user->id, $ids, true))
            ->values()
            ->all();
    }
}
