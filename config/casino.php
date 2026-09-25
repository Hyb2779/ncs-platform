<?php

return [
    'user_prefix' => env('PROVIDER_USER_PREFIX', 'np_'),
    'demo_secret' => 'demo-callback-secret',
    'goldpalace' => [
        'url' => env('GOLDPALACE_API_URL'),
        'agent_code' => env('GOLDPALACE_AGENT_CODE'),
        'api_token' => env('GOLDPALACE_API_TOKEN'),
        'callback_token' => env('GOLDPALACE_CALLBACK_TOKEN'),
    ],
    'onegamex' => [
        'token_id' => env('ONEGAMEX_TOKEN_ID'),
        'token_name' => env('ONEGAMEX_TOKEN_NAME'),
        'secret_key' => env('ONEGAMEX_SECRET_KEY'),
        'url' => env('ONEGAMEX_API_URL'),
    ],
];
