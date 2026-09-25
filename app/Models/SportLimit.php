<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SportLimit extends Model
{
    protected $fillable = [
        'superadmin_id', 'min_stake', 'max_stake', 'max_win', 'combo_min', 'combo_max',
        'min_total_odds', 'min_odd', 'daily_max', 'cancel_minutes',
    ];

    public function superadmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'superadmin_id');
    }
}
