<?php

return [
    'key_id' => env('NEBULESS_ACTION_KEY_ID'),
    'private_key_file' => env('NEBULESS_ACTION_PRIVATE_KEY_FILE'),
    'private_key' => env('NEBULESS_ACTION_PRIVATE_KEY_FILE') ? @file_get_contents(env('NEBULESS_ACTION_PRIVATE_KEY_FILE')) : null,
];
