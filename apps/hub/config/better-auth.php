<?php

return [
    'bridge_url' => env('AUTH_BRIDGE_URL', 'http://127.0.0.1:19445'),
    'bridge_key' => env('AUTH_BRIDGE_KEY'),
    'database' => env('AUTH_DATABASE', storage_path('auth.sqlite')),
    'secret' => env('AUTH_SECRET'),
    'origin' => env('AUTH_ORIGIN', env('APP_URL', 'http://localhost')),
    'node' => env('AUTH_NODE', 'node'),
];
