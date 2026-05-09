<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [
    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'crm'),
            'username' => env('DB_USERNAME', 'crm'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            'options' => extension_loaded('pdo_pgsql') ? array_filter([
                PDO::ATTR_PERSISTENT => env('DB_PERSISTENT', false),
            ]) : [],
        ],

        // Read replica for reporting / dashboard queries
        'pgsql_read' => [
            'driver' => 'pgsql',
            'host' => env('DB_READ_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('DB_READ_PORT', env('DB_PORT', '5432')),
            'database' => env('DB_DATABASE', 'crm'),
            'username' => env('DB_READ_USERNAME', env('DB_USERNAME', 'crm')),
            'password' => env('DB_READ_PASSWORD', env('DB_PASSWORD', '')),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],
    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'crm'), '_').'_db_'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

        // Separate Redis logical DB for dialer state (hot data, frequent updates)
        'dialer' => [
            'url' => env('REDIS_DIALER_URL'),
            'host' => env('REDIS_DIALER_HOST', env('REDIS_HOST', '127.0.0.1')),
            'username' => env('REDIS_DIALER_USERNAME'),
            'password' => env('REDIS_DIALER_PASSWORD', env('REDIS_PASSWORD')),
            'port' => env('REDIS_DIALER_PORT', env('REDIS_PORT', '6379')),
            'database' => env('REDIS_DIALER_DB', '2'),
        ],

        // WebSocket/broadcasting
        'broadcasting' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_BROADCAST_DB', '3'),
        ],
    ],
];
