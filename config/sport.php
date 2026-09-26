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
];
