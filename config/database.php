<?php

return [
    'default' => 'mysql',
    
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'port' => $_ENV['DB_PORT'] ?? 3306,
            'database' => $_ENV['DB_DATABASE'] ?? 'erp_sistema',
            'username' => $_ENV['DB_USERNAME'] ?? 'root',
            'password' => $_ENV['DB_PASSWORD'] ?? '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        ],
        
        'reports' => [
            'driver' => 'mysql',
            'host' => $_ENV['REPORTS_DB_HOST'] ?? 'localhost',
            'port' => $_ENV['REPORTS_DB_PORT'] ?? 3306,
            'database' => $_ENV['REPORTS_DB_DATABASE'] ?? 'erp_reports',
            'username' => $_ENV['REPORTS_DB_USERNAME'] ?? 'root',
            'password' => $_ENV['REPORTS_DB_PASSWORD'] ?? '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]
    ]
];
