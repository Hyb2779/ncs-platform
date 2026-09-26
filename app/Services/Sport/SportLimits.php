<?php

namespace App\Services\Sport;

use App\Enums\Currency;
use App\Enums\UserRole;
use App\Models\SportLimit;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Validation\ValidationException;

class SportLimits
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function forUser(User $user): EffectiveSportLimit
    {
        return $this->merge($this->rows($user, true));
    }

    public function ceiling(User $user): EffectiveSportLimit
    {
        if ($user->role === UserRole::Owner) {
            return EffectiveSportLimit::open();
        }

        return $this->merge($this->rows($user, false));
    }

    public function owner(Currency $currency): SportLimit
    {
        return SportLimit::query()->whereNull('user_id')->where('currency', $currency->value)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function save(User $actor, Currency $currency, array $input): SportLimit
    {
        $this->guardActor($actor, $currency);
        $ceiling = $this->ceiling($actor);
        $values = $this->values($input, $ceiling);
        $row = $actor->role === UserRole::Owner
            ? $this->owner($currency)
            : SportLimit::query()->firstOrNew(['user_id' => $actor->id]);
        $row->fill($values);
        $row->user_id = $actor->role === UserRole::Owner ? null : $actor->id;
        $row->currency = $currency;
        $row->save();
        $this->activity->write($actor, 'sport.limits.updated', $actor, [
            'currency' => $currency->value,
            'values' => $this->payload($row),
        ]);

        return $row;
    }

    public function restore(User $actor, Currency $currency): void
    {
        $this->guardActor($actor, $currency);
        if ($actor->role === UserRole::Owner) {
            $row = $this->owner($currency);
            $row->fill(SportLimitCatalog::for($currency->value));
            $row->save();
        } else {
            SportLimit::query()->where('user_id', $actor->id)->delete();
        }
        $this->activity->write($actor, 'sport.limits.restored', $actor, ['currency' => $currency->value]);
    }

    /**
     * @return list<SportLimit>
     */
    private function rows(User $user, bool $includeSelf): array
    {
        $rows = [$this->owner($user->currency)];
        $ids = $this->chainIds($user, $includeSelf);
        if ($ids === []) {
            return $rows;
        }
        $saved = SportLimit::query()->whereIn('user_id', $ids)->get()->keyBy('user_id');
        foreach ($ids as $id) {
            $row = $saved->get($id);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return list<int>
     */
    private function chainIds(User $user, bool $includeSelf): array
    {
        $ids = array_map('intval', array_values(array_filter(explode('/', trim((string) $user->path, '/')))));
        if (! $includeSelf) {
            $ids = array_values(array_filter($ids, fn (int $id): bool => $id !== $user->id));
        }
        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $ids)
            ->whereIn('role', [UserRole::Superadmin, UserRole::Bayi])
            ->orderBy('depth')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<SportLimit>  $rows
     */
    private function merge(array $rows): EffectiveSportLimit
    {
        $values = [];
        foreach (SportLimitFields::MAX as $field) {
            $values[$field] = $this->tightest($rows, $field, true);
        }
        foreach (SportLimitFields::MIN as $field) {
            $values[$field] = $this->tightest($rows, $field, false);
        }
        $cashOut = $rows !== [];
        foreach ($rows as $row) {
            if (! $row->cash_out_enabled) {
                $cashOut = false;
            }
        }
        $values['cash_out_enabled'] = $cashOut;

        return new EffectiveSportLimit($values);
    }

    /**
     * @param  list<SportLimit>  $rows
     */
    private function tightest(array $rows, string $field, bool $maximum): mixed
    {
        $chosen = null;
        foreach ($rows as $row) {
            $value = $row->{$field};
            if ($value === null) {
                continue;
            }
            if ($chosen === null) {
                $chosen = $value;

                continue;
            }
            $tighter = $this->numeric($field)
                ? ($maximum ? bccomp((string) $value, (string) $chosen, 2) < 0 : bccomp((string) $value, (string) $chosen, 2) > 0)
                : ($maximum ? (int) $value < (int) $chosen : (int) $value > (int) $chosen);
            if ($tighter) {
                $chosen = $value;
            }
        }

        return $chosen;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function values(array $input, EffectiveSportLimit $ceiling): array
    {
        $unlimited = (array) ($input['unlimited'] ?? []);
        $errors = [];
        $values = [];
        $cashOut = (bool) ($input['cash_out_enabled'] ?? false);
        if ($cashOut && $ceiling->cash_out_enabled !== true) {
            $errors['cash_out_enabled'] = __('sport.panel.parent_limited');
            $cashOut = false;
        }
        $values['cash_out_enabled'] = $cashOut;

        foreach ([...SportLimitFields::MIN, ...SportLimitFields::MAX] as $field) {
            $cap = $ceiling->get($field);
            $wantsOpen = isset($unlimited[$field]);
            if ($wantsOpen) {
                if ($cap !== null) {
                    $errors[$field] = $this->boundMessage($field, $cap);
                }
                $values[$field] = $cap === null ? null : $cap;

                continue;
            }
            $raw = $input[$field] ?? null;
            if ($raw === null || $raw === '') {
                $errors[$field] = $cap === null
                    ? __('sport.panel.limit_invalid')
                    : $this->boundMessage($field, $cap);
                $values[$field] = $cap;

                continue;
            }
            $value = $this->normalize($field, $raw);
            if ($value === null) {
                $errors[$field] = $cap === null
                    ? __('sport.panel.limit_invalid')
                    : $this->boundMessage($field, $cap);

                continue;
            }
            if ($cap !== null && $this->exceeds($field, $value, $cap, SportLimitFields::isFloor($field))) {
                $errors[$field] = $this->boundMessage($field, $cap);
            }
            $values[$field] = $value;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $values;
    }

    private function boundMessage(string $field, mixed $cap): string
    {
        $key = SportLimitFields::isFloor($field) ? 'sport.panel.at_least' : 'sport.panel.at_most';

        return __($key, ['value' => $cap]);
    }

    private function exceeds(string $field, mixed $value, mixed $cap, bool $isMin): bool
    {
        if ($this->numeric($field)) {
            $cmp = bccomp((string) $value, (string) $cap, 2);

            return $isMin ? $cmp < 0 : $cmp > 0;
        }

        return $isMin ? (int) $value < (int) $cap : (int) $value > (int) $cap;
    }

    private function normalize(string $field, mixed $raw): mixed
    {
        if (in_array($field, SportLimitFields::INTS, true)) {
            if (! is_numeric($raw) || (int) $raw < 0) {
                return null;
            }

            return (int) $raw;
        }
        if (! is_numeric($raw)) {
            return null;
        }
        $scale = in_array($field, SportLimitFields::ODDS, true) ? '1.01' : '0.01';
        $value = bcadd((string) $raw, '0', 2);
        if (bccomp($value, $scale, 2) < 0) {
            return null;
        }

        return $value;
    }

    private function numeric(string $field): bool
    {
        return in_array($field, [...SportLimitFields::MONEY, ...SportLimitFields::ODDS], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(SportLimit $row): array
    {
        $payload = ['cash_out_enabled' => (bool) $row->cash_out_enabled];
        foreach ([...SportLimitFields::MIN, ...SportLimitFields::MAX] as $field) {
            $value = $row->{$field};
            $payload[$field] = $value === null ? null : (string) $value;
        }

        return $payload;
    }

    private function guardActor(User $actor, Currency $currency): void
    {
        if (! in_array($actor->role, [UserRole::Owner, UserRole::Superadmin, UserRole::Bayi], true)) {
            abort(404);
        }
        if ($actor->role !== UserRole::Owner && $actor->currency !== $currency) {
            abort(404);
        }
    }
}
