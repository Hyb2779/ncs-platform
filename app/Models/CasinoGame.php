<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CasinoGame extends Model
{
    protected $fillable = [
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

    public function provider(): BelongsTo
    {
        return $this->belongsTo(CasinoProvider::class, 'provider_id');
    }
}
