<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SportTeam extends Model
{
    protected $fillable = ['api_id', 'name', 'logo'];

    protected static function booted(): void
    {
        static::saving(function (SportTeam $team): void {
            $team->name_key = \sport_search_key((string) $team->name);
        });
    }
}
