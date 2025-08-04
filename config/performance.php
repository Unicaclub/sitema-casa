<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Performance Configuration - Sistema ERP
    |--------------------------------------------------------------------------
    |
    | Configurações para o sistema de performance suprema
    |
    */

    // Configurações gerais
    'enabled' => env('PERFORMANCE_ENABLED', true),
    'debug_mode' => env('PERFORMANCE_DEBUG', false),
    'auto_optimize' => env('PERFORMANCE_AUTO_OPTIMIZE', true),
    
    // Cache Configuration
    'cache' => [
        'enabled' => env('CACHE_ENABLED', true),
        'default_driver' => env('CACHE_DRIVER', 'file'),
        'warming' => env('CACHE_WARMING', true),
        'compression' => env('CACHE_COMPRESSION', true),
        'intelligent_ttl' => env('CACHE_INTELLIGENT_TTL', true),
        
        // TTL padrões por tipo de dados (em segundos)
        'ttl_mapping' => [
            'dashboard_metrics' => 300,    // 5 minutos
            'produto_' => 600,             // 10 minutos
            'cliente_' => 300,             // 5 minutos
            'venda_' => 180,               // 3 minutos
            'configuracao_' => 3600,       // 1 hora
            'relatorio_' => 1800,          // 30 minutos
            'usuario_' => 900,             // 15 minutos
            'categoria_' => 1800,          // 30 minutos
            'top_produtos' => 600,         // 10 minutos
            'alertas_' => 120,             // 2 minutos
        ],
        
        // Redis configuration
        'redis' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD', null),
            'database' => env('REDIS_DB', 0),
            'timeout' => 5,
            'read_timeout' => 5,
        ],
        
        // File cache configuration
        'file' => [
            'path' => env('CACHE_FILE_PATH', sys_get_temp_dir() . '/erp_cache'),
            'permissions' => 0755,
        ],
    ],
    
    // Database Optimization
    'database' => [
        'query_optimization' => env('DB_QUERY_OPTIMIZATION', true),
        'connection_pooling' => env('DB_CONNECTION_POOLING', true),
        'lazy_loading' => env('DB_LAZY_LOADING', true),
        'index_hints' => env('DB_INDEX_HINTS', true),
        
        // Connection Pool Settings
        'pool' => [
            'min_connections' => env('DB_POOL_MIN', 5),
            'max_connections' => env('DB_POOL_MAX', 20),
            'connection_timeout' => env('DB_POOL_TIMEOUT', 5),
            'idle_timeout' => env('DB_POOL_IDLE_TIMEOUT', 600),
        ],
    ],
    
    // Memory Management
    'memory' => [
        'enabled' => env('MEMORY_MANAGEMENT', true),
        'auto_gc' => env('MEMORY_AUTO_GC', true),
        'object_pooling' => env('MEMORY_OBJECT_POOLING', true),
        'streaming_processing' => env('MEMORY_STREAMING', true),
        
        // Limites e alertas
        'warning_threshold' => env('MEMORY_WARNING_THRESHOLD', 80), // %
        'critical_threshold' => env('MEMORY_CRITICAL_THRESHOLD', 90), // %
        'max_pool_size' => env('MEMORY_MAX_POOL_SIZE', 100),
        
        // Configurações de garbage collection
        'gc_probability' => env('MEMORY_GC_PROBABILITY', 10), // %
        'gc_frequency' => env('MEMORY_GC_FREQUENCY', 100), // requests
    ],
    
    // Compression Settings
    'compression' => [
        'enabled' => env('COMPRESSION_ENABLED', true),
        'auto_detect' => env('COMPRESSION_AUTO_DETECT', true),
        'min_size' => env('COMPRESSION_MIN_SIZE', 1024), // bytes
        'level' => env('COMPRESSION_LEVEL', 6), // 1-9
        'algorithm' => env('COMPRESSION_ALGORITHM', 'gzip'), // gzip, brotli, lz4
        
        // HTTP Response Compression
        'http_compression' => env('HTTP_COMPRESSION', true),
        'supported_algorithms' => ['br', 'gzip', 'deflate'],
        
        // Asset Optimization
        'assets' => [
            'minify_css' => env('MINIFY_CSS', true),
            'minify_js' => env('MINIFY_JS', true),
            'minify_html' => env('MINIFY_HTML', true),
            'optimize_images' => env('OPTIMIZE_IMAGES', true),
        ],
    ],
    
    // Monitoring & Analytics
    'monitoring' => [
        'enabled' => env('MONITORING_ENABLED', true),
        'detailed_logging' => env('MONITORING_DETAILED', false),
        'performance_logging' => env('PERFORMANCE_LOGGING', true),
        'benchmark_frequency' => env('BENCHMARK_FREQUENCY', 3600), // seconds
        
        // Alertas automáticos
        'alerts' => [
            'enabled' => env('ALERTS_ENABLED', true),
            'email_alerts' => env('ALERTS_EMAIL', false),
            'slack_webhook' => env('ALERTS_SLACK_WEBHOOK', null),
            'performance_threshold' => env('ALERTS_PERFORMANCE_THRESHOLD', 70), // score mínimo
        ],
        
        // Métricas a coletar
        'metrics' => [
            'response_time' => true,
            'memory_usage' => true,
            'database_performance' => true,
            'cache_hit_rate' => true,
            'error_rate' => true,
            'throughput' => true,
        ],
        
        // Armazenamento de métricas
        'storage' => [
            'driver' => env('METRICS_STORAGE', 'file'), // file, database, redis
            'retention_days' => env('METRICS_RETENTION', 30),
            'file_path' => env('METRICS_FILE_PATH', storage_path('metrics')),
        ],
    ],
    
    // Lazy Loading Configuration
    'lazy_loading' => [
        'enabled' => env('LAZY_LOADING_ENABLED', true),
        'preload_strategy' => env('LAZY_PRELOAD_STRATEGY', 'intelligent'),
        'preload_probability_threshold' => env('LAZY_PRELOAD_THRESHOLD', 0.7),
        'max_preload_queue' => env('LAZY_MAX_PRELOAD_QUEUE', 100),
        
        // Relacionamentos para preload automático
        'auto_preload_relations' => [
            'produto' => ['categoria', 'fornecedor'],
            'venda' => ['cliente', 'itens'],
            'cliente' => ['endereco'],
        ],
        
        // Cache de relacionamentos
        'relation_cache_ttl' => env('LAZY_RELATION_CACHE_TTL', 300), // 5 minutos
    ],
    
    // Performance Profiles
    'profiles' => [
        'development' => [
            'cache_enabled' => false,
            'compression_enabled' => false,
            'detailed_logging' => true,
            'auto_optimize' => false,
        ],
        
        'testing' => [
            'cache_enabled' => true,
            'compression_enabled' => false,
            'detailed_logging' => true,
            'auto_optimize' => true,
        ],
        
        'production' => [
            'cache_enabled' => true,
            'compression_enabled' => true,
            'detailed_logging' => false,
            'auto_optimize' => true,
        ],
    ],
    
    // Benchmark Targets
    'benchmarks' => [
        'database_query_simple' => [
            'target_time' => 0.01, // seconds
            'warning_time' => 0.05,
            'critical_time' => 0.1,
        ],
        
        'database_query_complex' => [
            'target_time' => 0.1,
            'warning_time' => 0.5,
            'critical_time' => 1.0,
        ],
        
        'cache_operations' => [
            'target_time' => 0.001,
            'warning_time' => 0.01,
            'critical_time' => 0.05,
        ],
        
        'memory_usage' => [
            'target_mb' => 128,
            'warning_mb' => 256,
            'critical_mb' => 512,
        ],
        
        'response_time' => [
            'target_ms' => 100,
            'warning_ms' => 500,
            'critical_ms' => 1000,
        ],
    ],
    
    // Auto-scaling Configuration
    'scaling' => [
        'enabled' => env('AUTO_SCALING_ENABLED', false),
        'cpu_threshold' => env('SCALING_CPU_THRESHOLD', 70),
        'memory_threshold' => env('SCALING_MEMORY_THRESHOLD', 80),
        'response_time_threshold' => env('SCALING_RESPONSE_THRESHOLD', 500), // ms
        
        // Ações de scaling
        'actions' => [
            'increase_cache_size' => true,
            'increase_connection_pool' => true,
            'enable_aggressive_gc' => true,
            'reduce_cache_ttl' => true,
        ],
    ],
    
    // Feature Flags
    'features' => [
        'query_optimizer' => env('FEATURE_QUERY_OPTIMIZER', true),
        'intelligent_caching' => env('FEATURE_INTELLIGENT_CACHING', true),
        'memory_management' => env('FEATURE_MEMORY_MANAGEMENT', true),
        'compression' => env('FEATURE_COMPRESSION', true),
        'lazy_loading' => env('FEATURE_LAZY_LOADING', true),
        'connection_pooling' => env('FEATURE_CONNECTION_POOLING', true),
        'performance_monitoring' => env('FEATURE_PERFORMANCE_MONITORING', true),
        'auto_optimization' => env('FEATURE_AUTO_OPTIMIZATION', true),
    ],
];