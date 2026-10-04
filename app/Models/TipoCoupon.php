<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Wegas Spor (Tipo) kuponu; NCS koprusunden senkronlanir. */
class TipoCoupon extends Model
{
    public const SETTLED = ['won', 'lost', 'cancelled', 'canceled', 'refunded', 'void', 'cashout', 'cashed_out', 'half_won', 'half_lost'];

    protected $fillable = [
        'bet_id', 'user_id', 'currency', 'type', 'live', 'status', 'status_label', 'stake', 'total_odds',
        'potential_win', 'payout', 'selection_count', 'won_count', 'placed_at', 'detail', 'detail_fetched_at', 'synced_at',
    ];

    protected $casts = [
        'live' => 'boolean',
        'detail' => 'array',
        'placed_at' => 'datetime',
        'detail_fetched_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Panel durum anahtari (sport.coupon.statuses.*) -> Tipo etiketleri. */
    public const LABELS = [
        'won' => ['won', 'half_won', 'cashout', 'cashed_out'],
        'lost' => ['lost', 'half_lost'],
        'cancelled' => ['cancelled', 'canceled'],
        'refunded' => ['refunded'],
        'void' => ['void'],
    ];

    public static function statusFor(?string $label): string
    {
        foreach (self::LABELS as $status => $labels) {
            if (in_array((string) $label, $labels, true)) {
                return $status;
            }
        }

        return 'pending';
    }

    public function panelStatus(): string
    {
        return self::statusFor($this->status_label);
    }

    public function isSettled(): bool
    {
        return in_array((string) $this->status_label, self::SETTLED, true);
    }
}
