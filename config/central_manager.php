<?php

return [
    'enabled' => env('CENTRAL_MANAGER_ENABLED', false),
    'mode' => env('CENTRAL_MANAGER_MODE', 'off'), // possible values: 'off', 'publisher', 'consumer', 'both'
    'require_rabbitmq_config' => env('CENTRAL_MANAGER_REQUIRE_RABBITMQ_CONFIG', true),
    'rabbitmq_config_path' => env('CENTRAL_MANAGER_RABBITMQ_CONFIG_PATH', 'services.rabbitmq'),
    'required_rabbitmq_keys' => ['host', 'port', 'user', 'pass', 'vhost', 'queue'],
];
