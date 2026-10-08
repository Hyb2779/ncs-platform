<?php

namespace App\Models;

use App\Support\GameSearch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CasinoGame extends Model
{
    protected $fillable = [
        'vendor',
        'provider_id', 'external_id', 'name', 'category', 'image_url',
        'is_live', 'is_active', 'sort_order', 'is_popular',
    ];

    protected function casts(): array
    {
        return [
            'is_live' => 'boolean',
            'is_active' => 'boolean',
            'is_popular' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (CasinoGame $game) {
            if ($game->isDirty('name') || $game->name_folded === null || $game->name_folded === '') {
                $game->name_folded = GameSearch::fold((string) $game->name);
            }
        });
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(CasinoProvider::class, 'provider_id');
    }
}
