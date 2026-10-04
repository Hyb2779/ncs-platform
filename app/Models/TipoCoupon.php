<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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

    /** Detay yoksa ya da acik kuponda 1 dakikadan eskiyse koprudan yeniler. */
    public function ensureDetail(\App\Services\Sport\NcsBridge $bridge): void
    {
        $stale = $this->detail === null
            || (! $this->isSettled() && ($this->detail_fetched_at === null || $this->detail_fetched_at->lt(now()->subMinute())));
        if (! $stale) {
            return;
        }
        $detail = $bridge->coupon((int) $this->user_id, (int) $this->bet_id);
        if ($detail !== null) {
            $this->storeDetail($detail);
        }
    }

    public function selections(): HasMany
    {
        return $this->hasMany(TipoSelection::class);
    }

    /** Detayi kaydeder ve secim satirlarini (tipo_selections) bastan yazar. */
    public function storeDetail(array $detail): void
    {
        DB::transaction(function () use ($detail) {
            $this->forceFill(['detail' => $detail, 'detail_fetched_at' => now()])->save();
            $this->selections()->delete();
            foreach ((array) ($detail['selections'] ?? []) as $s) {
                if (! is_array($s) || ! is_numeric($s['event_id'] ?? null)) {
                    continue;
                }
                $this->selections()->create([
                    'selection_id' => is_numeric($s['id'] ?? null) ? (int) $s['id'] : null,
                    'event_id' => (int) $s['event_id'],
                    'home_name' => mb_substr((string) ($s['home_name'] ?? ''), 0, 160),
                    'away_name' => mb_substr((string) ($s['away_name'] ?? ''), 0, 160),
                    'competition_name' => isset($s['competition_name']) ? mb_substr((string) $s['competition_name'], 0, 200) : null,
                    'country_name' => isset($s['country_name']) ? mb_substr((string) $s['country_name'], 0, 120) : null,
                    'sport_id' => is_numeric($s['sport_id'] ?? null) ? (int) $s['sport_id'] : null,
                    'match_time' => is_numeric($s['match_time'] ?? null) ? \Carbon\Carbon::createFromTimestamp((int) $s['match_time']) : null,
                    'market_name' => mb_substr((string) ($s['market_name'] ?? ''), 0, 160),
                    'selection_name' => mb_substr((string) ($s['selection_name'] ?? ''), 0, 120),
                    'handicap' => isset($s['handicap']) ? mb_substr((string) $s['handicap'], 0, 20) : null,
                    'odds' => (float) ($s['odds'] ?? 0),
                    'status_label' => isset($s['status_label']) ? (string) $s['status_label'] : null,
                    'is_live' => (bool) ($s['is_live'] ?? false),
                ]);
            }
        });
    }

    public function isSettled(): bool
    {
        return in_array((string) $this->status_label, self::SETTLED, true);
    }
}
