<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameBlock extends Model
{
    protected $fillable = ['superadmin_id', 'scope', 'value', 'created_by'];
}
