<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SportMarket extends Model
{
    protected $fillable = ['code', 'name_key', 'api_bet_id', 'line', 'sort_order'];

    /**
     * @return list<string>
     */
    public static function outcomesFor(string $code): array
    {
        return match ($code) {
            '1X2', 'HT1X2' => ['home', 'draw', 'away'],
            'DC' => ['home_draw', 'home_away', 'draw_away'],
            'OU15', 'OU25', 'OU35' => ['under', 'over'],
            'BTTS' => ['yes', 'no'],
            default => [],
        };
    }
}
