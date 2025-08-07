<?php

declare(strict_types=1);

namespace ERP\Core\Performance;

use ERP\Core\Database\DatabaseManager;
use ERP\Core\Cache\CacheInterface;

/**
 * Bootstrap de Performance Suprema
 * 
 * Inicialização automática de todos os componentes de performance
 * 
 * @package ERP\Core\Performance
 */
final class PerformanceBootstrap
{
    private static ?self $instance = null;
    
    private ?PerformanceAnalyzer $analyzer = null;
    private ?QueryOptimizer $queryOptimizer = null;
    private ?CacheOtimizado $cacheOtimizado = null;
    private ?MemoryManager $memoryManager = null;
    private ?CompressionManager $compressionManager = null;
    private ?ConnectionPool $connectionPool = null;
    private ?LazyLoader $lazyLoader = null;
    
    private array $config = [];
    private bool $initialized = false;
    
    private function __construct() {}
    
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    
    /**
     * Inicializar sistema de performance suprema
     */
    public function initialize(DatabaseManager $database, CacheInterface $cache, array $config = []): self
    {
        if ($this->initialized) {
            return $this;
        }
        
        $this->config = array_merge($this->getDefaultConfig(), $config);
        
        // Inicializar componentes na ordem correta
        $this->initializeMemoryManager();
        $this->initializeConnectionPool($database, $config['database'] ?? []);
        $this->initializeCompressionManager();
        $this->initializeCacheOtimizado($cache, $database);
        $this->initializeQueryOptimizer($database);
        $this->initializeLazyLoader($database, $cache);
        $this->initializePerformanceAnalyzer($database, $cache);
        
        // Configurar hooks automáticos
        $this->setupAutoHooks();
        
        $this->initialized = true;
        
        return $this;
    }
    
    /**
     * Obter analisador de performance
     */
    public function getAnalyzer(): PerformanceAnalyzer
    {
        $this->ensureInitialized();
        return $this->analyzer;
    }
    
    /**
     * Obter otimizador de queries
     */
    public function getQueryOptimizer(): QueryOptimizer
    {
        $this->ensureInitialized();
        return $this->queryOptimizer;
    }
    
    /**
     * Obter cache otimizado
     */
    public function getCacheOtimizado(): CacheOtimizado
    {
        $this->ensureInitialized();
        return $this->cacheOtimizado;
    }
    
    /**
     * Obter gerenciador de memória
     */
    public function getMemoryManager(): MemoryManager
    {
        $this->ensureInitialized();
        return $this->memoryManager;
    }
    
    /**
     * Obter gerenciador de compressão
     */
    public function getCompressionManager(): CompressionManager
    {
        $this->ensureInitialized();
        return $this->compressionManager;
    }
    
    /**
     * Obter pool de conexões
     */
    public function getConnectionPool(): ConnectionPool
    {
        $this->ensureInitialized();
        return $this->connectionPool;
    }
    
    /**
     * Obter lazy loader
     */
    public function getLazyLoader(): LazyLoader
    {
        $this->ensureInitialized();
        return $this->lazyLoader;
    }
    
    /**
     * Executar análise de performance completa
     */
    public function analyzePerformance(): array
    {
        return $this->getAnalyzer()->analisarPerformanceCompleta();
    }
    
    /**
     * Executar benchmark rápido
     */
    public function quickBenchmark(): array
    {
        return $this->getAnalyzer()->executarBenchmarkTempoReal();
    }
    
    /**
     * Otimização automática
     */
    public function autoOptimize(): void
    {
        $this->getMemoryManager()->otimizacaoAutomatica();
        $this->getCacheOtimizado()->autoOtimizar();
        $this->getLazyLoader()->otimizarAutomaticamente();
        $this->getConnectionPool()->autoScale();
    }
    
    /**
     * Middleware de compressão HTTP
     */
    public function compressResponse(string $content, array $headers = []): array
    {
        return $this->getCompressionManager()->comprimirRespostaHTTP($content, $headers);
    }
    
    /**
     * Cache inteligente com wrapper
     */
    public function cache(string $key, callable $callback, ?int $ttl = null): mixed
    {
        return $this->getCacheOtimizado()->remember($key, $callback, $ttl);
    }
    
    /**
     * Lazy loading com wrapper
     */
    public function lazy(string $type, string|int $id, array $options = []): \Closure
    {
        return $this->getLazyLoader()->load($type, $id, $options);
    }
    
    /**
     * Query otimizada com wrapper
     */
    public function optimizedQuery(string $type, array $params = []): array
    {
        return match($type) {
            'dashboard_metrics' => $this->getQueryOptimizer()->obterMetricasDashboardOtimizado($params['tenant_id'], $params['periodo'] ?? 30),
            'produtos_relacionamentos' => $this->getQueryOptimizer()->obterProdutosComRelacionamentos($params['tenant_id'], $params['filtros'] ?? [], $params['limite'] ?? 20, $params['offset'] ?? 0),
            'vendas_completas' => $this->getQueryOptimizer()->obterVendasCompletas($params['tenant_id'], $params['filtros'] ?? [], $params['limite'] ?? 20, $params['offset'] ?? 0),
            'relatorio_financeiro' => $this->getQueryOptimizer()->obterRelatorioFinanceiroOtimizado($params['tenant_id'], $params['data_inicio'], $params['data_fim']),
            'top_produtos' => $this->getQueryOptimizer()->obterTopProdutosOtimizado($params['tenant_id'], $params['limite'] ?? 10, $params['dias'] ?? 30),
            default => []
        };
    }
    
    /**
     * Configurar hooks automáticos
     */
    private function setupAutoHooks(): void
    {
        // Hook de shutdown para relatório final
        register_shutdown_function(function () {
            if ($this->config['auto_report'] ?? true) {
                $this->generateShutdownReport();
            }
        });
        
        // Hook de garbage collection otimizado
        if (function_exists('register_tick_function') && ($this->config['auto_gc'] ?? true)) {
            register_tick_function([$this->memoryManager, 'verificacaoPeriodicaMemoria']);
        }
        
        // Hook de auto-otimização periódica
        if ($this->config['auto_optimize_interval'] ?? 0 > 0) {
            $this->setupPeriodicOptimization();
        }
    }
    
    /**
     * Relatório no shutdown
     */
    private function generateShutdownReport(): void
    {
        $performance = $this->analyzer->obterRelatorioPerformance();
        
        // Log apenas se performance baixa ou alertas críticos
        if ($performance['score_medio'] < 80 || !empty($performance['alertas_criticos'] ?? [])) {
            error_log('Performance Alert: Score=' . $performance['score_medio'] . ', Alerts=' . count($performance['alertas_criticos'] ?? []));
        }
        
        // Salvar métricas se configurado
        if ($this->config['save_metrics'] ?? false) {
            $this->savePerformanceMetrics($performance);
        }
    }
    
    /**
     * Configurar otimização periódica
     */
    private function setupPeriodicOptimization(): void
    {
        $interval = $this->config['auto_optimize_interval'];
        
        // Usar pcntl_alarm se disponível, senão ignore
        if (function_exists('pcntl_alarm')) {
            pcntl_signal(SIGALRM, function () {
                $this->autoOptimize();
                pcntl_alarm($this->config['auto_optimize_interval']);
            });
            pcntl_alarm($interval);
        }
    }
    
    /**
     * Salvar métricas de performance
     */
    private function savePerformanceMetrics(array $metrics): void
    {
        $file = $this->config['metrics_file'] ?? sys_get_temp_dir() . '/erp_performance_metrics.json';
        
        $existing = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
        $existing[] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'metrics' => $metrics
        ];
        
        // Manter apenas últimos 1000 registros
        if (count($existing) > 1000) {
            $existing = array_slice($existing, -1000);
        }
        
        file_put_contents($file, json_encode($existing, JSON_PRETTY_PRINT));
    }
    
    /**
     * Configuração padrão
     */
    private function getDefaultConfig(): array
    {
        return [
            'auto_report' => true,
            'auto_gc' => true,
            'auto_optimize_interval' => 0, // 0 = desabilitado
            'save_metrics' => false,
            'metrics_file' => null,
            'memory_limit_warning' => 80, // %
            'cache_warming' => true,
            'compression_enabled' => true,
            'lazy_loading_enabled' => true,
            'connection_pool_enabled' => true,
            'database' => [
                'host' => 'localhost',
                'port' => 3306,
                'username' => 'root',
                'password' => '',
                'max_connections' => 20,
                'min_connections' => 5
            ]
        ];
    }
    
    /**
     * Inicializar componentes individuais
     */
    private function initializeMemoryManager(): void
    {
        $this->memoryManager = new MemoryManager();
    }
    
    private function initializeConnectionPool(DatabaseManager $database, array $dbConfig): void
    {
        if ($this->config['connection_pool_enabled'] ?? true) {
            $this->connectionPool = new ConnectionPool($dbConfig);
        }
    }
    
    private function initializeCompressionManager(): void
    {
        if ($this->config['compression_enabled'] ?? true) {
            $this->compressionManager = new CompressionManager();
        }
    }
    
    private function initializeCacheOtimizado(CacheInterface $cache, DatabaseManager $database): void
    {
        $this->cacheOtimizado = new CacheOtimizado($cache, $database);
        
        // Cache warming se habilitado
        if ($this->config['cache_warming'] ?? true) {
            $this->cacheOtimizado->warmCache();
        }
    }
    
    private function initializeQueryOptimizer(DatabaseManager $database): void
    {
        $this->queryOptimizer = new QueryOptimizer($database);
    }
    
    private function initializeLazyLoader(DatabaseManager $database, CacheInterface $cache): void
    {
        if ($this->config['lazy_loading_enabled'] ?? true) {
            $this->lazyLoader = new LazyLoader($database, $cache);
        }
    }
    
    private function initializePerformanceAnalyzer(DatabaseManager $database, CacheInterface $cache): void
    {
        $this->analyzer = new PerformanceAnalyzer(
            $database,
            $cache,
            $this->queryOptimizer,
            $this->memoryManager,
            $this->cacheOtimizado,
            $this->compressionManager ?? new CompressionManager(),
            $this->connectionPool ?? $this->createDummyConnectionPool(),
            $this->lazyLoader ?? $this->createDummyLazyLoader($database, $cache)
        );
    }
    
    private function createDummyConnectionPool(): ConnectionPool
    {
        return new ConnectionPool($this->config['database']);
    }
    
    private function createDummyLazyLoader(DatabaseManager $database, CacheInterface $cache): LazyLoader
    {
        return new LazyLoader($database, $cache);
    }
    
    private function ensureInitialized(): void
    {
        if (! $this->initialized) {
            throw new \RuntimeException('PerformanceBootstrap not initialized. Call initialize() first.');
        }
    }
}
