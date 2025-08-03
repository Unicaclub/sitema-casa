<?php

return [
    'default' => 'redis',
    
    'redis' => [
        'host' => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
        'port' => $_ENV['REDIS_PORT'] ?? 6379,
        'password' => $_ENV['REDIS_PASSWORD'] ?? null,
        'database' => $_ENV['REDIS_DB'] ?? 0,
        'timeout' => 5,
        'prefix' => 'erp:',
    ],
    
    'file' => [
        'path' => __DIR__ . '/../storage/cache',
        'prefix' => 'erp_',
    ],
    
    'ttl' => [
        'short' => 5 * 60,      // 5 minutos
        'medium' => 30 * 60,    // 30 minutos
        'long' => 2 * 60 * 60,  // 2 horas
        'daily' => 24 * 60 * 60, // 24 horas
    ]
];
