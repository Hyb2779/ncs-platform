<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SportMargin extends Model
{
    protected $fillable = ['superadmin_id', 'layer', 'league_id', 'fixture_id', 'market_code', 'margin', 'max_odd'];
}
