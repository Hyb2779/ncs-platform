<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

#[Fillable([
    'username',
    'password',
    'role',
    'parent_id',
    'path',
    'depth',
    'superadmin_id',
    'language',
    'currency',
    'timezone',
    'commission_rate',
    'status',
    'user_limit',
    'note',
    'last_login_at',
    'last_login_ip',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'language' => Language::class,
            'currency' => Currency::class,
            'commission_rate' => 'decimal:2',
            'user_limit' => 'integer',
            'depth' => 'integer',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class)->ofMany(
            ['id' => 'max'],
            function ($query) {
                $query->where('currency', $this->currency);
            },
        );
    }

    public function scopeSubtreeOf(Builder $query, self $actor): Builder
    {
        if ($actor->role === UserRole::Owner) {
            return $query;
        }

        return $query->where('path', 'like', $actor->path.'%');
    }

    public function isInSubtreeOf(self $actor): bool
    {
        if ($actor->role === UserRole::Owner) {
            return true;
        }

        return str_starts_with($this->path, $actor->path);
    }

    public function homePath(): string
    {
        return $this->role === UserRole::Uye ? '/' : '/panel';
    }

    public function formattedCommissionRate(): string
    {
        $rate = (float) $this->commission_rate;

        return match (app()->getLocale()) {
            'tr' => '%'.number_format($rate, 2, ',', '.'),
            'de' => number_format($rate, 2, ',', '.').' %',
            'ar' => $this->arabicPercent($rate),
            default => number_format($rate, 2, '.', ',').'%',
        };
    }

    public function formattedLastLogin(): string
    {
        if ($this->last_login_at === null) {
            return __('panel.empty_value');
        }

        $date = $this->last_login_at->timezone($this->timezone);
        $locale = app()->getLocale();

        return match ($locale) {
            'tr', 'de' => $date->locale($locale)->translatedFormat('d.m.Y H:i'),
            'ar' => $date->locale('ar')->translatedFormat('d M Y H:i'),
            default => $date->locale('en')->translatedFormat('M j, Y H:i'),
        };
    }

    public function formattedChildLimit(): string
    {
        if ($this->role === UserRole::Uye) {
            return __('panel.empty_value');
        }

        $count = $this->children_count ?? $this->children()->count();

        return $count.' / '.($this->user_limit ?? __('panel.unlimited'));
    }

    private function arabicPercent(float $rate): string
    {
        $western = number_format($rate, 2, '.', '');
        $digits = ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩', '.' => '٫'];

        return strtr($western, $digits).'٪';
    }
}
