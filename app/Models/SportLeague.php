<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SportLeague extends Model
{
    protected $fillable = ['api_id', 'country_id', 'name', 'logo', 'season', 'is_active', 'is_featured', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_featured' => 'boolean'];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(SportCountry::class, 'country_id');
    }

    public function fixtures(): HasMany
    {
        return $this->hasMany(SportFixture::class, 'league_id');
    }
}
