<?php

declare(strict_types=1);

namespace ERP\Core\Monitoring;

use ERP\Core\AI\AIEngine;
use ERP\Core\Performance\UltimatePerformanceEngine;

/**
 * APM (Application Performance Monitoring) Agent
 * 
 * Agente avançado de monitoramento de performance de aplicação
 * com instrumentação automática e análise de código em tempo real
 */
class APMAgent
{
    private AIEngine $aiEngine;
    private UltimatePerformanceEngine $performance;
    private array $activeTraces = [];
    private array $transactionStack = [];
    private array $performanceBaselines = [];
    private bool $enabled = true;
    
    public function __construct(
        AIEngine $aiEngine,
        UltimatePerformanceEngine $performance
    ) {
        $this->aiEngine = $aiEngine;
        $this->performance = $performance;
        $this->initializeAgent();
    }
    
    /**
     * Inicia transação APM
     */
    public function startTransaction(string $name, string $type = 'request'): string
    {
        if (!$this->enabled) {
            return '';
        }
        
        $transactionId = uniqid('txn_', true);
        
        $transaction = [
            'id' => $transactionId,
            'name' => $name,
            'type' => $type,
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(true),
            'parent_id' => $this->getCurrentTransactionId(),
            'spans' => [],
            'metadata' => [
                'user_id' => $this->getCurrentUserId(),
                'session_id' => $this->getCurrentSessionId(),
                'tenant_id' => $this->getCurrentTenantId(),
                'request_id' => $this->getCurrentRequestId()
            ],
            'custom_data' => [],
            'errors' => []
        ];
        
        $this->transactionStack[] = $transaction;
        
        return $transactionId;
    }
    
    /**
     * Finaliza transação APM
     */
    public function endTransaction(string $transactionId, array $result = []): void
    {
        if (!$this->enabled || empty($this->transactionStack)) {
            return;
        }
        
        $transaction = array_pop($this->transactionStack);
        
        if ($transaction['id'] !== $transactionId) {
            // Log warning sobre transações não balanceadas
            return;
        }
        
        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);
        
        $transaction['end_time'] = $endTime;
        $transaction['duration'] = ($endTime - $transaction['start_time']) * 1000; // ms
        $transaction['memory_delta'] = $endMemory - $transaction['start_memory'];
        $transaction['result'] = $result;
        
        // Análise de performance com IA
        $performanceAnalysis = $this->analyzeTransactionPerformance($transaction);
        $transaction['performance_analysis'] = $performanceAnalysis;
        
        // Detecção de anomalias
        if ($this->detectPerformanceAnomaly($transaction)) {
            $transaction['anomaly_detected'] = true;
            $this->handlePerformanceAnomaly($transaction);
        }
        
        $this->sendTransactionData($transaction);
    }
    
    /**
     * Inicia span personalizado
     */
    public function startSpan(string $name, string $type = 'custom'): string
    {
        if (!$this->enabled || empty($this->transactionStack)) {
            return '';
        }
        
        $spanId = uniqid('span_', true);
        
        $span = [
            'id' => $spanId,
            'name' => $name,
            'type' => $type,
            'start_time' => microtime(true),
            'parent_id' => $this->getCurrentSpanId(),
            'tags' => [],
            'logs' => []
        ];
        
        $currentTransaction = &$this->transactionStack[count($this->transactionStack) - 1];
        $currentTransaction['spans'][] = $span;
        
        return $spanId;
    }
    
    /**
     * Finaliza span personalizado
     */
    public function endSpan(string $spanId, array $tags = []): void
    {
        if (!$this->enabled || empty($this->transactionStack)) {
            return;
        }
        
        $currentTransaction = &$this->transactionStack[count($this->transactionStack) - 1];
        
        foreach ($currentTransaction['spans'] as &$span) {
            if ($span['id'] === $spanId) {
                $span['end_time'] = microtime(true);
                $span['duration'] = ($span['end_time'] - $span['start_time']) * 1000; // ms
                $span['tags'] = array_merge($span['tags'], $tags);
                break;
            }
        }
    }
    
    /**
     * Instrumenta automaticamente chamadas de banco de dados
     */
    public function instrumentDatabaseQuery(string $query, array $params = []): callable
    {
        return function() use ($query, $params) {
            $spanId = $this->startSpan('db.query', 'db');
            
            $startTime = microtime(true);
            
            try {
                // Execute a query original
                $result = func_get_args()[0]();
                
                $this->endSpan($spanId, [
                    'db.statement' => $this->sanitizeQuery($query),
                    'db.rows_affected' => $this->extractRowsAffected($result),
                    'db.operation' => $this->extractOperation($query),
                    'success' => true
                ]);
                
                return $result;
                
            } catch (\Throwable $e) {
                $this->recordError($e, [
                    'span_id' => $spanId,
                    'query' => $this->sanitizeQuery($query)
                ]);
                
                $this->endSpan($spanId, [
                    'error' => true,
                    'error.message' => $e->getMessage()
                ]);
                
                throw $e;
            }
        };
    }
    
    /**
     * Instrumenta chamadas HTTP externas
     */
    public function instrumentHttpRequest(string $url, string $method = 'GET'): callable
    {
        return function() use ($url, $method) {
            $spanId = $this->startSpan('http.request', 'http');
            
            try {
                $result = func_get_args()[0]();
                
                $this->endSpan($spanId, [
                    'http.url' => $url,
                    'http.method' => $method,
                    'http.status_code' => $this->extractStatusCode($result),
                    'success' => true
                ]);
                
                return $result;
                
            } catch (\Throwable $e) {
                $this->recordError($e, [
                    'span_id' => $spanId,
                    'url' => $url,
                    'method' => $method
                ]);
                
                $this->endSpan($spanId, [
                    'error' => true,
                    'error.message' => $e->getMessage()
                ]);
                
                throw $e;
            }
        };
    }
    
    /**
     * Instrumenta operações de cache
     */
    public function instrumentCacheOperation(string $operation, string $key): callable
    {
        return function() use ($operation, $key) {
            $spanId = $this->startSpan("cache.{$operation}", 'cache');
            
            try {
                $result = func_get_args()[0]();
                
                $this->endSpan($spanId, [
                    'cache.operation' => $operation,
                    'cache.key' => $key,
                    'cache.hit' => $this->isCacheHit($result, $operation),
                    'success' => true
                ]);
                
                return $result;
                
            } catch (\Throwable $e) {
                $this->recordError($e, [
                    'span_id' => $spanId,
                    'cache_operation' => $operation,
                    'cache_key' => $key
                ]);
                
                $this->endSpan($spanId, [
                    'error' => true,
                    'error.message' => $e->getMessage()
                ]);
                
                throw $e;
            }
        };
    }
    
    /**
     * Registra erro personalizado
     */
    public function recordError(\Throwable $error, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }
        
        $errorData = [
            'id' => uniqid('error_', true),
            'timestamp' => time(),
            'message' => $error->getMessage(),
            'type' => get_class($error),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'code' => $error->getCode(),
            'trace' => $error->getTraceAsString(),
            'context' => $context,
            'transaction_id' => $this->getCurrentTransactionId(),
            'span_id' => $context['span_id'] ?? null
        ];
        
        if (!empty($this->transactionStack)) {
            $currentTransaction = &$this->transactionStack[count($this->transactionStack) - 1];
            $currentTransaction['errors'][] = $errorData;
        }
        
        $this->sendErrorData($errorData);
    }
    
    /**
     * Adiciona dados customizados à transação atual
     */
    public function addCustomData(string $key, mixed $value): void
    {
        if (!$this->enabled || empty($this->transactionStack)) {
            return;
        }
        
        $currentTransaction = &$this->transactionStack[count($this->transactionStack) - 1];
        $currentTransaction['custom_data'][$key] = $value;
    }
    
    /**
     * Obtém métricas de performance em tempo real
     */
    public function getPerformanceMetrics(): array
    {
        return [
            'active_transactions' => count($this->transactionStack),
            'avg_response_time' => $this->calculateAverageResponseTime(),
            'throughput' => $this->calculateThroughput(),
            'error_rate' => $this->calculateErrorRate(),
            'apdex_score' => $this->calculateApdexScore(),
            'memory_usage' => memory_get_usage(true),
            'cpu_usage' => sys_getloadavg()[0] ?? 0,
            'database_time' => $this->calculateDatabaseTime(),
            'external_time' => $this->calculateExternalTime(),
            'cache_hit_rate' => $this->calculateCacheHitRate()
        ];
    }
    
    /**
     * Gera relatório de performance detalhado
     */
    public function generatePerformanceReport(array $timeRange): array
    {
        $transactions = $this->getTransactionsInTimeRange($timeRange);
        
        return [
            'period' => $timeRange,
            'summary' => [
                'total_transactions' => count($transactions),
                'avg_response_time' => $this->calculateAverageResponseTime($transactions),
                'p95_response_time' => $this->calculatePercentile($transactions, 95),
                'p99_response_time' => $this->calculatePercentile($transactions, 99),
                'error_rate' => $this->calculateErrorRate($transactions),
                'throughput' => $this->calculateThroughput($transactions)
            ],
            'slowest_transactions' => $this->getSlowestTransactions($transactions, 10),
            'most_frequent_errors' => $this->getMostFrequentErrors($transactions),
            'database_analysis' => $this->analyzeDatabasePerformance($transactions),
            'external_services' => $this->analyzeExternalServices($transactions),
            'memory_analysis' => $this->analyzeMemoryUsage($transactions),
            'recommendations' => $this->generateOptimizationRecommendations($transactions)
        ];
    }
    
    private function initializeAgent(): void
    {
        // Carrega baselines de performance
        $this->loadPerformanceBaselines();
        
        // Registra handlers de shutdown para finalizar transações pendentes
        register_shutdown_function([$this, 'handleShutdown']);
        
        // Configura instrumentação automática
        $this->setupAutoInstrumentation();
    }
    
    private function analyzeTransactionPerformance(array $transaction): array
    {
        $analysis = [
            'performance_score' => $this->calculatePerformanceScore($transaction),
            'bottlenecks' => $this->identifyBottlenecks($transaction),
            'optimization_opportunities' => $this->identifyOptimizationOpportunities($transaction),
            'comparison_to_baseline' => $this->compareToBaseline($transaction),
            'ai_insights' => $this->aiEngine->predict([
                'type' => 'performance_analysis',
                'transaction' => $transaction,
                'historical_data' => $this->getHistoricalPerformanceData($transaction['name'])
            ])
        ];
        
        return $analysis;
    }
    
    private function detectPerformanceAnomaly(array $transaction): bool
    {
        $baseline = $this->getPerformanceBaseline($transaction['name']);
        
        if (!$baseline) {
            return false;
        }
        
        // Verifica se a duração está fora do padrão
        $durationThreshold = $baseline['avg_duration'] * 2.5;
        if ($transaction['duration'] > $durationThreshold) {
            return true;
        }
        
        // Verifica uso de memória anômalo
        $memoryThreshold = $baseline['avg_memory'] * 3;
        if ($transaction['memory_delta'] > $memoryThreshold) {
            return true;
        }
        
        return false;
    }
    
    private function handlePerformanceAnomaly(array $transaction): void
    {
        // Envia alerta para sistema de monitoramento
        $this->sendPerformanceAlert([
            'type' => 'performance_anomaly',
            'transaction' => $transaction,
            'severity' => $this->calculateAnomalySeverity($transaction)
        ]);
    }
    
    // Métodos auxiliares simplificados
    private function getCurrentTransactionId(): ?string { return null; }
    private function getCurrentUserId(): ?string { return null; }
    private function getCurrentSessionId(): ?string { return null; }
    private function getCurrentTenantId(): ?string { return null; }
    private function getCurrentRequestId(): ?string { return null; }
    private function getCurrentSpanId(): ?string { return null; }
    private function sendTransactionData(array $transaction): void {}
    private function sendErrorData(array $error): void {}
    private function sanitizeQuery(string $query): string { return preg_replace('/\d+/', '?', $query); }
    private function extractRowsAffected($result): int { return 0; }
    private function extractOperation(string $query): string { return strtoupper(explode(' ', trim($query))[0]); }
    private function extractStatusCode($result): int { return 200; }
    private function isCacheHit($result, string $operation): bool { return $operation === 'get' && $result !== null; }
    private function calculateAverageResponseTime(array $transactions = null): float { return 100.0; }
    private function calculateThroughput(array $transactions = null): float { return 50.0; }
    private function calculateErrorRate(array $transactions = null): float { return 0.5; }
    private function calculateApdexScore(): float { return 0.95; }
    private function calculateDatabaseTime(): float { return 25.0; }
    private function calculateExternalTime(): float { return 15.0; }
    private function calculateCacheHitRate(): float { return 85.0; }
    private function getTransactionsInTimeRange(array $timeRange): array { return []; }
    private function calculatePercentile(array $transactions, int $percentile): float { return 150.0; }
    private function getSlowestTransactions(array $transactions, int $limit): array { return []; }
    private function getMostFrequentErrors(array $transactions): array { return []; }
    private function analyzeDatabasePerformance(array $transactions): array { return []; }
    private function analyzeExternalServices(array $transactions): array { return []; }
    private function analyzeMemoryUsage(array $transactions): array { return []; }
    private function generateOptimizationRecommendations(array $transactions): array { return []; }
    private function loadPerformanceBaselines(): void {}
    private function setupAutoInstrumentation(): void {}
    private function calculatePerformanceScore(array $transaction): float { return 85.0; }
    private function identifyBottlenecks(array $transaction): array { return []; }
    private function identifyOptimizationOpportunities(array $transaction): array { return []; }
    private function compareToBaseline(array $transaction): array { return []; }
    private function getHistoricalPerformanceData(string $transactionName): array { return []; }
    private function getPerformanceBaseline(string $transactionName): ?array { return null; }
    private function calculateAnomalySeverity(array $transaction): string { return 'medium'; }
    private function sendPerformanceAlert(array $alert): void {}
    
    public function handleShutdown(): void
    {
        // Finaliza transações pendentes
        while (!empty($this->transactionStack)) {
            $transaction = array_pop($this->transactionStack);
            $this->endTransaction($transaction['id']);
        }
    }
}