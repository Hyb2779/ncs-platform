<?php

return [
    'settle_after_minutes' => 110,
    'void_after_hours' => 48,
    'stale_after_hours' => 6,
    'batch_size' => 20,
    'settle_statuses' => ['FT', 'AET', 'PEN'],
    'void_statuses' => ['PST', 'CANC', 'ABD', 'AWD', 'WO', 'TBD'],
    'wait_statuses' => ['SUSP', 'INT'],
    'stale_statuses' => ['NS', '1H', 'HT', '2H', 'ET', 'BT', 'P', 'LIVE'],
    'live_statuses' => ['1H', '2H', 'ET', 'BT', 'P', 'LIVE'],

    // Kendi spor bülteni/kupon sistemi. Kapalıyken oyuncu tarafı 404 (Sonuçlar ve Wegas Spor açık).
    'own_book_enabled' => (bool) env('OWN_SPORT_ENABLED', false),

    // Bozdurma: adil değerin üyeye kalan payı. Canlı oran 90 sn, maç önü 10 dk taze olmalı.
    'cashout_keep' => '0.90',
    'cashout_live_seconds' => 90,
    'cashout_prematch_seconds' => 600,
    'cashout_drift' => '0.02',
];
