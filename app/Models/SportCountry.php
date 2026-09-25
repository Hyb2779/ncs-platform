<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SportCountry extends Model
{
    protected $fillable = ['name', 'code', 'flag'];

    public function leagues(): HasMany
    {
        return $this->hasMany(SportLeague::class, 'country_id');
    }
}
