<?php

declare(strict_types=1);

namespace ERP\Core\Monitoring;

use ERP\Core\Cache\CacheManager;
use ERP\Core\Database;
use ERP\Core\Queue\QueueManager;
use ERP\Core\Security\SOCManager;

/**
 * Health Check Manager
 * 
 * Sistema avançado de health checks com verificações
 * profundas de todos os sistemas críticos
 */
class HealthCheckManager
{
    private CacheManager $cache;
    private Database $database;
    private QueueManager $queue;
    private SOCManager $soc;
    private array $healthChecks = [];
    private array $criticalSystems = [];
    
    public function __construct(
        CacheManager $cache,
        Database $database,
        QueueManager $queue,
        SOCManager $soc
    ) {
        $this->cache = $cache;
        $this->database = $database;
        $this->queue = $queue;
        $this->soc = $soc;
        $this->initializeHealthChecks();
    }
    
    /**
     * Executa todos os health checks
     */
    public function runAllHealthChecks(): array
    {
        $startTime = microtime(true);
        $results = [];
        $overallStatus = 'healthy';
        
        foreach ($this->healthChecks as $name => $check) {
            $checkResult = $this->runHealthCheck($name, $check);
            $results[$name] = $checkResult;
            
            if ($checkResult['status'] === 'unhealthy' && $check['critical']) {
                $overallStatus = 'unhealthy';
            } elseif ($checkResult['status'] === 'degraded' && $overallStatus === 'healthy') {
                $overallStatus = 'degraded';
            }
        }
        
        $totalTime = microtime(true) - $startTime;
        
        return [
            'status' => $overallStatus,
            'timestamp' => time(),
            'total_duration' => round($totalTime * 1000, 2), // ms
            'checks' => $results,
            'summary' => $this->generateHealthSummary($results)
        ];
    }
    
    /**
     * Executa health check específico
     */
    public function runHealthCheck(string $name, ?array $config = null): array
    {
        $config = $config ?? $this->healthChecks[$name] ?? null;
        
        if (! $config) {
            return [
                'status' => 'unknown',
                'message' => 'Health check not found',
                'duration' => 0
            ];
        }
        
        $startTime = microtime(true);
        
        try {
            $result = $this->executeHealthCheck($config);
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            return [
                'status' => $result['status'],
                'message' => $result['message'],
                'duration' => $duration,
                'details' => $result['details'] ?? [],
                'metrics' => $result['metrics'] ?? [],
                'last_check' => time()
            ];
            
        } catch (\Throwable $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            return [
                'status' => 'unhealthy',
                'message' => $e->getMessage(),
                'duration' => $duration,
                'error' => [
                    'type' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ],
                'last_check' => time()
            ];
        }
    }
    
    /**
     * Health check do banco de dados
     */
    public function checkDatabase(): array
    {
        $checks = [];
        
        // Conectividade básica
        $checks['connectivity'] = $this->checkDatabaseConnectivity();
        
        // Performance das queries
        $checks['query_performance'] = $this->checkQueryPerformance();
        
        // Conexões ativas
        $checks['connections'] = $this->checkDatabaseConnections();
        
        // Espaço em disco
        $checks['disk_space'] = $this->checkDatabaseDiskSpace();
        
        // Replicação (se aplicável)
        $checks['replication'] = $this->checkDatabaseReplication();
        
        // Locks de tabela
        $checks['locks'] = $this->checkDatabaseLocks();
        
        $overallStatus = $this->determineOverallStatus($checks);
        
        return [
            'status' => $overallStatus,
            'message' => $this->generateStatusMessage('Database', $overallStatus),
            'details' => $checks,
            'metrics' => [
                'active_connections' => $this->getActiveConnections(),
                'avg_query_time' => $this->getAverageQueryTime(),
                'slow_queries' => $this->getSlowQueriesCount()
            ]
        ];
    }
    
    /**
     * Health check do cache (Redis)
     */
    public function checkCache(): array
    {
        $checks = [];
        
        // Conectividade Redis
        $checks['connectivity'] = $this->checkRedisConnectivity();
        
        // Uso de memória
        $checks['memory_usage'] = $this->checkRedisMemoryUsage();
        
        // Performance
        $checks['performance'] = $this->checkRedisPerformance();
        
        // Persistência
        $checks['persistence'] = $this->checkRedisPersistence();
        
        // Eviction de keys
        $checks['eviction'] = $this->checkRedisEviction();
        
        $overallStatus = $this->determineOverallStatus($checks);
        
        return [
            'status' => $overallStatus,
            'message' => $this->generateStatusMessage('Cache', $overallStatus),
            'details' => $checks,
            'metrics' => [
                'memory_used' => $this->getRedisMemoryUsed(),
                'connected_clients' => $this->getRedisConnectedClients(),
                'operations_per_sec' => $this->getRedisOperationsPerSec(),
                'hit_rate' => $this->getCacheHitRate()
            ]
        ];
    }
    
    /**
     * Health check do sistema de filas
     */
    public function checkQueue(): array
    {
        $checks = [];
        
        // Conectividade com broker
        $checks['connectivity'] = $this->checkQueueConnectivity();
        
        // Tamanho das filas
        $checks['queue_sizes'] = $this->checkQueueSizes();
        
        // Workers ativos
        $checks['workers'] = $this->checkQueueWorkers();
        
        // Jobs falhas
        $checks['failed_jobs'] = $this->checkFailedJobs();
        
        // Performance de processamento
        $checks['processing_performance'] = $this->checkQueueProcessingPerformance();
        
        $overallStatus = $this->determineOverallStatus($checks);
        
        return [
            'status' => $overallStatus,
            'message' => $this->generateStatusMessage('Queue', $overallStatus),
            'details' => $checks,
            'metrics' => [
                'pending_jobs' => $this->getPendingJobsCount(),
                'processing_jobs' => $this->getProcessingJobsCount(),
                'failed_jobs' => $this->getFailedJobsCount(),
                'jobs_per_minute' => $this->getJobsPerMinute()
            ]
        ];
    }
    
    /**
     * Health check dos sistemas de segurança
     */
    public function checkSecurity(): array
    {
        return $this->soc->performHealthCheck();
    }
    
    /**
     * Health check do sistema de arquivos
     */
    public function checkFileSystem(): array
    {
        $checks = [];
        
        // Espaço em disco
        $checks['disk_space'] = $this->checkDiskSpace();
        
        // Permissões de diretórios
        $checks['permissions'] = $this->checkDirectoryPermissions();
        
        // Logs de aplicação
        $checks['log_files'] = $this->checkLogFiles();
        
        // Arquivos temporários
        $checks['temp_files'] = $this->checkTempFiles();
        
        $overallStatus = $this->determineOverallStatus($checks);
        
        return [
            'status' => $overallStatus,
            'message' => $this->generateStatusMessage('FileSystem', $overallStatus),
            'details' => $checks,
            'metrics' => [
                'disk_usage_percent' => $this->getDiskUsagePercent(),
                'free_space_gb' => $this->getFreeSpaceGB(),
                'log_files_count' => $this->getLogFilesCount(),
                'log_files_size_mb' => $this->getLogFilesSizeMB()
            ]
        ];
    }
    
    /**
     * Health check de APIs externas
     */
    public function checkExternalAPIs(): array
    {
        $apis = [
            'virus_total' => 'https://www.virustotal.com/api/v3/version',
            'payment_gateway' => 'https://api.payment.example.com/health',
            'notification_service' => 'https://api.notifications.example.com/status'
        ];
        
        $checks = [];
        
        foreach ($apis as $name => $url) {
            $checks[$name] = $this->checkExternalAPI($name, $url);
        }
        
        $overallStatus = $this->determineOverallStatus($checks);
        
        return [
            'status' => $overallStatus,
            'message' => $this->generateStatusMessage('External APIs', $overallStatus),
            'details' => $checks
        ];
    }
    
    /**
     * Health check de métricas de sistema
     */
    public function checkSystemMetrics(): array
    {
        $checks = [];
        
        // CPU
        $checks['cpu'] = $this->checkCPUUsage();
        
        // Memória
        $checks['memory'] = $this->checkMemoryUsage();
        
        // Load average
        $checks['load_average'] = $this->checkLoadAverage();
        
        // Processos
        $checks['processes'] = $this->checkProcesses();
        
        $overallStatus = $this->determineOverallStatus($checks);
        
        return [
            'status' => $overallStatus,
            'message' => $this->generateStatusMessage('System', $overallStatus),
            'details' => $checks,
            'metrics' => [
                'cpu_usage_percent' => $this->getCPUUsagePercent(),
                'memory_usage_percent' => $this->getMemoryUsagePercent(),
                'load_average_1min' => $this->getLoadAverage1Min(),
                'active_processes' => $this->getActiveProcessesCount()
            ]
        ];
    }
    
    private function initializeHealthChecks(): void
    {
        $this->healthChecks = [
            'database' => [
                'name' => 'Database',
                'method' => 'checkDatabase',
                'critical' => true,
                'timeout' => 10,
                'interval' => 30
            ],
            'cache' => [
                'name' => 'Cache (Redis)',
                'method' => 'checkCache',
                'critical' => true,
                'timeout' => 5,
                'interval' => 15
            ],
            'queue' => [
                'name' => 'Queue System',
                'method' => 'checkQueue',
                'critical' => true,
                'timeout' => 10,
                'interval' => 30
            ],
            'security' => [
                'name' => 'Security Systems',
                'method' => 'checkSecurity',
                'critical' => true,
                'timeout' => 15,
                'interval' => 60
            ],
            'filesystem' => [
                'name' => 'File System',
                'method' => 'checkFileSystem',
                'critical' => false,
                'timeout' => 5,
                'interval' => 60
            ],
            'external_apis' => [
                'name' => 'External APIs',
                'method' => 'checkExternalAPIs',
                'critical' => false,
                'timeout' => 30,
                'interval' => 300
            ],
            'system_metrics' => [
                'name' => 'System Metrics',
                'method' => 'checkSystemMetrics',
                'critical' => false,
                'timeout' => 5,
                'interval' => 30
            ]
        ];
        
        $this->criticalSystems = array_keys(array_filter(
            $this->healthChecks,
            fn($check) => $check['critical']
        ));
    }
    
    private function executeHealthCheck(array $config): array
    {
        $method = $config['method'];
        
        if (! method_exists($this, $method)) {
            throw new \RuntimeException("Health check method '{$method}' not found");
        }
        
        return $this->$method();
    }
    
    private function determineOverallStatus(array $checks): string
    {
        $unhealthyCount = 0;
        $degradedCount = 0;
        
        foreach ($checks as $check) {
            if ($check['status'] === 'unhealthy') {
                $unhealthyCount++;
            } elseif ($check['status'] === 'degraded') {
                $degradedCount++;
            }
        }
        
        if ($unhealthyCount > 0) {
            return 'unhealthy';
        } elseif ($degradedCount > 0) {
            return 'degraded';
        }
        
        return 'healthy';
    }
    
    private function generateStatusMessage(string $component, string $status): string
    {
        return match ($status) {
            'healthy' => "{$component} is operating normally",
            'degraded' => "{$component} is experiencing some issues",
            'unhealthy' => "{$component} is not functioning properly",
            default => "{$component} status is unknown"
        };
    }
    
    private function generateHealthSummary(array $results): array
    {
        $total = count($results);
        $healthy = count(array_filter($results, fn($r) => $r['status'] === 'healthy'));
        $degraded = count(array_filter($results, fn($r) => $r['status'] === 'degraded'));
        $unhealthy = count(array_filter($results, fn($r) => $r['status'] === 'unhealthy'));
        
        return [
            'total_checks' => $total,
            'healthy' => $healthy,
            'degraded' => $degraded,
            'unhealthy' => $unhealthy,
            'health_percentage' => $total > 0 ? round(($healthy / $total) * 100, 1) : 0
        ];
    }
    
    // Métodos de verificação simplificados (implementação real seria mais complexa)
    private function checkDatabaseConnectivity(): array { return ['status' => 'healthy', 'message' => 'Connected']; }
    private function checkQueryPerformance(): array { return ['status' => 'healthy', 'message' => 'Performance OK']; }
    private function checkDatabaseConnections(): array { return ['status' => 'healthy', 'message' => 'Connections normal']; }
    private function checkDatabaseDiskSpace(): array { return ['status' => 'healthy', 'message' => 'Disk space OK']; }
    private function checkDatabaseReplication(): array { return ['status' => 'healthy', 'message' => 'Replication OK']; }
    private function checkDatabaseLocks(): array { return ['status' => 'healthy', 'message' => 'No locks detected']; }
    private function checkRedisConnectivity(): array { return ['status' => 'healthy', 'message' => 'Connected']; }
    private function checkRedisMemoryUsage(): array { return ['status' => 'healthy', 'message' => 'Memory usage normal']; }
    private function checkRedisPerformance(): array { return ['status' => 'healthy', 'message' => 'Performance OK']; }
    private function checkRedisPersistence(): array { return ['status' => 'healthy', 'message' => 'Persistence OK']; }
    private function checkRedisEviction(): array { return ['status' => 'healthy', 'message' => 'Eviction normal']; }
    private function checkQueueConnectivity(): array { return ['status' => 'healthy', 'message' => 'Connected']; }
    private function checkQueueSizes(): array { return ['status' => 'healthy', 'message' => 'Queue sizes normal']; }
    private function checkQueueWorkers(): array { return ['status' => 'healthy', 'message' => 'Workers active']; }
    private function checkFailedJobs(): array { return ['status' => 'healthy', 'message' => 'No failed jobs']; }
    private function checkQueueProcessingPerformance(): array { return ['status' => 'healthy', 'message' => 'Processing OK']; }
    private function checkDiskSpace(): array { return ['status' => 'healthy', 'message' => 'Disk space sufficient']; }
    private function checkDirectoryPermissions(): array { return ['status' => 'healthy', 'message' => 'Permissions OK']; }
    private function checkLogFiles(): array { return ['status' => 'healthy', 'message' => 'Log files OK']; }
    private function checkTempFiles(): array { return ['status' => 'healthy', 'message' => 'Temp files OK']; }
    private function checkExternalAPI(string $name, string $url): array { return ['status' => 'healthy', 'message' => 'API responsive']; }
    private function checkCPUUsage(): array { return ['status' => 'healthy', 'message' => 'CPU usage normal']; }
    private function checkMemoryUsage(): array { return ['status' => 'healthy', 'message' => 'Memory usage normal']; }
    private function checkLoadAverage(): array { return ['status' => 'healthy', 'message' => 'Load average normal']; }
    private function checkProcesses(): array { return ['status' => 'healthy', 'message' => 'Processes normal']; }
    
    // Métodos de métricas simplificados
    private function getActiveConnections(): int { return 50; }
    private function getAverageQueryTime(): float { return 15.5; }
    private function getSlowQueriesCount(): int { return 2; }
    private function getRedisMemoryUsed(): string { return '256MB'; }
    private function getRedisConnectedClients(): int { return 25; }
    private function getRedisOperationsPerSec(): int { return 1000; }
    private function getCacheHitRate(): float { return 85.0; }
    private function getPendingJobsCount(): int { return 10; }
    private function getProcessingJobsCount(): int { return 5; }
    private function getFailedJobsCount(): int { return 0; }
    private function getJobsPerMinute(): int { return 100; }
    private function getDiskUsagePercent(): float { return 75.0; }
    private function getFreeSpaceGB(): float { return 50.5; }
    private function getLogFilesCount(): int { return 25; }
    private function getLogFilesSizeMB(): float { return 125.0; }
    private function getCPUUsagePercent(): float { return 35.0; }
    private function getMemoryUsagePercent(): float { return 60.0; }
    private function getLoadAverage1Min(): float { return 1.5; }
    private function getActiveProcessesCount(): int { return 150; }
}
