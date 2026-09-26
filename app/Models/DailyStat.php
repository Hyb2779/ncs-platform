<?php

namespace App\Models;

use App\Enums\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyStat extends Model
{
    protected $fillable = [
        'stat_date',
        'user_id',
        'currency',
        'product',
        'turnover',
        'payout',
        'ggr',
        'bet_count',
        'active_players',
        'new_players',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'stat_date' => 'date',
            'currency' => Currency::class,
            'turnover' => 'decimal:2',
            'payout' => 'decimal:2',
            'ggr' => 'decimal:2',
            'bet_count' => 'integer',
            'active_players' => 'integer',
            'new_players' => 'integer',
            'closed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
