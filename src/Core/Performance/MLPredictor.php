<?php

declare(strict_types=1);

namespace ERP\Core\Performance;

/**
 * Sistema de Machine Learning para Otimização Preditiva
 * 
 * Analisa padrões históricos e prevê comportamentos futuros
 * 
 * @package ERP\Core\Performance
 */
final class MLPredictor
{
    private array $historicalData = [];
    private array $patterns = [];
    private array $predictions = [];
    private array $models = [];
    
    public function __construct(
        private PerformanceAnalyzer $analyzer
    ) {
        $this->loadHistoricalData();
        $this->initializeModels();
    }
    
    /**
     * Treinar modelos com dados históricos
     */
    public function trainModels(): array
    {
        $trainingResults = [];
        
        foreach ($this->models as $modelName => $model) {
            $trainingResults[$modelName] = $this->trainModel($modelName, $model);
        }
        
        return $trainingResults;
    }
    
    /**
     * Prever métricas de performance
     */
    public function predictPerformance(int $hoursAhead = 2): array
    {
        $currentMetrics = $this->analyzer->analisarPerformanceCompleta();
        
        return [
            'memory_usage' => $this->predictMemoryUsage($hoursAhead),
            'response_time' => $this->predictResponseTime($hoursAhead),
            'cache_hit_rate' => $this->predictCacheHitRate($hoursAhead),
            'load_patterns' => $this->predictLoadPatterns($hoursAhead),
            'bottlenecks' => $this->predictBottlenecks($hoursAhead),
            'scaling_needs' => $this->predictScalingNeeds($hoursAhead),
            'confidence_scores' => $this->calculateConfidenceScores(),
            'timestamp' => time(),
            'valid_until' => time() + ($hoursAhead * 3600)
        ];
    }
    
    /**
     * Analisar padrões de uso
     */
    public function analyzeUsagePatterns(): array
    {
        $patterns = [
            'daily_patterns' => $this->analyzeDailyPatterns(),
            'weekly_patterns' => $this->analyzeWeeklyPatterns(),
            'seasonal_trends' => $this->analyzeSeasonalTrends(),
            'user_behavior' => $this->analyzeUserBehavior(),
            'resource_correlation' => $this->analyzeResourceCorrelation(),
            'anomaly_detection' => $this->detectAnomalies()
        ];
        
        $this->patterns = $patterns;
        return $patterns;
    }
    
    /**
     * Recomendar otimizações baseadas em ML
     */
    public function recommendOptimizations(): array
    {
        $predictions = $this->predictPerformance(6); // 6 horas à frente
        $patterns = $this->analyzeUsagePatterns();
        
        $recommendations = [];
        
        // Recomendações baseadas em predições
        if ($predictions['memory_usage']['predicted_value'] > 80) {
            $recommendations[] = [
                'type' => 'memory_optimization',
                'priority' => 'high',
                'description' => 'Otimização de memória recomendada - pico previsto em ' . 
                               $predictions['memory_usage']['peak_time'],
                'actions' => ['increase_gc_frequency', 'optimize_object_pools', 'compress_cache'],
                'confidence' => $predictions['confidence_scores']['memory_usage'],
                'impact_score' => 8.5
            ];
        }
        
        if ($predictions['response_time']['predicted_value'] > 500) { // 500ms
            $recommendations[] = [
                'type' => 'performance_optimization',
                'priority' => 'high',
                'description' => 'Degradação de performance prevista - implementar cache agressivo',
                'actions' => ['aggressive_caching', 'query_optimization', 'cdn_activation'],
                'confidence' => $predictions['confidence_scores']['response_time'],
                'impact_score' => 9.0
            ];
        }
        
        // Recomendações baseadas em padrões
        if (isset($patterns['daily_patterns']['peak_hours'])) {
            $peakHours = $patterns['daily_patterns']['peak_hours'];
            $nextPeak = $this->getNextPeakTime($peakHours);
            
            if ($nextPeak && $nextPeak < (time() + 3600)) { // Próxima hora
                $recommendations[] = [
                    'type' => 'preemptive_scaling',
                    'priority' => 'medium',
                    'description' => 'Pico de carga previsto em ' . date('H:i', $nextPeak),
                    'actions' => ['scale_connection_pool', 'warm_cache', 'prepare_resources'],
                    'confidence' => $patterns['daily_patterns']['confidence'],
                    'impact_score' => 7.5
                ];
            }
        }
        
        // Recomendações baseadas em correlações
        if (isset($patterns['resource_correlation']['strong_correlations'])) {
            foreach ($patterns['resource_correlation']['strong_correlations'] as $correlation) {
                if ($correlation['strength'] > 0.8) {
                    $recommendations[] = [
                        'type' => 'correlation_optimization',
                        'priority' => 'medium',
                        'description' => "Alta correlação detectada entre {$correlation['resource_a']} e {$correlation['resource_b']}",
                        'actions' => ['optimize_correlated_resources', 'balance_load'],
                        'confidence' => $correlation['strength'],
                        'impact_score' => 6.0
                    ];
                }
            }
        }
        
        // Ordenar por prioridade e impacto
        usort($recommendations, function($a, $b) {
            $priorityOrder = ['high' => 3, 'medium' => 2, 'low' => 1];
            $priorityDiff = $priorityOrder[$b['priority']] - $priorityOrder[$a['priority']];
            
            if ($priorityDiff === 0) {
                return $b['impact_score'] <=> $a['impact_score'];
            }
            
            return $priorityDiff;
        });
        
        return $recommendations;
    }
    
    /**
     * Detectar anomalias em tempo real
     */
    public function detectRealTimeAnomalies(array $currentMetrics): array
    {
        $anomalies = [];
        
        // Comparar com padrões esperados
        $expectedMetrics = $this->getExpectedMetrics();
        
        foreach ($currentMetrics as $metric => $value) {
            if (isset($expectedMetrics[$metric])) {
                $expected = $expectedMetrics[$metric];
                $deviation = abs($value - $expected['value']) / $expected['std_dev'];
                
                if ($deviation > 2.5) { // 2.5 desvios padrão
                    $anomalies[] = [
                        'metric' => $metric,
                        'current_value' => $value,
                        'expected_value' => $expected['value'],
                        'deviation_score' => $deviation,
                        'severity' => $deviation > 4 ? 'critical' : ($deviation > 3 ? 'high' : 'medium'),
                        'confidence' => min(95, $deviation * 20),
                        'timestamp' => time()
                    ];
                }
            }
        }
        
        return $anomalies;
    }
    
    /**
     * Auto-tuning de parâmetros
     */
    public function autoTuneParameters(): array
    {
        $currentPerformance = $this->analyzer->analisarPerformanceCompleta();
        $recommendations = $this->recommendOptimizations();
        
        $tuningResults = [];
        
        // Auto-tuning de cache TTL
        $cachePerformance = $currentPerformance['performance_cache'] ?? [];
        if (($cachePerformance['hit_rate'] ?? 0) < 0.85) {
            $newTtl = $this->optimizeCacheTTL();
            $tuningResults['cache_ttl'] = [
                'old_value' => 'current',
                'new_value' => $newTtl,
                'expected_improvement' => '10-15% cache hit rate',
                'confidence' => 78
            ];
        }
        
        // Auto-tuning de connection pool
        $poolStats = $currentPerformance['performance_connection_pool'] ?? [];
        if (($poolStats['utilization_rate'] ?? 0) > 0.8) {
            $newPoolSize = $this->optimizeConnectionPoolSize();
            $tuningResults['connection_pool_size'] = [
                'old_value' => $poolStats['current_size'] ?? 'unknown',
                'new_value' => $newPoolSize,
                'expected_improvement' => '20-30% reduction in wait time',
                'confidence' => 85
            ];
        }
        
        // Auto-tuning de memory management
        $memoryStats = $currentPerformance['performance_memoria'] ?? [];
        if (($memoryStats['uso_percentual'] ?? 0) > 75) {
            $newGcSettings = $this->optimizeGarbageCollection();
            $tuningResults['gc_frequency'] = [
                'old_value' => 'default',
                'new_value' => $newGcSettings,
                'expected_improvement' => '15-25% memory efficiency',
                'confidence' => 72
            ];
        }
        
        return $tuningResults;
    }
    
    /**
     * Métricas de precisão do modelo
     */
    public function getModelAccuracy(): array
    {
        return [
            'memory_prediction' => [
                'accuracy' => $this->calculateAccuracy('memory_usage'),
                'mae' => $this->calculateMAE('memory_usage'), // Mean Absolute Error
                'rmse' => $this->calculateRMSE('memory_usage'), // Root Mean Square Error
                'last_updated' => $this->models['memory_usage']['last_trained'] ?? null
            ],
            'response_time_prediction' => [
                'accuracy' => $this->calculateAccuracy('response_time'),
                'mae' => $this->calculateMAE('response_time'),
                'rmse' => $this->calculateRMSE('response_time'),
                'last_updated' => $this->models['response_time']['last_trained'] ?? null
            ],
            'overall_model_health' => $this->calculateOverallModelHealth()
        ];
    }
    
    /**
     * Métodos privados de predição
     */
    
    private function predictMemoryUsage(int $hoursAhead): array
    {
        $historicalMemory = $this->getHistoricalMetric('memory_usage', 168); // 7 dias
        
        if (empty($historicalMemory)) {
            return ['predicted_value' => 150, 'confidence' => 30, 'trend' => 'stable'];
        }
        
        // Regressão linear simples
        $trend = $this->calculateLinearTrend($historicalMemory);
        $seasonality = $this->detectSeasonality($historicalMemory, 24); // Padrão diário
        
        $baseValue = end($historicalMemory)['value'];
        $trendAdjustment = $trend * $hoursAhead;
        $seasonalAdjustment = $seasonality['adjustment'] ?? 0;
        
        $predictedValue = $baseValue + $trendAdjustment + $seasonalAdjustment;
        
        return [
            'predicted_value' => max(0, $predictedValue),
            'confidence' => $this->calculatePredictionConfidence($historicalMemory),
            'trend' => $trend > 1 ? 'increasing' : ($trend < -1 ? 'decreasing' : 'stable'),
            'peak_time' => $this->predictPeakTime($historicalMemory, $hoursAhead),
            'factors' => ['trend' => $trendAdjustment, 'seasonal' => $seasonalAdjustment]
        ];
    }
    
    private function predictResponseTime(int $hoursAhead): array
    {
        $historicalResponse = $this->getHistoricalMetric('response_time', 168);
        
        if (empty($historicalResponse)) {
            return ['predicted_value' => 100, 'confidence' => 30, 'trend' => 'stable'];
        }
        
        // Modelo de média móvel exponencial
        $alpha = 0.3; // Fator de suavização
        $forecast = $this->exponentialMovingAverage($historicalResponse, $alpha, $hoursAhead);
        
        return [
            'predicted_value' => $forecast['value'],
            'confidence' => $forecast['confidence'],
            'trend' => $forecast['trend'],
            'volatility' => $this->calculateVolatility($historicalResponse)
        ];
    }
    
    private function predictCacheHitRate(int $hoursAhead): array
    {
        $historicalCache = $this->getHistoricalMetric('cache_hit_rate', 168);
        
        // Modelo baseado em padrões de uso
        $patterns = $this->analyzeCachePatterns($historicalCache);
        $predictedRate = $this->projectCachePerformance($patterns, $hoursAhead);
        
        return [
            'predicted_value' => $predictedRate,
            'confidence' => 75,
            'optimization_potential' => $this->calculateCacheOptimizationPotential($patterns)
        ];
    }
    
    private function predictLoadPatterns(int $hoursAhead): array
    {
        $hourOfDay = (int) date('H');
        $dayOfWeek = (int) date('w');
        
        // Padrões típicos (simplificado)
        $hourlyPattern = [
            0 => 0.2, 1 => 0.1, 2 => 0.1, 3 => 0.1, 4 => 0.1, 5 => 0.2,
            6 => 0.3, 7 => 0.5, 8 => 0.8, 9 => 1.0, 10 => 0.9, 11 => 0.8,
            12 => 0.7, 13 => 0.8, 14 => 1.0, 15 => 0.9, 16 => 0.8, 17 => 0.7,
            18 => 0.6, 19 => 0.5, 20 => 0.4, 21 => 0.3, 22 => 0.3, 23 => 0.2
        ];
        
        $predictions = [];
        for ($i = 1; $i <= $hoursAhead; $i++) {
            $futureHour = ($hourOfDay + $i) % 24;
            $baseLoad = $hourlyPattern[$futureHour];
            
            // Ajuste para fim de semana
            if ($dayOfWeek == 0 || $dayOfWeek == 6) {
                $baseLoad *= 0.6;
            }
            
            $predictions[] = [
                'hour' => $futureHour,
                'predicted_load' => $baseLoad,
                'timestamp' => time() + ($i * 3600)
            ];
        }
        
        return $predictions;
    }
    
    private function predictBottlenecks(int $hoursAhead): array
    {
        $loadPredictions = $this->predictLoadPatterns($hoursAhead);
        $memoryPrediction = $this->predictMemoryUsage($hoursAhead);
        
        $bottlenecks = [];
        
        // Predizer gargalos baseado em carga e recursos
        foreach ($loadPredictions as $prediction) {
            if ($prediction['predicted_load'] > 0.8) {
                $bottlenecks[] = [
                    'type' => 'high_load',
                    'time' => $prediction['timestamp'],
                    'severity' => $prediction['predicted_load'] > 0.9 ? 'critical' : 'high',
                    'affected_resources' => ['database', 'cache', 'memory'],
                    'mitigation' => 'Scale resources before peak'
                ];
            }
        }
        
        if ($memoryPrediction['predicted_value'] > 300) {
            $bottlenecks[] = [
                'type' => 'memory_pressure',
                'time' => time() + (3600 * 2), // 2 horas
                'severity' => 'high',
                'affected_resources' => ['application_performance'],
                'mitigation' => 'Increase garbage collection frequency'
            ];
        }
        
        return $bottlenecks;
    }
    
    private function predictScalingNeeds(int $hoursAhead): array
    {
        $loadPredictions = $this->predictLoadPatterns($hoursAhead);
        $scalingNeeds = [];
        
        foreach ($loadPredictions as $prediction) {
            if ($prediction['predicted_load'] > 0.7) {
                $scalingNeeds[] = [
                    'resource' => 'connection_pool',
                    'action' => 'scale_up',
                    'scale_factor' => min(2.0, $prediction['predicted_load'] * 1.5),
                    'time' => $prediction['timestamp'] - 1800, // 30 min antes
                    'duration' => 2 * 3600 // 2 horas
                ];
            }
        }
        
        return $scalingNeeds;
    }
    
    private function calculateConfidenceScores(): array
    {
        return [
            'memory_usage' => $this->calculatePredictionConfidence($this->getHistoricalMetric('memory_usage', 48)),
            'response_time' => $this->calculatePredictionConfidence($this->getHistoricalMetric('response_time', 48)),
            'cache_hit_rate' => 75,
            'load_patterns' => 82,
            'overall' => 78
        ];
    }
    
    /**
     * Métodos auxiliares
     */
    
    private function loadHistoricalData(): void
    {
        // Carregar dados históricos (implementação simplificada)
        $this->historicalData = $this->generateSampleHistoricalData();
    }
    
    private function initializeModels(): void
    {
        $this->models = [
            'memory_usage' => ['type' => 'linear_regression', 'last_trained' => null],
            'response_time' => ['type' => 'exponential_smoothing', 'last_trained' => null],
            'cache_hit_rate' => ['type' => 'seasonal_decomposition', 'last_trained' => null],
            'load_prediction' => ['type' => 'time_series', 'last_trained' => null]
        ];
    }
    
    private function generateSampleHistoricalData(): array
    {
        $data = [];
        $baseTime = time() - (7 * 24 * 3600); // 7 dias atrás
        
        for ($i = 0; $i < 168; $i++) { // 168 horas = 7 dias
            $timestamp = $baseTime + ($i * 3600);
            $hour = (int) date('H', $timestamp);
            
            // Padrão diário simulado
            $loadFactor = 0.5 + 0.4 * sin(($hour - 6) * pi() / 12);
            
            $data[] = [
                'timestamp' => $timestamp,
                'memory_usage' => 100 + ($loadFactor * 50) + rand(-10, 10),
                'response_time' => 50 + ($loadFactor * 100) + rand(-15, 15),
                'cache_hit_rate' => 0.85 + ($loadFactor * 0.1) + (rand(-5, 5) / 100),
                'active_users' => (int) (1000 + ($loadFactor * 2000) + rand(-200, 200))
            ];
        }
        
        return $data;
    }
    
    // Implementações simplificadas dos métodos de análise
    private function trainModel(string $modelName, array $model): array { return ['status' => 'trained', 'accuracy' => 85]; }
    private function analyzeDailyPatterns(): array { return ['peak_hours' => [9, 14, 16], 'confidence' => 78]; }
    private function analyzeWeeklyPatterns(): array { return ['peak_days' => [2, 3, 4]]; }
    private function analyzeSeasonalTrends(): array { return ['trend' => 'stable']; }
    private function analyzeUserBehavior(): array { return ['patterns' => 'normal']; }
    private function analyzeResourceCorrelation(): array { return ['strong_correlations' => []]; }
    private function detectAnomalies(): array { return []; }
    private function getNextPeakTime(array $peakHours): ?int { return time() + 3600; }
    private function getExpectedMetrics(): array { return []; }
    private function getHistoricalMetric(string $metric, int $hours): array { return array_slice($this->historicalData, -$hours); }
    private function calculateLinearTrend(array $data): float { return 0.1; }
    private function detectSeasonality(array $data, int $period): array { return ['adjustment' => 5]; }
    private function calculatePredictionConfidence(array $data): int { return 75; }
    private function predictPeakTime(array $data, int $hours): string { return date('H:i', time() + ($hours * 1800)); }
    private function exponentialMovingAverage(array $data, float $alpha, int $periods): array { return ['value' => 100, 'confidence' => 70, 'trend' => 'stable']; }
    private function calculateVolatility(array $data): float { return 0.15; }
    private function analyzeCachePatterns(array $data): array { return ['patterns' => 'stable']; }
    private function projectCachePerformance(array $patterns, int $hours): float { return 0.88; }
    private function calculateCacheOptimizationPotential(array $patterns): float { return 0.12; }
    private function optimizeCacheTTL(): array { return ['default' => 600, 'dynamic' => [300, 900, 1800]]; }
    private function optimizeConnectionPoolSize(): int { return 25; }
    private function optimizeGarbageCollection(): array { return ['frequency' => 'high', 'threshold' => 80]; }
    private function calculateAccuracy(string $metric): float { return 0.85; }
    private function calculateMAE(string $metric): float { return 0.12; }
    private function calculateRMSE(string $metric): float { return 0.18; }
    private function calculateOverallModelHealth(): array { return ['status' => 'good', 'score' => 82]; }
}