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
}
