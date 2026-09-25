<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameRound extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'provider', 'provider_transaction_id', 'round_id', 'user_id', 'game_id',
        'bet', 'win', 'balance_before', 'amount', 'balance_after', 'status', 'payload', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'bet' => 'decimal:2',
            'win' => 'decimal:2',
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
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
