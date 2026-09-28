<?php

return [
    'prematch_url' => env('FENIX_PREMATCH_URL', 'https://fenix5.com/api/prematchEvents'),
    'live_url' => env('FENIX_LIVE_URL', 'https://fenix5.com/api/liveEvents'),
    'result_url' => env('FENIX_RESULT_URL', 'https://fenix5.com/api/resultApi'),
    'resultbot_url' => env('FENIX_RESULTBOT_URL', 'https://fenix5.com/resultbot.php'),
    'horizon_days' => (int) env('FENIX_HORIZON_DAYS', 4),

    // Fenix types sözlüğü: "market adı|seçenek|handikap" => [bizim market kodu, seçenek kodu]
    'markets' => [
        'Maç Sonucu|1|0' => ['1X2', 'home'], 'Maç Sonucu|X|0' => ['1X2', 'draw'], 'Maç Sonucu|2|0' => ['1X2', 'away'],
        'Çifte Şans|1X|0' => ['DC', 'home_draw'], 'Çifte Şans|12|0' => ['DC', 'home_away'], 'Çifte Şans|X2|0' => ['DC', 'draw_away'],
        'Toplam Alt/Üst|Alt|1.5' => ['OU15', 'under'], 'Toplam Alt/Üst|Üst|1.5' => ['OU15', 'over'],
        'Toplam Alt/Üst|Alt|2.5' => ['OU25', 'under'], 'Toplam Alt/Üst|Üst|2.5' => ['OU25', 'over'],
        'Toplam Alt/Üst|Alt|3.5' => ['OU35', 'under'], 'Toplam Alt/Üst|Üst|3.5' => ['OU35', 'over'],
        'Karşılıklı Gol|Var|0' => ['BTTS', 'yes'], 'Karşılıklı Gol|Yok|0' => ['BTTS', 'no'],
        'İlk Yarı Sonucu|1|0' => ['HT1X2', 'home'], 'İlk Yarı Sonucu|X|0' => ['HT1X2', 'draw'], 'İlk Yarı Sonucu|2|0' => ['HT1X2', 'away'],
    ],
];
