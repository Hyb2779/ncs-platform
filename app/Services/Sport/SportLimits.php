<?php

namespace App\Services\Sport;

use App\Models\SportLimit;
use App\Models\User;

class SportLimits
{
    public function forUser(User $user): SportLimit
    {
        $override = $user->superadmin_id === null
            ? null
            : SportLimit::query()->where('superadmin_id', $user->superadmin_id)->first();

        return $override ?? SportLimit::query()->whereNull('superadmin_id')->firstOrFail();
    }

    public function global(): SportLimit
    {
        return SportLimit::query()->whereNull('superadmin_id')->firstOrFail();
    }
}
