<?php

return [
    'url' => 'https://v3.football.api-sports.io',
    'key' => env('APIFOOTBALL_KEY'),
    'bookmaker' => (int) env('APIFOOTBALL_BOOKMAKER', 8),
    'daily_limit' => 7500,
    'soft_cap' => 6500,
    'default_leagues' => [39, 140, 135, 78, 61, 203, 2, 3, 848, 5],
    'open_statuses' => ['NS', 'TBD'],
];
