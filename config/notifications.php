<?php

return [
    'exchange' => env('RABBITMQ_EXCHANGE', 'notifications'),
    'queue' => env('RABBITMQ_QUEUE', 'notifications.send'),
    'retry_queue' => env('RABBITMQ_RETRY_QUEUE', 'notifications.retry'),
    'routing_key' => env('RABBITMQ_ROUTING_KEY', 'notifications.send'),
    'retry_routing_key' => env('RABBITMQ_RETRY_ROUTING_KEY', 'notifications.retry'),
    'max_priority' => (int) env('RABBITMQ_MAX_PRIORITY', 10),
    'retry_delays_ms' => [
        1 => 1000,
        2 => 5000,
        3 => 15000,
    ],
    'max_attempts' => (int) env('NOTIFICATIONS_MAX_ATTEMPTS', 3),
];
