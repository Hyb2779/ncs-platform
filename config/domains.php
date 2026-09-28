<?php

return [
    // Boşsa alan adı ayrımı kapalı (test/geliştirme). Canlı: SITE_DOMAIN=wegas11.com, PANEL_DOMAIN=panel.wegas11.com
    'site' => env('SITE_DOMAIN'),
    'panel' => env('PANEL_DOMAIN'),
];
