<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SportFixture extends Model
{
    protected $fillable = [
        'api_id', 'league_id', 'home_team_id', 'away_team_id', 'starts_at', 'status',
        'score_home', 'score_away', 'ht_home', 'ht_away', 'bulletin_code',
    ];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime'];
    }

    public function league(): BelongsTo
    {
        return $this->belongsTo(SportLeague::class, 'league_id');
    }

    public function home(): BelongsTo
    {
        return $this->belongsTo(SportTeam::class, 'home_team_id');
    }

    public function away(): BelongsTo
    {
        return $this->belongsTo(SportTeam::class, 'away_team_id');
    }

    public function odds(): HasMany
    {
        return $this->hasMany(SportOdd::class, 'fixture_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, config('football.open_statuses'), true) && $this->starts_at->isFuture();
    }
}
