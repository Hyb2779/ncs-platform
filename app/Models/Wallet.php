<?php

namespace App\Models;

use App\Enums\Currency;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    protected $fillable = [
        'user_id',
        'currency',
        'balance',
    ];

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'balance' => 'decimal:2',
            'allow_negative' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function formattedBalance(): string
    {
        return Money::format((string) $this->balance, $this->currency);
    }

    public function formattedDistributedBalance(): string
    {
        return Money::formatAbsolute((string) $this->balance, $this->currency);
    }
}
