<?php

namespace Core\MultiTenant;

use Core\Database\Database;
use Core\Logger;
use Core\Cache\CacheManager;
use Core\Notificacoes\GerenciadorNotificacoes;

/**
 * MonitoringManager - Sistema de monitoramento multi-tenant
 * 
 * Responsável por:
 * - Monitorar métricas de performance por tenant
 * - Detectar vazamento de dados entre tenants
 * - Gerar alertas para atividades suspeitas
 * - Coletar estatísticas de uso
 */
class MonitoringManager
{
    private Database $database;
    private Logger $logger;
    private CacheManager $cache;
    private GerenciadorNotificacoes $notifications;
    private AuditLogger $auditLogger;
    
    // Thresholds para alertas
    const MAX_CROSS_TENANT_ATTEMPTS_PER_HOUR = 5;
    const MAX_QUERY_TIME_MS = 1000;
    const MAX_MEMORY_USAGE_MB = 512;
    const MAX_API_ERRORS_PER_MINUTE = 10;
    
    public function __construct(
        Database $database,
        Logger $logger,
        CacheManager $cache,
        GerenciadorNotificacoes $notifications,
        AuditLogger $auditLogger
    ) {
        $this->database = $database;
        $this->logger = $logger;
        $this->cache = $cache;
        $this->notifications = $notifications;
        $this->auditLogger = $auditLogger;
    }
    
    /**
     * Coleta métricas em tempo real
     */
    public function collectMetrics(int $tenantId): array
    {
        $startTime = microtime(true);
        
        $metrics = [
            'tenant_id' => $tenantId,
            'timestamp' => time(),
            'performance' => $this->collectPerformanceMetrics($tenantId),
            'security' => $this->collectSecurityMetrics($tenantId),
            'usage' => $this->collectUsageMetrics($tenantId),
            'health' => $this->collectHealthMetrics($tenantId)
        ];
        
        $collectionTime = (microtime(true) - $startTime) * 1000;
        $metrics['collection_time_ms'] = round($collectionTime, 2);
        
        // Armazenar métricas
        $this->storeMetrics($metrics);
        
        // Verificar alertas
        $this->checkAlerts($metrics);
        
        return $metrics;
    }
    
    /**
     * Coleta métricas de performance
     */
    private function collectPerformanceMetrics(int $tenantId): array
    {
        $cacheKey = "tenant_performance_metrics:{$tenantId}";
        
        return $this->cache->remember($cacheKey, 300, function() use ($tenantId) {
            $last24h = date('Y-m-d H:i:s', strtotime('-24 hours'));
            
            // Tempo médio de queries
            $avgQueryTime = $this->database->table('performance_metrics')
                ->where('tenant_id', $tenantId)
                ->where('metric_name', 'query_time')
                ->where('collected_at', '>=', $last24h)
                ->avg('metric_value') ?? 0;
            
            // Queries lentas (> 1s)
            $slowQueries = $this->database->table('performance_metrics')
                ->where('tenant_id', $tenantId)
                ->where('metric_name', 'query_time')
                ->where('metric_value', '>', 1000)
                ->where('collected_at', '>=', $last24h)
                ->count();
            
            // Uso de memória
            $avgMemoryUsage = $this->database->table('performance_metrics')
                ->where('tenant_id', $tenantId)
                ->where('metric_name', 'memory_usage')
                ->where('collected_at', '>=', $last24h)
                ->avg('metric_value') ?? 0;
            
            // Cache hit rate
            $cacheHitRate = $this->calculateCacheHitRate($tenantId);
            
            return [
                'avg_query_time_ms' => round($avgQueryTime, 2),
                'slow_queries_24h' => $slowQueries,
                'avg_memory_usage_mb' => round($avgMemoryUsage / 1024 / 1024, 2),
                'cache_hit_rate_percent' => $cacheHitRate
            ];
        });
    }
    
    /**
     * Coleta métricas de segurança
     */
    private function collectSecurityMetrics(int $tenantId): array
    {
        $last24h = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $lastHour = date('Y-m-d H:i:s', strtotime('-1 hour'));
        
        // Tentativas de acesso cruzado
        $crossTenantAttempts = $this->database->table('tenant_access_logs')
            ->where('current_tenant_id', $tenantId)
            ->where('access_granted', false)
            ->where('created_at', '>=', $last24h)
            ->count();
        
        $crossTenantAttemptsLastHour = $this->database->table('tenant_access_logs')
            ->where('current_tenant_id', $tenantId)
            ->where('access_granted', false)
            ->where('created_at', '>=', $lastHour)
            ->count();
        
        // Falhas de autenticação
        $authFailures = $this->database->table('audit_logs')
            ->where('tenant_id', $tenantId)
            ->where('category', 'authentication')
            ->where('level', 'error')
            ->where('created_at', '>=', $last24h)
            ->count();
        
        // Eventos de segurança críticos
        $criticalSecurityEvents = $this->database->table('audit_logs')
            ->where('tenant_id', $tenantId)
            ->where('category', 'security')
            ->where('level', 'critical')
            ->where('created_at', '>=', $last24h)
            ->count();
        
        // IPs únicos de acesso
        $uniqueIPs = $this->database->table('audit_logs')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $last24h)
            ->distinct()
            ->count('ip_address');
        
        return [
            'cross_tenant_attempts_24h' => $crossTenantAttempts,
            'cross_tenant_attempts_1h' => $crossTenantAttemptsLastHour,
            'auth_failures_24h' => $authFailures,
            'critical_security_events_24h' => $criticalSecurityEvents,
            'unique_ips_24h' => $uniqueIPs
        ];
    }
    
    /**
     * Coleta métricas de uso
     */
    private function collectUsageMetrics(int $tenantId): array
    {
        $last24h = date('Y-m-d H:i:s', strtotime('-24 hours'));
        
        // Usuários ativos
        $activeUsers = $this->database->table('audit_logs')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $last24h)
            ->distinct()
            ->count('user_id');
        
        // Requests de API
        $apiRequests = $this->database->table('audit_logs')
            ->where('tenant_id', $tenantId)
            ->where('category', 'api_access')
            ->where('created_at', '>=', $last24h)
            ->count();
        
        // Operações de dados
        $dataOperations = $this->database->table('audit_logs')
            ->where('tenant_id', $tenantId)
            ->where('category', 'data_operation')
            ->where('created_at', '>=', $last24h)
            ->count();
        
        // Tamanho dos dados do tenant
        $dataSize = $this->calculateTenantDataSize($tenantId);
        
        return [
            'active_users_24h' => $activeUsers,
            'api_requests_24h' => $apiRequests,
            'data_operations_24h' => $dataOperations,
            'data_size_mb' => $dataSize
        ];
    }
    
    /**
     * Coleta métricas de saúde do sistema
     */
    private function collectHealthMetrics(int $tenantId): array
    {
        $lastHour = date('Y-m-d H:i:s', strtotime('-1 hour'));
        
        // Erros da aplicação
        $applicationErrors = $this->database->table('audit_logs')
            ->where('tenant_id', $tenantId)
            ->where('level', 'error')
            ->where('created_at', '>=', $lastHour)
            ->count();
        
        // Conectividade com banco
        $dbConnectivity = $this->testDatabaseConnectivity();
        
        // Status do cache
        $cacheStatus = $this->testCacheConnectivity();
        
        // Uptime do tenant (último acesso)
        $lastActivity = $this->database->table('audit_logs')
            ->where('tenant_id', $tenantId)
            ->max('created_at');
        
        return [
            'application_errors_1h' => $applicationErrors,
            'database_connectivity' => $dbConnectivity,
            'cache_connectivity' => $cacheStatus,
            'last_activity' => $lastActivity,
            'status' => $this->calculateTenantHealthStatus($tenantId)
        ];
    }
    
    /**
     * Verifica alertas baseados nas métricas
     */
    private function checkAlerts(array $metrics): void
    {
        $tenantId = $metrics['tenant_id'];
        $alerts = [];
        
        // Alert: Muitas tentativas de acesso cruzado
        if ($metrics['security']['cross_tenant_attempts_1h'] >= self::MAX_CROSS_TENANT_ATTEMPTS_PER_HOUR) {
            $alerts[] = [
                'type' => 'cross_tenant_access_spike',
                'severity' => 'critical',
                'message' => "Detectadas {$metrics['security']['cross_tenant_attempts_1h']} tentativas de acesso cruzado na última hora",
                'tenant_id' => $tenantId
            ];
        }
        
        // Alert: Queries muito lentas
        if ($metrics['performance']['avg_query_time_ms'] > self::MAX_QUERY_TIME_MS) {
            $alerts[] = [
                'type' => 'slow_query_performance',
                'severity' => 'warning',
                'message' => "Tempo médio de query está em {$metrics['performance']['avg_query_time_ms']}ms",
                'tenant_id' => $tenantId
            ];
        }
        
        // Alert: Alto uso de memória
        if ($metrics['performance']['avg_memory_usage_mb'] > self::MAX_MEMORY_USAGE_MB) {
            $alerts[] = [
                'type' => 'high_memory_usage',
                'severity' => 'warning',
                'message' => "Uso de memória está em {$metrics['performance']['avg_memory_usage_mb']}MB",
                'tenant_id' => $tenantId
            ];
        }
        
        // Alert: Muitos erros de aplicação
        if ($metrics['health']['application_errors_1h'] >= self::MAX_API_ERRORS_PER_MINUTE * 60) {
            $alerts[] = [
                'type' => 'high_error_rate',
                'severity' => 'critical',
                'message' => "Alto número de erros: {$metrics['health']['application_errors_1h']} na última hora",
                'tenant_id' => $tenantId
            ];
        }
        
        // Alert: Cache hit rate baixo
        if ($metrics['performance']['cache_hit_rate_percent'] < 70) {
            $alerts[] = [
                'type' => 'low_cache_hit_rate',
                'severity' => 'warning',
                'message' => "Cache hit rate baixo: {$metrics['performance']['cache_hit_rate_percent']}%",
                'tenant_id' => $tenantId
            ];
        }
        
        // Processar alertas
        foreach ($alerts as $alert) {
            $this->processAlert($alert);
        }
    }
    
    /**
     * Processa um alerta
     */
    private function processAlert(array $alert): void
    {
        // Log do alerta
        $this->logger->log($alert['severity'], "MultiTenant Alert: {$alert['type']}", $alert);
        
        // Registrar na auditoria
        $this->auditLogger->logSecurityEvent(
            "monitoring_alert_{$alert['type']}",
            $alert['severity'],
            $alert
        );
        
        // Enviar notificação se crítico
        if ($alert['severity'] === 'critical') {
            $this->sendCriticalAlert($alert);
        }
        
        // Armazenar alerta para dashboard
        $this->storeAlert($alert);
    }
    
    /**
     * Envia alerta crítico
     */
    private function sendCriticalAlert(array $alert): void
    {
        try {
            // Notificar administradores do tenant
            $admins = $this->getTenantAdmins($alert['tenant_id']);
            
            foreach ($admins as $admin) {
                $this->notifications->enviarNotificacao(
                    $admin['id'],
                    'Alerta Crítico de Segurança',
                    $alert['message'],
                    'security_alert',
                    [
                        'alert_type' => $alert['type'],
                        'severity' => $alert['severity'],
                        'tenant_id' => $alert['tenant_id']
                    ]
                );
            }
            
            // Notificar também equipe de suporte via email/Slack
            $this->sendExternalAlert($alert);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to send critical alert', [
                'error' => $e->getMessage(),
                'alert' => $alert
            ]);
        }
    }
    
    /**
     * Detecta potencial vazamento de dados
     */
    public function detectDataLeakage(): array
    {
        $leakages = [];
        $last24h = date('Y-m-d H:i:s', strtotime('-24 hours'));
        
        // 1. Verificar queries sem filtro por tenant
        $suspiciousQueries = $this->database->table('performance_metrics')
            ->where('metric_name', 'query_without_tenant_filter')
            ->where('collected_at', '>=', $last24h)
            ->get();
        
        if (count($suspiciousQueries) > 0) {
            $leakages[] = [
                'type' => 'queries_without_tenant_filter',
                'count' => count($suspiciousQueries),
                'severity' => 'critical',
                'description' => 'Queries executadas sem filtro por tenant_id'
            ];
        }
        
        // 2. Verificar responses com dados de múltiplos tenants
        $mixedDataResponses = $this->database->table('audit_logs')
            ->where('action', 'data_leakage_detected')
            ->where('created_at', '>=', $last24h)
            ->count();
        
        if ($mixedDataResponses > 0) {
            $leakages[] = [
                'type' => 'mixed_tenant_data_in_response',
                'count' => $mixedDataResponses,
                'severity' => 'critical',
                'description' => 'Responses contendo dados de múltiplos tenants'
            ];
        }
        
        // 3. Verificar acessos cross-tenant bem-sucedidos
        $successfulCrossAccess = $this->database->table('tenant_access_logs')
            ->where('access_granted', true)
            ->whereRaw('current_tenant_id != requested_tenant_id')
            ->where('created_at', '>=', $last24h)
            ->count();
        
        if ($successfulCrossAccess > 0) {
            $leakages[] = [
                'type' => 'successful_cross_tenant_access',
                'count' => $successfulCrossAccess,
                'severity' => 'warning',
                'description' => 'Acessos cross-tenant que foram permitidos'
            ];
        }
        
        return $leakages;
    }
    
    /**
     * Gera relatório de segurança multi-tenant
     */
    public function generateSecurityReport(int $tenantId, int $days = 7): array
    {
        $startDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $report = [
            'tenant_id' => $tenantId,
            'period_days' => $days,
            'generated_at' => date('Y-m-d H:i:s'),
            'security_metrics' => $this->collectSecurityMetrics($tenantId),
            'audit_stats' => $this->auditLogger->getTenantAuditStats($tenantId, $days),
            'suspicious_activity' => $this->auditLogger->detectSuspiciousActivity($tenantId, $days * 24),
            'data_leakage_detection' => $this->detectDataLeakage(),
            'recommendations' => $this->generateSecurityRecommendations($tenantId)
        ];
        
        // Log da geração do relatório
        $this->auditLogger->logTenantOperation('security_report_generated', $tenantId, [
            'period_days' => $days,
            'report_size' => strlen(json_encode($report))
        ]);
        
        return $report;
    }
    
    /**
     * Calcula cache hit rate
     */
    private function calculateCacheHitRate(int $tenantId): float
    {
        $cacheKey = "cache_stats:{$tenantId}";
        $stats = $this->cache->get($cacheKey, ['hits' => 0, 'misses' => 0]);
        
        $total = $stats['hits'] + $stats['misses'];
        return $total > 0 ? ($stats['hits'] / $total) * 100 : 0;
    }
    
    /**
     * Calcula tamanho dos dados do tenant
     */
    private function calculateTenantDataSize(int $tenantId): float
    {
        $tables = [
            'clientes', 'produtos', 'vendas', 'transacoes_financeiras',
            'audit_logs', 'movimentacoes_estoque'
        ];
        
        $totalSize = 0;
        
        foreach ($tables as $table) {
            try {
                $result = $this->database->select("
                    SELECT 
                        (data_length + index_length) / 1024 / 1024 as size_mb
                    FROM information_schema.tables 
                    WHERE table_schema = DATABASE() 
                    AND table_name = ?
                ", [$table]);
                
                if (!empty($result)) {
                    $totalSize += $result[0]['size_mb'] ?? 0;
                }
            } catch (\Exception $e) {
                // Ignora erro na tabela
            }
        }
        
        return round($totalSize, 2);
    }
    
    /**
     * Testa conectividade com banco
     */
    private function testDatabaseConnectivity(): bool
    {
        try {
            $this->database->select('SELECT 1');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Testa conectividade com cache
     */
    private function testCacheConnectivity(): bool
    {
        try {
            $testKey = 'connectivity_test_' . time();
            $this->cache->put($testKey, 'test', 60);
            $result = $this->cache->get($testKey);
            $this->cache->forget($testKey);
            return $result === 'test';
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Calcula status de saúde do tenant
     */
    private function calculateTenantHealthStatus(int $tenantId): string
    {
        $metrics = $this->collectSecurityMetrics($tenantId);
        
        if ($metrics['critical_security_events_24h'] > 0) {
            return 'critical';
        }
        
        if ($metrics['cross_tenant_attempts_1h'] > 0 || $metrics['auth_failures_24h'] > 10) {
            return 'warning';
        }
        
        return 'healthy';
    }
    
    /**
     * Armazena métricas
     */
    private function storeMetrics(array $metrics): void
    {
        try {
            $this->database->table('tenant_monitoring_metrics')->insert([
                'tenant_id' => $metrics['tenant_id'],
                'metrics_data' => json_encode($metrics),
                'collected_at' => date('Y-m-d H:i:s', $metrics['timestamp'])
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to store monitoring metrics', [
                'error' => $e->getMessage(),
                'tenant_id' => $metrics['tenant_id']
            ]);
        }
    }
    
    /**
     * Armazena alerta
     */
    private function storeAlert(array $alert): void
    {
        try {
            $this->database->table('tenant_monitoring_alerts')->insert([
                'tenant_id' => $alert['tenant_id'],
                'alert_type' => $alert['type'],
                'severity' => $alert['severity'],
                'message' => $alert['message'],
                'alert_data' => json_encode($alert),
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to store alert', [
                'error' => $e->getMessage(),
                'alert' => $alert
            ]);
        }
    }
    
    /**
     * Obtém administradores do tenant
     */
    private function getTenantAdmins(int $tenantId): array
    {
        return $this->database->table('users')
            ->where('tenant_id', $tenantId)
            ->where('is_admin', true)
            ->where('active', true)
            ->select(['id', 'name', 'email'])
            ->get();
    }
    
    /**
     * Envia alerta externo (email/Slack)
     */
    private function sendExternalAlert(array $alert): void
    {
        // Implementar integração com sistemas externos
        // Por exemplo: Slack, email, PagerDuty, etc.
        $this->logger->critical('External alert would be sent', $alert);
    }
    
    /**
     * Gera recomendações de segurança
     */
    private function generateSecurityRecommendations(int $tenantId): array
    {
        $recommendations = [];
        $metrics = $this->collectSecurityMetrics($tenantId);
        
        if ($metrics['cross_tenant_attempts_24h'] > 0) {
            $recommendations[] = [
                'type' => 'security',
                'priority' => 'high',
                'title' => 'Revisar permissões de usuários',
                'description' => 'Foram detectadas tentativas de acesso cruzado. Revise as permissões dos usuários.'
            ];
        }
        
        if ($metrics['auth_failures_24h'] > 20) {
            $recommendations[] = [
                'type' => 'security',
                'priority' => 'medium',
                'title' => 'Implementar rate limiting mais restritivo',
                'description' => 'Alto número de falhas de autenticação detectado.'
            ];
        }
        
        $performance = $this->collectPerformanceMetrics($tenantId);
        if ($performance['cache_hit_rate_percent'] < 80) {
            $recommendations[] = [
                'type' => 'performance',
                'priority' => 'medium',
                'title' => 'Otimizar estratégia de cache',
                'description' => 'Taxa de acerto do cache está baixa, considere revisar a estratégia de cache.'
            ];
        }
        
        return $recommendations;
    }
}