<?php

declare(strict_types=1);

return [
    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'ctftimeparser',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    'parser' => [
        // How many days ahead to fetch events
        'days_ahead'               => 14,
        // CTFTime API max results per request
        'events_limit'             => 100,
        // cURL timeout in seconds
        'request_timeout'          => 10,
        // Pause between per-event detail requests (seconds)
        'sleep_between_requests'   => 1,
    ],

    'log_file' => __DIR__ . '/logs/parser.log',
];
