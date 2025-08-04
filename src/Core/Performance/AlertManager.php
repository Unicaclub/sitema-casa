<?php

declare(strict_types=1);

namespace ERP\Core\Performance;

use ERP\Core\Notificacoes\GerenciadorNotificacoes;

/**
 * Sistema de Alertas Inteligentes
 * 
 * Detecta problemas automaticamente e envia alertas contextuais
 * 
 * @package ERP\Core\Performance
 */
final class AlertManager
{
    private array $rules = [];
    private array $alertHistory = [];
    private array $suppressedAlerts = [];
    private array $config;
    
    public function __construct(
        private PerformanceAnalyzer $analyzer,
        private GerenciadorNotificacoes $notificationManager,
        array $config = []
    ) {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->initializeAlertRules();
    }
    
    /**
     * Verificar e processar alertas
     */
    public function checkAlerts(): array
    {
        $currentMetrics = $this->analyzer->analisarPerformanceCompleta();
        $activeAlerts = [];
        
        foreach ($this->rules as $rule) {
            $alert = $this->evaluateRule($rule, $currentMetrics);
            
            if ($alert && !$this->isAlertSuppressed($alert)) {
                $activeAlerts[] = $alert;
                $this->processAlert($alert);
            }
        }
        
        // Limpar alertas expirados
        $this->cleanupExpiredAlerts();
        
        return $activeAlerts;
    }
    
    /**
     * Configurar regra de alerta customizada
     */
    public function addAlertRule(string $name, array $rule): void
    {
        $this->rules[$name] = array_merge([
            'type' => 'custom',
            'severity' => 'warning',
            'condition' => null,
            'message_template' => '',
            'cooldown_minutes' => 15,
            'auto_resolve' => true,
            'actions' => []
        ], $rule);
    }
    
    /**
     * Suprimir alertas temporariamente
     */
    public function suppressAlert(string $alertType, int $durationMinutes = 60): void
    {
        $this->suppressedAlerts[$alertType] = time() + ($durationMinutes * 60);
    }
    
    /**
     * Obter histórico de alertas
     */
    public function getAlertHistory(int $limitHours = 24): array
    {
        $cutoffTime = time() - ($limitHours * 3600);
        
        return array_filter($this->alertHistory, function($alert) use ($cutoffTime) {
            return $alert['timestamp'] >= $cutoffTime;
        });
    }
    
    /**
     * Análise de tendências de alertas
     */
    public function analyzeAlertTrends(): array
    {
        $history = $this->getAlertHistory(168); // 7 dias
        
        $trends = [
            'total_alerts' => count($history),
            'by_severity' => [],
            'by_type' => [],
            'recurring_patterns' => [],
            'resolution_times' => [],
            'most_frequent' => null
        ];
        
        foreach ($history as $alert) {
            // Por severidade
            $severity = $alert['severity'];
            $trends['by_severity'][$severity] = ($trends['by_severity'][$severity] ?? 0) + 1;
            
            // Por tipo
            $type = $alert['type'];
            $trends['by_type'][$type] = ($trends['by_type'][$type] ?? 0) + 1;
            
            // Tempo de resolução
            if (isset($alert['resolved_at'])) {
                $resolutionTime = $alert['resolved_at'] - $alert['timestamp'];
                $trends['resolution_times'][] = $resolutionTime;
            }
        }
        
        // Alerta mais frequente
        if (!empty($trends['by_type'])) {
            $trends['most_frequent'] = array_search(max($trends['by_type']), $trends['by_type']);
        }
        
        // Tempo médio de resolução
        if (!empty($trends['resolution_times'])) {
            $trends['avg_resolution_time'] = array_sum($trends['resolution_times']) / count($trends['resolution_times']);
        }
        
        return $trends;
    }
    
    /**
     * Dashboard de alertas para API
     */
    public function getDashboardData(): array
    {
        $activeAlerts = $this->checkAlerts();
        $trends = $this->analyzeAlertTrends();
        $recentHistory = $this->getAlertHistory(1); // Última hora
        
        return [
            'active_alerts' => $activeAlerts,
            'alert_count' => count($activeAlerts),
            'severity_breakdown' => $this->getSeverityBreakdown($activeAlerts),
            'trends' => $trends,
            'recent_activity' => $recentHistory,
            'system_health' => $this->calculateSystemHealth($activeAlerts),
            'next_check' => time() + 60, // Próxima verificação em 1 minuto
            'suppressed_alerts' => array_keys($this->suppressedAlerts)
        ];
    }
    
    /**
     * Métodos privados
     */
    
    private function initializeAlertRules(): void
    {
        $this->rules = [
            'high_memory_usage' => [
                'type' => 'memory',
                'severity' => 'warning',
                'condition' => function($metrics) {
                    return ($metrics['performance_memoria']['uso_atual_mb'] ?? 0) > 
                           $this->config['memory_warning_threshold'];
                },
                'message_template' => 'Uso de memória alto: {memory}MB ({percentage}%)',
                'cooldown_minutes' => 10,
                'actions' => ['trigger_gc', 'optimize_memory']
            ],
            
            'critical_memory_usage' => [
                'type' => 'memory',
                'severity' => 'critical',
                'condition' => function($metrics) {
                    return ($metrics['performance_memoria']['uso_atual_mb'] ?? 0) > 
                           $this->config['memory_critical_threshold'];
                },
                'message_template' => 'Uso crítico de memória: {memory}MB ({percentage}%)',
                'cooldown_minutes' => 5,
                'actions' => ['emergency_gc', 'scale_resources', 'alert_admins']
            ],
            
            'slow_response_time' => [
                'type' => 'performance',
                'severity' => 'warning',
                'condition' => function($metrics) {
                    $avgTime = $metrics['performance_database']['tempo_medio_query'] ?? 0;
                    return $avgTime > $this->config['response_time_warning'];
                },
                'message_template' => 'Tempo de resposta lento: {time}ms (limite: {limit}ms)',
                'cooldown_minutes' => 15,
                'actions' => ['optimize_queries', 'warm_cache']
            ],
            
            'low_cache_hit_rate' => [
                'type' => 'cache',
                'severity' => 'warning',
                'condition' => function($metrics) {
                    return ($metrics['performance_cache']['hit_rate'] ?? 1) < 
                           $this->config['cache_hit_rate_minimum'];
                },
                'message_template' => 'Taxa de hit do cache baixa: {rate}% (mínimo: {minimum}%)',
                'cooldown_minutes' => 20,
                'actions' => ['warm_cache', 'review_cache_strategy']
            ],
            
            'high_error_rate' => [
                'type' => 'errors',
                'severity' => 'critical',
                'condition' => function($metrics) {
                    $errorRate = $metrics['performance_connection_pool']['error_rate'] ?? 0;
                    return $errorRate > $this->config['error_rate_threshold'];
                },
                'message_template' => 'Alta taxa de erros: {rate}% (limite: {limit}%)',
                'cooldown_minutes' => 5,
                'actions' => ['check_database', 'scale_connections', 'alert_devs']
            ],
            
            'disk_space_low' => [
                'type' => 'resources',
                'severity' => 'warning',
                'condition' => function($metrics) {
                    // Simular verificação de espaço em disco
                    return $this->checkDiskSpace() > $this->config['disk_usage_threshold'];
                },
                'message_template' => 'Espaço em disco baixo: {usage}% usado',
                'cooldown_minutes' => 60,
                'actions' => ['cleanup_logs', 'compress_data']
            ],
            
            'connection_pool_exhausted' => [
                'type' => 'database',
                'severity' => 'critical',
                'condition' => function($metrics) {
                    $poolStats = $metrics['performance_connection_pool'] ?? [];
                    $utilization = $poolStats['utilization_rate'] ?? 0;
                    return $utilization > 0.9; // 90% do pool em uso
                },
                'message_template' => 'Pool de conexões quase esgotado: {utilization}%',
                'cooldown_minutes' => 3,
                'actions' => ['scale_pool', 'optimize_queries', 'emergency_scaling']
            ]
        ];
    }
    
    private function evaluateRule(array $rule, array $metrics): ?array
    {
        if (!is_callable($rule['condition'])) {
            return null;
        }
        
        if (!$rule['condition']($metrics)) {
            return null;
        }
        
        // Verificar cooldown
        $lastAlert = $this->getLastAlertOfType($rule['type']);
        if ($lastAlert && (time() - $lastAlert['timestamp']) < ($rule['cooldown_minutes'] * 60)) {
            return null;
        }
        
        // Criar alerta
        $alert = [
            'id' => uniqid('alert_'),
            'type' => $rule['type'],
            'severity' => $rule['severity'],
            'message' => $this->formatMessage($rule['message_template'], $metrics),
            'timestamp' => time(),
            'metrics_snapshot' => $metrics,
            'actions' => $rule['actions'] ?? [],
            'auto_resolve' => $rule['auto_resolve'] ?? true,
            'resolved' => false
        ];
        
        return $alert;
    }
    
    private function processAlert(array $alert): void
    {
        // Adicionar ao histórico
        $this->alertHistory[] = $alert;
        
        // Executar ações automáticas
        $this->executeAlertActions($alert);
        
        // Enviar notificações
        $this->sendNotifications($alert);
        
        // Log do alerta
        error_log("PERFORMANCE ALERT [{$alert['severity']}]: {$alert['message']}");
    }
    
    private function executeAlertActions(array $alert): void
    {
        foreach ($alert['actions'] as $action) {
            try {
                match($action) {
                    'trigger_gc' => $this->triggerGarbageCollection(),
                    'optimize_memory' => $this->optimizeMemory(),
                    'emergency_gc' => $this->emergencyGarbageCollection(),
                    'scale_resources' => $this->scaleResources(),
                    'optimize_queries' => $this->optimizeQueries(),
                    'warm_cache' => $this->warmCache(),
                    'check_database' => $this->checkDatabase(),
                    'scale_connections' => $this->scaleConnections(),
                    'cleanup_logs' => $this->cleanupLogs(),
                    'compress_data' => $this->compressData(),
                    'scale_pool' => $this->scaleConnectionPool(),
                    'emergency_scaling' => $this->emergencyScaling(),
                    default => null
                };
            } catch (\Exception $e) {
                error_log("Erro ao executar ação de alerta '{$action}': " . $e->getMessage());
            }
        }
    }
    
    private function sendNotifications(array $alert): void
    {
        $channels = match($alert['severity']) {
            'critical' => ['email', 'slack', 'sms'],
            'warning' => ['email', 'slack'],
            'info' => ['slack'],
            default => ['slack']
        };
        
        foreach ($channels as $channel) {
            try {
                $this->notificationManager->enviar([
                    'canal' => $channel,
                    'titulo' => "Alerta de Performance [{$alert['severity']}]",
                    'mensagem' => $alert['message'],
                    'dados' => [
                        'alert_id' => $alert['id'],
                        'timestamp' => $alert['timestamp'],
                        'type' => $alert['type']
                    ]
                ]);
            } catch (\Exception $e) {
                error_log("Erro ao enviar notificação por {$channel}: " . $e->getMessage());
            }
        }
    }
    
    private function isAlertSuppressed(array $alert): bool
    {
        $suppressedUntil = $this->suppressedAlerts[$alert['type']] ?? 0;
        return time() < $suppressedUntil;
    }
    
    private function getLastAlertOfType(string $type): ?array
    {
        $filtered = array_filter($this->alertHistory, fn($alert) => $alert['type'] === $type);
        
        if (empty($filtered)) {
            return null;
        }
        
        return end($filtered);
    }
    
    private function formatMessage(string $template, array $metrics): string
    {
        $replacements = [
            '{memory}' => $metrics['performance_memoria']['uso_atual_mb'] ?? 'N/A',
            '{percentage}' => $metrics['performance_memoria']['uso_percentual'] ?? 'N/A',
            '{time}' => ($metrics['performance_database']['tempo_medio_query'] ?? 0) * 1000,
            '{limit}' => $this->config['response_time_warning'],
            '{rate}' => ($metrics['performance_cache']['hit_rate'] ?? 0) * 100,
            '{minimum}' => $this->config['cache_hit_rate_minimum'] * 100,
            '{usage}' => $this->checkDiskSpace(),
            '{utilization}' => ($metrics['performance_connection_pool']['utilization_rate'] ?? 0) * 100
        ];
        
        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }
    
    private function cleanupExpiredAlerts(): void
    {
        $cutoffTime = time() - (7 * 24 * 3600); // 7 dias
        
        $this->alertHistory = array_filter($this->alertHistory, function($alert) use ($cutoffTime) {
            return $alert['timestamp'] >= $cutoffTime;
        });
        
        // Limpar supressões expiradas
        foreach ($this->suppressedAlerts as $type => $until) {
            if (time() >= $until) {
                unset($this->suppressedAlerts[$type]);
            }
        }
    }
    
    private function getSeverityBreakdown(array $alerts): array
    {
        $breakdown = ['critical' => 0, 'warning' => 0, 'info' => 0];
        
        foreach ($alerts as $alert) {
            $severity = $alert['severity'] ?? 'info';
            $breakdown[$severity] = ($breakdown[$severity] ?? 0) + 1;
        }
        
        return $breakdown;
    }
    
    private function calculateSystemHealth(array $alerts): array
    {
        $criticalCount = count(array_filter($alerts, fn($a) => $a['severity'] === 'critical'));
        $warningCount = count(array_filter($alerts, fn($a) => $a['severity'] === 'warning'));
        
        if ($criticalCount > 0) {
            $status = 'critical';
            $score = max(0, 50 - ($criticalCount * 10));
        } elseif ($warningCount > 3) {
            $status = 'degraded';
            $score = max(50, 80 - ($warningCount * 5));
        } elseif ($warningCount > 0) {
            $status = 'warning';
            $score = max(70, 90 - ($warningCount * 3));
        } else {
            $status = 'healthy';
            $score = 100;
        }
        
        return [
            'status' => $status,
            'score' => $score,
            'critical_alerts' => $criticalCount,
            'warning_alerts' => $warningCount
        ];
    }
    
    private function getDefaultConfig(): array
    {
        return [
            'memory_warning_threshold' => 200, // MB
            'memory_critical_threshold' => 400, // MB
            'response_time_warning' => 0.5, // seconds
            'cache_hit_rate_minimum' => 0.8, // 80%
            'error_rate_threshold' => 0.05, // 5%
            'disk_usage_threshold' => 85, // 85%
            'notification_channels' => ['email', 'slack'],
            'auto_actions_enabled' => true
        ];
    }
    
    // Ações automáticas (implementações simplificadas)
    private function triggerGarbageCollection(): void { gc_collect_cycles(); }
    private function optimizeMemory(): void { /* Implementar otimização */ }
    private function emergencyGarbageCollection(): void { for($i=0;$i<3;$i++) gc_collect_cycles(); }
    private function scaleResources(): void { /* Implementar scaling */ }
    private function optimizeQueries(): void { /* Implementar otimização */ }
    private function warmCache(): void { /* Implementar cache warming */ }
    private function checkDatabase(): void { /* Implementar verificação */ }
    private function scaleConnections(): void { /* Implementar scaling de conexões */ }
    private function cleanupLogs(): void { /* Implementar limpeza */ }
    private function compressData(): void { /* Implementar compressão */ }
    private function scaleConnectionPool(): void { /* Implementar scaling do pool */ }
    private function emergencyScaling(): void { /* Implementar scaling de emergência */ }
    
    private function checkDiskSpace(): float
    {
        $total = disk_total_space('/');
        $free = disk_free_space('/');
        
        if ($total === false || $free === false) {
            return 0;
        }
        
        return (($total - $free) / $total) * 100;
    }
}