<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Wegas Spor kupon secimi (tipo_coupons.detail'den turetilir; Bahis Yogunlugu icin). */
class TipoSelection extends Model
{
    protected $fillable = [
        'tipo_coupon_id', 'selection_id', 'event_id', 'home_name', 'away_name', 'competition_name', 'country_name',
        'sport_id', 'match_time', 'market_name', 'selection_name', 'handicap', 'odds', 'status_label', 'is_live',
    ];

    protected $casts = ['match_time' => 'datetime', 'is_live' => 'boolean'];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(TipoCoupon::class, 'tipo_coupon_id');
    }
}
