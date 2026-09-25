<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CasinoProvider extends Model
{
    protected $fillable = ['code', 'name', 'status', 'is_live'];

    protected function casts(): array
    {
        return ['is_live' => 'boolean'];
    }

    public function games(): HasMany
    {
        return $this->hasMany(CasinoGame::class, 'provider_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
