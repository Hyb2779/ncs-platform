<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SportWarning extends Model
{
    public const Overdraft = 'overdraft';

    public const Stale = 'stale';

    protected $fillable = [
        'type', 'user_id', 'fixture_id', 'coupon_id', 'amount', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fixture(): BelongsTo
    {
        return $this->belongsTo(SportFixture::class, 'fixture_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeVisibleTo(Builder $query, User $actor): Builder
    {
        return $query->where(function (Builder $inner) use ($actor): void {
            $inner->whereHas('user', fn (Builder $users) => $users->subtreeOf($actor))
                ->orWhere(function (Builder $ownerOnly) use ($actor): void {
                    if ($actor->role === UserRole::Owner) {
                        $ownerOnly->whereNull('user_id');
                    } else {
                        $ownerOnly->whereRaw('0 = 1');
                    }
                });
        });
    }
}
