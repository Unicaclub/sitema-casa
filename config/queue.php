<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Queue Configuration - Sistema Enterprise de Filas
    |--------------------------------------------------------------------------
    |
    | Configuração completa para o sistema de filas enterprise com IA
    |
    */

    // Driver de fila padrão
    'default' => env('QUEUE_CONNECTION', 'redis'),

    // Configurações de conexões
    'connections' => [
        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for' => null,
            'after_commit' => false,
        ],

        'database' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],
    ],

    // Configurações do QueueManager
    'manager' => [
        // Workers
        'max_workers' => env('QUEUE_MAX_WORKERS', 50),
        'min_workers' => env('QUEUE_MIN_WORKERS', 2),
        'worker_timeout' => env('QUEUE_WORKER_TIMEOUT', 3600),
        'worker_memory_limit' => env('QUEUE_WORKER_MEMORY', '512M'),
        'worker_sleep' => env('QUEUE_WORKER_SLEEP', 3),
        'worker_max_jobs' => env('QUEUE_WORKER_MAX_JOBS', 1000),
        'worker_max_time' => env('QUEUE_WORKER_MAX_TIME', 3600),

        // Jobs
        'max_job_timeout' => env('QUEUE_JOB_TIMEOUT', 3600),
        'default_retry_delay' => env('QUEUE_RETRY_DELAY', 5),
        'max_retry_attempts' => env('QUEUE_MAX_RETRIES', 3),
        'retry_delay_multiplier' => env('QUEUE_RETRY_MULTIPLIER', 2),
        'max_retry_delay' => env('QUEUE_MAX_RETRY_DELAY', 300),

        // Dead Letter Queues
        'dead_letter_enabled' => env('QUEUE_DEAD_LETTER_ENABLED', true),
        'dead_letter_after_days' => env('QUEUE_DEAD_LETTER_DAYS', 7),
        'dead_letter_cleanup_days' => env('QUEUE_DEAD_LETTER_CLEANUP', 30),

        // Métricas e Monitoramento
        'metrics_enabled' => env('QUEUE_METRICS_ENABLED', true),
        'metrics_retention_hours' => env('QUEUE_METRICS_RETENTION', 168), // 7 dias
        'health_check_interval' => env('QUEUE_HEALTH_CHECK_INTERVAL', 30),
        'stats_update_interval' => env('QUEUE_STATS_INTERVAL', 60),
    ],

    // Auto-scaling Configuration
    'auto_scaling' => [
        'enabled' => env('QUEUE_AUTO_SCALING_ENABLED', true),
        'scale_up_threshold' => env('QUEUE_SCALE_UP_THRESHOLD', 50),
        'scale_down_threshold' => env('QUEUE_SCALE_DOWN_THRESHOLD', 10),
        'scale_increment' => env('QUEUE_SCALE_INCREMENT', 5),
        'scale_decrement' => env('QUEUE_SCALE_DECREMENT', 2),
        'max_wait_time' => env('QUEUE_MAX_WAIT_TIME', 30),
        'min_wait_time' => env('QUEUE_MIN_WAIT_TIME', 5),
        'scale_cooldown' => env('QUEUE_SCALE_COOLDOWN', 300), // 5 minutos
        'cpu_threshold' => env('QUEUE_CPU_THRESHOLD', 80),
        'memory_threshold' => env('QUEUE_MEMORY_THRESHOLD', 85),
    ],

    // Circuit Breaker
    'circuit_breaker' => [
        'enabled' => env('QUEUE_CB_ENABLED', true),
        'failure_threshold' => env('QUEUE_CB_FAILURE_THRESHOLD', 5),
        'timeout' => env('QUEUE_CB_TIMEOUT', 60),
        'retry_timeout' => env('QUEUE_CB_RETRY_TIMEOUT', 300),
        'success_threshold' => env('QUEUE_CB_SUCCESS_THRESHOLD', 3),
    ],

    // Job Classifications (AI-powered)
    'classifications' => [
        'critical' => [
            'priority' => 100,
            'max_retry' => 5,
            'timeout' => 300,
            'patterns' => [
                'Security*', 'Alert*', 'Emergency*', 'Critical*'
            ]
        ],
        'high' => [
            'priority' => 80,
            'max_retry' => 3,
            'timeout' => 120,
            'patterns' => [
                'Payment*', 'Order*', 'User*', 'Auth*'
            ]
        ],
        'normal' => [
            'priority' => 50,
            'max_retry' => 2,
            'timeout' => 60,
            'patterns' => [
                'Email*', 'Notification*', 'Report*'
            ]
        ],
        'low' => [
            'priority' => 20,
            'max_retry' => 1,
            'timeout' => 30,
            'patterns' => [
                'Cleanup*', 'Analytics*', 'Cache*'
            ]
        ],
        'batch' => [
            'priority' => 10,
            'max_retry' => 0,
            'timeout' => 600,
            'patterns' => [
                'Batch*', 'Bulk*', 'Import*', 'Export*', 'Sync*'
            ]
        ]
    ],

    // Queue Priorities
    'queues' => [
        'critical' => [
            'weight' => 100,
            'max_jobs' => 10,
            'workers' => 5
        ],
        'high' => [
            'weight' => 80,
            'max_jobs' => 50,
            'workers' => 10
        ],
        'default' => [
            'weight' => 50,
            'max_jobs' => 100,
            'workers' => 15
        ],
        'low' => [
            'weight' => 20,
            'max_jobs' => 200,
            'workers' => 5
        ],
        'batch' => [
            'weight' => 10,
            'max_jobs' => 1000,
            'workers' => 3
        ]
    ],

    // Tenant Configuration
    'tenants' => [
        'isolation_enabled' => env('QUEUE_TENANT_ISOLATION', true),
        'dedicated_workers' => env('QUEUE_TENANT_DEDICATED_WORKERS', false),
        'resource_limits' => [
            'premium' => [
                'max_jobs_per_minute' => 1000,
                'max_concurrent_jobs' => 50,
                'priority_boost' => 10
            ],
            'standard' => [
                'max_jobs_per_minute' => 500,
                'max_concurrent_jobs' => 25,
                'priority_boost' => 0
            ],
            'basic' => [
                'max_jobs_per_minute' => 100,
                'max_concurrent_jobs' => 10,
                'priority_boost' => -5
            ]
        ]
    ],

    // Monitoring & Alerting
    'monitoring' => [
        'enabled' => env('QUEUE_MONITORING_ENABLED', true),
        'dashboard_enabled' => env('QUEUE_DASHBOARD_ENABLED', true),
        'metrics_endpoint' => env('QUEUE_METRICS_ENDPOINT', '/api/queue/metrics'),
        'dashboard_endpoint' => env('QUEUE_DASHBOARD_ENDPOINT', '/api/queue/dashboard'),
        
        'alerts' => [
            'enabled' => env('QUEUE_ALERTS_ENABLED', true),
            'email_alerts' => env('QUEUE_EMAIL_ALERTS', false),
            'slack_webhook' => env('QUEUE_SLACK_WEBHOOK'),
            'webhook_url' => env('QUEUE_ALERT_WEBHOOK'),
            
            'thresholds' => [
                'queue_size' => env('QUEUE_ALERT_SIZE', 1000),
                'failure_rate' => env('QUEUE_ALERT_FAILURE_RATE', 0.05),
                'avg_processing_time' => env('QUEUE_ALERT_PROCESSING_TIME', 120),
                'worker_memory_usage' => env('QUEUE_ALERT_MEMORY', 0.9),
                'dead_letter_size' => env('QUEUE_ALERT_DEAD_LETTER', 100)
            ]
        ]
    ],

    // Performance Optimization
    'performance' => [
        'batch_processing' => env('QUEUE_BATCH_PROCESSING', true),
        'batch_size' => env('QUEUE_BATCH_SIZE', 100),
        'compression_enabled' => env('QUEUE_COMPRESSION', true),
        'compression_threshold' => env('QUEUE_COMPRESSION_THRESHOLD', 1024), // bytes
        'lazy_loading' => env('QUEUE_LAZY_LOADING', true),
        'connection_pooling' => env('QUEUE_CONNECTION_POOLING', true),
        'persistent_connections' => env('QUEUE_PERSISTENT_CONNECTIONS', true),
        
        'optimization' => [
            'query_cache' => env('QUEUE_QUERY_CACHE', true),
            'result_cache' => env('QUEUE_RESULT_CACHE', true),
            'memory_optimization' => env('QUEUE_MEMORY_OPTIMIZATION', true),
            'garbage_collection' => env('QUEUE_GARBAGE_COLLECTION', true)
        ]
    ],

    // Security
    'security' => [
        'encryption_enabled' => env('QUEUE_ENCRYPTION', false),
        'encryption_key' => env('QUEUE_ENCRYPTION_KEY'),
        'signed_payloads' => env('QUEUE_SIGNED_PAYLOADS', true),
        'payload_verification' => env('QUEUE_PAYLOAD_VERIFICATION', true),
        'rate_limiting' => [
            'enabled' => env('QUEUE_RATE_LIMITING', true),
            'max_jobs_per_tenant_per_minute' => env('QUEUE_RATE_LIMIT', 1000)
        ],
        'audit_logging' => env('QUEUE_AUDIT_LOGGING', true)
    ],

    // Backup & Recovery
    'backup' => [
        'enabled' => env('QUEUE_BACKUP_ENABLED', true),
        'backup_interval' => env('QUEUE_BACKUP_INTERVAL', 3600), // 1 hora
        'backup_retention_days' => env('QUEUE_BACKUP_RETENTION', 7),
        'backup_storage' => env('QUEUE_BACKUP_STORAGE', 'local'),
        'backup_compression' => env('QUEUE_BACKUP_COMPRESSION', true),
        'incremental_backup' => env('QUEUE_INCREMENTAL_BACKUP', true)
    ],

    // Development & Debugging
    'development' => [
        'debug_mode' => env('QUEUE_DEBUG', false),
        'log_level' => env('QUEUE_LOG_LEVEL', 'info'),
        'profiling_enabled' => env('QUEUE_PROFILING', false),
        'query_logging' => env('QUEUE_QUERY_LOGGING', false),
        'performance_logging' => env('QUEUE_PERFORMANCE_LOGGING', false),
        'fake_processing' => env('QUEUE_FAKE_PROCESSING', false),
        'simulate_failures' => env('QUEUE_SIMULATE_FAILURES', false)
    ]
];