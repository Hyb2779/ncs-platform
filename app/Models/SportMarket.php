<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SportMarket extends Model
{
    protected $fillable = ['code', 'name_key', 'api_bet_id', 'line', 'sort_order'];
}
