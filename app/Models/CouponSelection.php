<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CouponSelection extends Model
{
    protected $fillable = [
        'coupon_id', 'fixture_id', 'market_code', 'outcome', 'odds', 'raw_odds', 'kickoff', 'status',
    ];

    protected function casts(): array
    {
        return [
            'kickoff' => 'datetime',
            'odds' => 'decimal:2',
            'raw_odds' => 'decimal:2',
        ];
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function fixture(): BelongsTo
    {
        return $this->belongsTo(SportFixture::class, 'fixture_id');
    }
}
