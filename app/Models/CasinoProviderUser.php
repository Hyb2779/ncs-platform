<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CasinoProviderUser extends Model
{
    protected $fillable = ['provider', 'user_id', 'external_code'];
}
