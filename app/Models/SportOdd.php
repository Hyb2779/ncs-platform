<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SportOdd extends Model
{
    protected $fillable = [
        'fixture_id', 'market_id', 'outcome', 'raw_odd', 'shown_odd', 'direction', 'suspended', 'quoted_at',
    ];

    protected function casts(): array
    {
        return [
            'raw_odd' => 'decimal:2',
            'shown_odd' => 'decimal:2',
            'suspended' => 'boolean',
            'quoted_at' => 'datetime',
        ];
    }

    public function fixture(): BelongsTo
    {
        return $this->belongsTo(SportFixture::class, 'fixture_id');
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(SportMarket::class, 'market_id');
    }
}
