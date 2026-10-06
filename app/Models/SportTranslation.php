<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SportTranslation extends Model
{
    protected $fillable = ['entity_type', 'entity_id', 'locale', 'name', 'source'];

    protected static function booted(): void
    {
        static::saving(function (SportTranslation $translation): void {
            $translation->name_key = \sport_search_key((string) $translation->name);
        });
    }
}
