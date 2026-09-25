<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SportTranslation extends Model
{
    protected $fillable = ['entity_type', 'entity_id', 'locale', 'name', 'source'];
}
