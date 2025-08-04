<?php

declare(strict_types=1);

namespace ERP\Core\Performance;

use PDO;
use PDOException;
use SplQueue;

/**
 * Pool de Conexões de Alto Performance
 * 
 * Gerencia pool de conexões persistentes para máxima performance
 * 
 * @package ERP\Core\Performance
 */
final class ConnectionPool
{
    private SplQueue $availableConnections;
    private array $busyConnections = [];
    private array $config;
    private int $currentSize = 0;
    private int $maxSize;
    private int $minSize;
    private array $stats = [
        'created' => 0,
        'reused' => 0,
        'errors' => 0,
        'timeouts' => 0
    ];
    
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->maxSize = $config['max_connections'] ?? 20;
        $this->minSize = $config['min_connections'] ?? 5;
        $this->availableConnections = new SplQueue();
        
        // Criar conexões mínimas iniciais
        $this->initializeMinConnections();
    }
    
    /**
     * Obter conexão do pool
     */
    public function getConnection(int $timeout = 5): PDO
    {
        $startTime = microtime(true);
        
        // Tentar obter conexão disponível
        while (microtime(true) - $startTime < $timeout) {
            if (!$this->availableConnections->isEmpty()) {
                $connection = $this->availableConnections->dequeue();
                
                // Verificar se conexão ainda está viva
                if ($this->isConnectionAlive($connection)) {
                    $this->busyConnections[spl_object_id($connection)] = $connection;
                    $this->stats['reused']++;
                    return $connection;
                } else {
                    $this->currentSize--;
                }
            }
            
            // Criar nova conexão se possível
            if ($this->currentSize < $this->maxSize) {
                $connection = $this->createConnection();
                if ($connection) {
                    $this->busyConnections[spl_object_id($connection)] = $connection;
                    $this->currentSize++;
                    $this->stats['created']++;
                    return $connection;
                }
            }
            
            // Aguardar um pouco antes de tentar novamente
            usleep(10000); // 10ms
        }
        
        $this->stats['timeouts']++;
        throw new \RuntimeException('Timeout ao obter conexão do pool');
    }
    
    /**
     * Retornar conexão para o pool
     */
    public function releaseConnection(PDO $connection): void
    {
        $connectionId = spl_object_id($connection);
        
        if (isset($this->busyConnections[$connectionId])) {
            unset($this->busyConnections[$connectionId]);
            
            // Verificar se conexão ainda está saudável
            if ($this->isConnectionHealthy($connection)) {
                $this->availableConnections->enqueue($connection);
            } else {
                $this->currentSize--;
            }
        }
    }
    
    /**
     * Executar query com connection do pool
     */
    public function execute(string $query, array $params = [], int $timeout = 5): mixed
    {
        $connection = $this->getConnection($timeout);
        
        try {
            $stmt = $connection->prepare($query);
            $result = $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->stats['errors']++;
            throw $e;
        } finally {
            $this->releaseConnection($connection);
        }
    }
    
    /**
     * Executar transação com connection dedicada
     */
    public function transaction(callable $callback, int $timeout = 10): mixed
    {
        $connection = $this->getConnection($timeout);
        
        try {
            $connection->beginTransaction();
            $result = $callback($connection);
            $connection->commit();
            
            return $result;
        } catch (\Exception $e) {
            $connection->rollback();
            $this->stats['errors']++;
            throw $e;
        } finally {
            $this->releaseConnection($connection);
        }
    }
    
    /**
     * Preparar conexões para alta carga (warming)
     */
    public function warmUp(): void
    {
        $targetSize = min($this->maxSize, $this->minSize * 2);
        
        while ($this->currentSize < $targetSize) {
            $connection = $this->createConnection();
            if ($connection) {
                $this->availableConnections->enqueue($connection);
                $this->currentSize++;
            } else {
                break;
            }
        }
    }
    
    /**
     * Limpeza de conexões inativas (garbage collection)
     */
    public function cleanup(): void
    {
        $cleanConnections = new SplQueue();
        
        // Verificar conexões disponíveis
        while (!$this->availableConnections->isEmpty()) {
            $connection = $this->availableConnections->dequeue();
            
            if ($this->isConnectionHealthy($connection)) {
                $cleanConnections->enqueue($connection);
            } else {
                $this->currentSize--;
            }
        }
        
        $this->availableConnections = $cleanConnections;
        
        // Garantir conexões mínimas
        while ($this->currentSize < $this->minSize) {
            $connection = $this->createConnection();
            if ($connection) {
                $this->availableConnections->enqueue($connection);
                $this->currentSize++;
            } else {
                break;
            }
        }
    }
    
    /**
     * Obter estatísticas do pool
     */
    public function getStats(): array
    {
        return [
            'current_size' => $this->currentSize,
            'available' => $this->availableConnections->count(),
            'busy' => count($this->busyConnections),
            'max_size' => $this->maxSize,
            'min_size' => $this->minSize,
            'stats' => $this->stats,
            'efficiency' => $this->calculateEfficiency(),
            'health' => $this->checkPoolHealth()
        ];
    }
    
    /**
     * Monitoramento de performance do pool
     */
    public function getPerformanceMetrics(): array
    {
        $totalOperations = array_sum($this->stats);
        
        return [
            'utilization_rate' => $this->currentSize > 0 ? count($this->busyConnections) / $this->currentSize : 0,
            'reuse_rate' => $totalOperations > 0 ? $this->stats['reused'] / $totalOperations : 0,
            'error_rate' => $totalOperations > 0 ? $this->stats['errors'] / $totalOperations : 0,
            'timeout_rate' => $totalOperations > 0 ? $this->stats['timeouts'] / $totalOperations : 0,
            'pool_efficiency' => $this->calculateEfficiency(),
            'recommended_size' => $this->calculateRecommendedSize()
        ];
    }
    
    /**
     * Auto-scaling baseado na demanda
     */
    public function autoScale(): void
    {
        $metrics = $this->getPerformanceMetrics();
        
        // Scale up se utilização alta
        if ($metrics['utilization_rate'] > 0.8 && $this->currentSize < $this->maxSize) {
            $newConnections = min(2, $this->maxSize - $this->currentSize);
            for ($i = 0; $i < $newConnections; $i++) {
                $connection = $this->createConnection();
                if ($connection) {
                    $this->availableConnections->enqueue($connection);
                    $this->currentSize++;
                }
            }
        }
        
        // Scale down se utilização baixa
        if ($metrics['utilization_rate'] < 0.3 && $this->currentSize > $this->minSize) {
            $connectionsToRemove = min(1, $this->currentSize - $this->minSize);
            for ($i = 0; $i < $connectionsToRemove && !$this->availableConnections->isEmpty(); $i++) {
                $this->availableConnections->dequeue();
                $this->currentSize--;
            }
        }
    }
    
    /**
     * Destructor - fechar todas as conexões
     */
    public function __destruct()
    {
        $this->closeAllConnections();
    }
    
    /**
     * Métodos privados
     */
    
    private function initializeMinConnections(): void
    {
        for ($i = 0; $i < $this->minSize; $i++) {
            $connection = $this->createConnection();
            if ($connection) {
                $this->availableConnections->enqueue($connection);
                $this->currentSize++;
            }
        }
    }
    
    private function createConnection(): ?PDO
    {
        try {
            $dsn = "mysql:host={$this->config['host']};port={$this->config['port']};dbname={$this->config['database']};charset=utf8mb4";
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => true,
                PDO::ATTR_TIMEOUT => 5,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                PDO::MYSQL_ATTR_LOCAL_INFILE => false,
            ];
            
            $connection = new PDO($dsn, $this->config['username'], $this->config['password'], $options);
            
            // Configurações de performance
            $connection->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
            $connection->exec("SET SESSION time_zone = '+00:00'");
            $connection->exec("SET SESSION autocommit = 1");
            
            return $connection;
        } catch (PDOException $e) {
            error_log("Erro ao criar conexão: " . $e->getMessage());
            return null;
        }
    }
    
    private function isConnectionAlive(PDO $connection): bool
    {
        try {
            $connection->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    private function isConnectionHealthy(PDO $connection): bool
    {
        try {
            // Verificar se conexão responde rapidamente
            $start = microtime(true);
            $connection->query('SELECT 1');
            $responseTime = microtime(true) - $start;
            
            // Conexão saudável se responde em < 100ms
            return $responseTime < 0.1;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    private function calculateEfficiency(): float
    {
        $total = array_sum($this->stats);
        if ($total === 0) return 1.0;
        
        $successRate = ($this->stats['created'] + $this->stats['reused']) / $total;
        $reuseBonus = $this->stats['reused'] > 0 ? 0.2 : 0;
        
        return min(1.0, $successRate + $reuseBonus);
    }
    
    private function calculateRecommendedSize(): int
    {
        $avgBusy = count($this->busyConnections);
        $peakMultiplier = 1.5; // Buffer para picos
        
        return max($this->minSize, min($this->maxSize, (int)($avgBusy * $peakMultiplier)));
    }
    
    private function checkPoolHealth(): string
    {
        $metrics = $this->getPerformanceMetrics();
        
        if ($metrics['error_rate'] > 0.1) return 'unhealthy';
        if ($metrics['timeout_rate'] > 0.05) return 'degraded';
        if ($metrics['efficiency'] < 0.8) return 'suboptimal';
        
        return 'healthy';
    }
    
    private function closeAllConnections(): void
    {
        // Fechar conexões disponíveis
        while (!$this->availableConnections->isEmpty()) {
            $this->availableConnections->dequeue();
        }
        
        // Fechar conexões ocupadas
        $this->busyConnections = [];
        $this->currentSize = 0;
    }
}