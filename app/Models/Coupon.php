<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    protected $fillable = [
        'coupon_no', 'user_id', 'superadmin_id', 'client_key', 'type', 'stake', 'total_odds',
        'potential_win', 'status', 'accept_odds_change', 'note', 'ip', 'device', 'placed_at',
        'settled_at', 'cancelled_by', 'cancel_reason', 'settlement_revision',
    ];

    protected function casts(): array
    {
        return [
            'accept_odds_change' => 'boolean',
            'placed_at' => 'datetime',
            'settled_at' => 'datetime',
            'settlement_revision' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function selections(): HasMany
    {
        return $this->hasMany(CouponSelection::class);
    }
}
