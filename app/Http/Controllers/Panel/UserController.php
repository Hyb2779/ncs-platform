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

        $balance = \App\Models\Wallet::query()
            ->select('balance')
            ->whereColumn('wallets.user_id', 'users.id')
            ->whereColumn('wallets.currency', 'users.currency')
            ->limit(1);

        $base = User::query()
            ->subtreeOf($actor)
            ->where('parent_id', $parent->id)
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
            ->orderBy('username')
            ->paginate(50)
            ->withQueryString();

        return view('panel.users.index', [
            'parent' => $parent,
            'users' => $users,
            'summary' => $summary,
            'sort' => $sort,
            'breadcrumb' => $this->breadcrumb($actor, $parent),
            'canCreate' => $actor->role->childRole() !== null && ($parent->id === $actor->id || $parent->role->childRole() !== null) && $parent->role->childRole() !== null && $this->actorCreatesHere($actor, $parent),
        ]);
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
            ['password' => ['nullable', 'string', 'min:8', 'max:255']],
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

    public function create(Request $request, HierarchyService $hierarchy): View
    {
        $actor = $request->user();
        $parent = $request->filled('parent')
            ? $hierarchy->findInSubtree($actor, (int) $request->query('parent'))
            : $actor;

        abort_unless($this->actorCreatesHere($actor, $parent), 404);

        return view('panel.users.create', [
            'parent' => $parent,
            'breadcrumb' => $this->breadcrumb($actor, $parent),
        ]);
    }

    public function store(StoreUserRequest $request, HierarchyService $hierarchy): RedirectResponse
    {
        $actor = $request->user();
        $parent = $request->filled('parent')
            ? $hierarchy->findInSubtree($actor, (int) $request->input('parent'))
            : $actor;

        abort_unless($this->actorCreatesHere($actor, $parent), 404);

        try {
            $hierarchy->create($parent, $request->validated());
        } catch (HierarchyException $exception) {
            return back()->withInput()->withErrors(['username' => __($exception->translationKey, $exception->replace)]);
        }

        return redirect()
            ->route('panel.users.index', ['parent' => $parent->id === $actor->id ? null : $parent->id])
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
            ->route('panel.users.index', ['parent' => $target->parent_id === $actor->id ? null : $target->parent_id])
            ->with('status', __('panel.user_updated'));
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
