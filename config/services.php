<?php

return [
    'guacamole' => [
        'api_url' => env('GUACAMOLE_API_URL', 'http://127.0.0.1:8080/guacamole'),
        'client_url' => env('GUACAMOLE_CLIENT_URL', 'http://localhost:8080/guacamole'),
        'client_id' => env('GUACAMOLE_CLIENT_ID'),
        'data_source' => env('GUACAMOLE_DATA_SOURCE', 'postgresql'),
        'username' => env('GUACAMOLE_SERVICE_USERNAME'),
        'password' => env('GUACAMOLE_SERVICE_PASSWORD'),
    ],
    'cs2' => [
        'provisioner_url' => env('CS2_PROVISIONER_URL'),
        'provisioner_token' => env('CS2_PROVISIONER_TOKEN'),
        'callback_url' => env('CS2_CALLBACK_URL', env('APP_URL')),
    ],
    'mlbb_ocr' => [
        'command' => env('MLBB_OCR_COMMAND', 'py'),
        'command_args' => array_filter(explode(' ', env('MLBB_OCR_COMMAND_ARGS', '-3.12'))),
        'timeout' => (int) env('MLBB_OCR_TIMEOUT', 120),
    ],
];
