<?php

namespace App\Models;

use App\Enums\WalletProduct;
use App\Enums\WalletTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class WalletTransaction extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'wallet_id',
        'user_id',
        'type',
        'product',
        'amount',
        'balance_before',
        'balance_after',
        'idempotency_key',
        'reference',
        'counterparty_user_id',
        'note',
        'created_by',
        'ip',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => WalletTransactionType::class,
            'product' => WalletProduct::class,
            'amount' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('wallet_transactions are immutable');
        });

        static::deleting(function (): void {
            throw new RuntimeException('wallet_transactions are immutable');
        });
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counterparty_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
