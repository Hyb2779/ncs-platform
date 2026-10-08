<?php

return [
    /*
    | Kategori kartı görselleri. Blade sabit yol tutmaz; HomeCategoryImages buradan çözer.
    | file: public/ altındaki göreli yol. Oyun kartları sağlayıcı + ad / slayt anahtarıyla seçilir.
    */
    'categories' => [
        'sport' => [
            'file' => 'img/categories/sport.jpg',
        ],
        'slot' => [
            'provider' => 'goldpalace',
            'slide_keys' => ['sweet-bonanza-2500', 'sweet-bonanza-super-scatter'],
            'names' => ['Sweet Bonanza 2500', 'Sweet Bonanza Super Scatter', 'Sweet Bonanza'],
        ],
        'mini' => [
            'provider' => 'romaspin',
            'category' => 'mini',
            'names' => ['Aviator'],
        ],
        'casino' => [
            'provider' => 'romaspin',
            'live' => true,
        ],
    ],

    /*
    | Ana sayfa slaytına eklenen oyunlar. Slot slaytları paneldeki home_slides
    | kayıtlarından gelir; burası kod değiştirmeden eklenip çıkarılır.
    | category: slot | live | mini. image: public/ altındaki opsiyonel banner.
    | provider_label: opsiyonel; doluysa karttaki sağlayıcı etiketini ezer.
    */
    'slides' => [
        ['category' => 'live', 'game_id' => 2874],
        ['category' => 'live', 'game_id' => 2924],
        ['category' => 'live', 'game_id' => 2938],
        ['category' => 'mini', 'game_id' => 3096, 'provider_label' => 'Spribe'],
        ['category' => 'mini', 'game_id' => 3098],
        ['category' => 'mini', 'game_id' => 3103],
    ],

    'matches' => [
        'limit' => 5,
        'live_ttl' => 30,
        'refresh_ms' => 30000,
        'live_path' => 'canli-bahis',
    ],
];
