<?php
declare(strict_types=1);

return [
    'app' => [
        'name' => getenv('APP_NAME') ?: 'JDEV Industry',
        'timezone' => getenv('APP_TIMEZONE') ?: 'Europe/Rome',
        'default_poll_seconds' => max(3, (int) (getenv('POLL_SECONDS') ?: 5)),
    ],
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'name' => getenv('DB_NAME') ?: 'jdev_industry',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
];
