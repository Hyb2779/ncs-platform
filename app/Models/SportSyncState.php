<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SportSyncState extends Model
{
    protected $fillable = ['code', 'last_synced_at', 'last_error'];

    protected function casts(): array
    {
        return ['last_synced_at' => 'datetime'];
    }
}
