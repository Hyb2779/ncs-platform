<?php

namespace App\Services\Casino;

use App\Models\User;

class CasinoUserCode
{
    public function forUser(User $user): string
    {
        return $this->prefix().$user->id;
    }

    public function resolve(string $code): ?User
    {
        $prefix = $this->prefix();

        if (! str_starts_with($code, $prefix)) {
            return null;
        }

        $id = substr($code, strlen($prefix));

        if ($id === '' || ! ctype_digit($id)) {
            return null;
        }

        return User::query()->find((int) $id);
    }

    public function prefix(): string
    {
        return (string) config('casino.user_prefix', 'np_');
    }
}
