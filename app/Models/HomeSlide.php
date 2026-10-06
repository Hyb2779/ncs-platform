<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HomeSlide extends Model
{
    public const TOP_WIN = 'top-win';

    public const MATCH = 'match';

    protected $fillable = ['key', 'game_id', 'sort_order', 'is_active', 'image_path'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(CasinoGame::class, 'game_id');
    }

    public function isSystem(): bool
    {
        return $this->key !== null;
    }

    public function isTopWin(): bool
    {
        return $this->key === self::TOP_WIN;
    }

    public function isMatch(): bool
    {
        return $this->key === self::MATCH;
    }
}
