<?php

namespace App\Services;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class HierarchyService
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function create(User $actor, array $data): User
    {
        $role = $actor->role->childRole();

        if ($role === null) {
            throw new HierarchyException('hierarchy.cannot_create');
        }

        return DB::transaction(function () use ($actor, $data, $role) {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->assertCanOpenChild($actor);

            $inherited = $this->inheritedLocale($actor, $role, $data);

            $user = new User([
                'username' => $data['username'],
                'password' => $data['password'],
                'role' => $role,
                'parent_id' => $actor->id,
                'path' => '/',
                'depth' => $actor->depth + 1,
                'superadmin_id' => null,
                'language' => $inherited['language'],
                'currency' => $inherited['currency'],
                'timezone' => $inherited['timezone'],
                'commission_rate' => $data['commission_rate'],
                'status' => UserStatus::Active,
                'user_limit' => $data['user_limit'] ?? null,
                'note' => $data['note'] ?? null,
            ]);
            $user->save();

            $user->path = $actor->path.$user->id.'/';
            $user->superadmin_id = $role === UserRole::Superadmin ? $user->id : $actor->superadmin_id;
            $user->save();

            $this->activity->write($actor, 'user.created', $user, [
                'role' => $user->role->value,
                'username' => $user->username,
            ]);

            return $user->refresh();
        });
    }

    public function update(User $actor, User $target, array $data): User
    {
        $this->assertManageable($actor, $target);

        return DB::transaction(function () use ($actor, $target, $data) {
            $target = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $this->assertManageable($actor, $target);

            $changes = [];

            if (array_key_exists('commission_rate', $data)) {
                $target->commission_rate = $data['commission_rate'];
                $changes['commission_rate'] = $data['commission_rate'];
            }

            if (array_key_exists('user_limit', $data)) {
                $target->user_limit = $data['user_limit'];
                $changes['user_limit'] = $data['user_limit'];
            }

            if (array_key_exists('note', $data)) {
                $target->note = $data['note'];
                $changes['note'] = $data['note'];
            }

            if (! empty($data['password'])) {
                $target->password = $data['password'];
                $this->activity->write($actor, 'user.password_reset', $target);
            }

            if (isset($data['status']) && $data['status'] !== $target->status->value) {
                $previous = $target->status->value;
                $target->status = UserStatus::from($data['status']);
                $this->activity->write($actor, 'user.status_changed', $target, [
                    'from' => $previous,
                    'to' => $target->status->value,
                ]);
            }

            $target->save();

            if ($changes !== []) {
                $this->activity->write($actor, 'user.updated', $target, $changes);
            }

            return $target->refresh();
        });
    }

    public function findInSubtree(User $actor, int $id): User
    {
        $user = User::query()->subtreeOf($actor)->whereKey($id)->first();

        if ($user === null) {
            abort(404);
        }

        return $user;
    }

    public function assertManageable(User $actor, User $target): void
    {
        if ($target->id === $actor->id || $this->isAncestor($actor, $target)) {
            throw new HierarchyException('hierarchy.cannot_manage_self_or_ancestor');
        }

        if (! $target->isInSubtreeOf($actor)) {
            abort(404);
        }
    }

    public function loginBlocked(User $user): bool
    {
        $ids = array_values(array_filter(explode('/', trim($user->path, '/'))));
        $chain = User::query()->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $id) {
            $member = $chain->get((int) $id);

            if ($member === null || $member->status !== UserStatus::Active) {
                return true;
            }
        }

        return false;
    }

    private function assertCanOpenChild(User $actor): void
    {
        if ($actor->role->childRole() === null) {
            throw new HierarchyException('hierarchy.cannot_create');
        }

        if ($actor->user_limit === null) {
            return;
        }

        $count = User::query()->where('parent_id', $actor->id)->count();

        if ($count >= $actor->user_limit) {
            throw new HierarchyException('hierarchy.user_limit_reached');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{language: Language, currency: Currency, timezone: string}
     */
    private function inheritedLocale(User $actor, UserRole $role, array $data): array
    {
        if ($role === UserRole::Superadmin) {
            return [
                'language' => Language::from($data['language']),
                'currency' => Currency::from($data['currency']),
                'timezone' => $data['timezone'],
            ];
        }

        return [
            'language' => $actor->language,
            'currency' => $actor->currency,
            'timezone' => $actor->timezone,
        ];
    }

    private function isAncestor(User $actor, User $target): bool
    {
        return $target->id !== $actor->id && str_starts_with($actor->path, $target->path);
    }
}
