<?php

namespace App\Models;

use App\Enums\Currency;
use App\Services\Sport\SportLimitFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SportLimit extends Model
{
    protected $fillable = [
        'user_id', 'currency', 'cash_out_enabled',
        ...SportLimitFields::MONEY,
        ...SportLimitFields::ODDS,
        ...SportLimitFields::INTS,
    ];

    protected function casts(): array
    {
        $casts = [
            'currency' => Currency::class,
            'cash_out_enabled' => 'boolean',
            'max_selections' => 'integer',
            'live_close_minute' => 'integer',
            'cancel_minutes' => 'integer',
        ];
        foreach ([...SportLimitFields::MONEY, ...SportLimitFields::ODDS] as $field) {
            $casts[$field] = 'decimal:2';
        }

        return $casts;
    }

    protected static function booted(): void
    {
        static::saving(function (SportLimit $limit): void {
            $currency = $limit->currency instanceof Currency ? $limit->currency->value : (string) $limit->currency;
            $limit->limit_key = $limit->user_id === null ? 'owner:'.$currency : 'user:'.$limit->user_id;
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
