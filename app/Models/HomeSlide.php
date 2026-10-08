<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HomeSlide extends Model
{
    public const TOP_WIN = 'top-win';

    /** @var array<string, string> */
    public const PINNED = [
        'sweet-bonanza-2500' => 'Sweet Bonanza 2500',
        'sweet-bonanza-super-scatter' => 'Sweet Bonanza Super Scatter',
    ];

    protected $fillable = ['key', 'game_id', 'sort_order', 'is_active', 'excluded', 'image_path'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'excluded' => 'boolean',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(CasinoGame::class, 'game_id');
    }

    public function isSystem(): bool
    {
        return $this->key !== null;
    }

    public function isPinned(): bool
    {
        return $this->key !== null && array_key_exists($this->key, self::PINNED);
    }

    public function isTopWin(): bool
    {
        return $this->key === self::TOP_WIN;
    }
}
