<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameSession extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'game_id', 'provider', 'token', 'opened_at', 'ip', 'device'];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(CasinoGame::class, 'game_id');
    }
}
