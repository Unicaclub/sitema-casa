<?php

declare(strict_types=1);

namespace ERP\Core\Cache;

use Redis;
use Exception;

/**
 * Redis Cache Manager - Sistema de Cache High-Performance
 * 
 * Gerenciador Redis para cache distribuído e sessões
 * 
 * @package ERP\Core\Cache
 */
final class RedisManager
{
    private ?Redis $redis = null;
    private array $config;
    private bool $connected = false;
    private string $keyPrefix;
    
    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->keyPrefix = $this->config['key_prefix'];
        $this->connect();
    }
    
    /**
     * Conectar ao Redis
     */
    private function connect(): void
    {
        try {
            $this->redis = new Redis();
            
            // Conectar ao servidor Redis
            $connected = $this->redis->connect(
                $this->config['host'],
                $this->config['port'],
                $this->config['timeout']
            );
            
            if (! $connected) {
                throw new Exception('Failed to connect to Redis server');
            }
            
            // Autenticar se necessário
            if (! empty($this->config['password'])) {
                $this->redis->auth($this->config['password']);
            }
            
            // Selecionar database
            if ($this->config['database'] > 0) {
                $this->redis->select($this->config['database']);
            }
            
            // Configurar serialização
            $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_JSON);
            
            // Configurar prefix
            if (! empty($this->keyPrefix)) {
                $this->redis->setOption(Redis::OPT_PREFIX, $this->keyPrefix);
            }
            
            $this->connected = true;
            
        } catch (Exception $e) {
            $this->connected = false;
            error_log("Redis connection failed: " . $e->getMessage());
        }
    }
    
    /**
     * Verificar se está conectado
     */
    public function isConnected(): bool
    {
        if (! $this->redis || ! $this->connected) {
            return false;
        }
        
        try {
            $this->redis->ping();
            return true;
        } catch (Exception $e) {
            $this->connected = false;
            return false;
        }
    }
    
    /**
     * Definir valor no cache
     */
    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        if (! $this->isConnected()) {
            return false;
        }
        
        try {
            if ($ttl > 0) {
                return $this->redis->setex($key, $ttl, $value);
            } else {
                return $this->redis->set($key, $value);
            }
        } catch (Exception $e) {
            error_log("Redis set failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obter valor do cache
     */
    public function get(string $key): mixed
    {
        if (! $this->isConnected()) {
            return null;
        }
        
        try {
            $value = $this->redis->get($key);
            return $value === false ? null : $value;
        } catch (Exception $e) {
            error_log("Redis get failed: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Verificar se chave existe
     */
    public function exists(string $key): bool
    {
        if (! $this->isConnected()) {
            return false;
        }
        
        try {
            return $this->redis->exists($key) > 0;
        } catch (Exception $e) {
            error_log("Redis exists failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Deletar chave
     */
    public function delete(string $key): bool
    {
        if (! $this->isConnected()) {
            return false;
        }
        
        try {
            return $this->redis->del($key) > 0;
        } catch (Exception $e) {
            error_log("Redis delete failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Incrementar valor
     */
    public function increment(string $key, int $value = 1): int
    {
        if (! $this->isConnected()) {
            return 0;
        }
        
        try {
            return $this->redis->incrBy($key, $value);
        } catch (Exception $e) {
            error_log("Redis increment failed: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Decrementar valor
     */
    public function decrement(string $key, int $value = 1): int
    {
        if (! $this->isConnected()) {
            return 0;
        }
        
        try {
            return $this->redis->decrBy($key, $value);
        } catch (Exception $e) {
            error_log("Redis decrement failed: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Definir TTL para chave existente
     */
    public function expire(string $key, int $seconds): bool
    {
        if (! $this->isConnected()) {
            return false;
        }
        
        try {
            return $this->redis->expire($key, $seconds);
        } catch (Exception $e) {
            error_log("Redis expire failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obter TTL de uma chave
     */
    public function ttl(string $key): int
    {
        if (! $this->isConnected()) {
            return -1;
        }
        
        try {
            return $this->redis->ttl($key);
        } catch (Exception $e) {
            error_log("Redis ttl failed: " . $e->getMessage());
            return -1;
        }
    }
    
    /**
     * Buscar chaves por padrão
     */
    public function keys(string $pattern): array
    {
        if (! $this->isConnected()) {
            return [];
        }
        
        try {
            return $this->redis->keys($pattern);
        } catch (Exception $e) {
            error_log("Redis keys failed: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Flush database
     */
    public function flush(): bool
    {
        if (! $this->isConnected()) {
            return false;
        }
        
        try {
            return $this->redis->flushDB();
        } catch (Exception $e) {
            error_log("Redis flush failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Operações de Hash
     */
    public function hSet(string $key, string $field, mixed $value): bool
    {
        if (! $this->isConnected()) {
            return false;
        }
        
        try {
            return $this->redis->hSet($key, $field, $value) !== false;
        } catch (Exception $e) {
            error_log("Redis hSet failed: " . $e->getMessage());
            return false;
        }
    }
    
    public function hGet(string $key, string $field): mixed
    {
        if (! $this->isConnected()) {
            return null;
        }
        
        try {
            $value = $this->redis->hGet($key, $field);
            return $value === false ? null : $value;
        } catch (Exception $e) {
            error_log("Redis hGet failed: " . $e->getMessage());
            return null;
        }
    }
    
    public function hGetAll(string $key): array
    {
        if (! $this->isConnected()) {
            return [];
        }
        
        try {
            return $this->redis->hGetAll($key) ?: [];
        } catch (Exception $e) {
            error_log("Redis hGetAll failed: " . $e->getMessage());
            return [];
        }
    }
    
    public function hDel(string $key, string $field): bool
    {
        if (! $this->isConnected()) {
            return false;
        }
        
        try {
            return $this->redis->hDel($key, $field) > 0;
        } catch (Exception $e) {
            error_log("Redis hDel failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Operações de List
     */
    public function lPush(string $key, mixed ...$values): int
    {
        if (! $this->isConnected()) {
            return 0;
        }
        
        try {
            return $this->redis->lPush($key, ...$values);
        } catch (Exception $e) {
            error_log("Redis lPush failed: " . $e->getMessage());
            return 0;
        }
    }
    
    public function rPush(string $key, mixed ...$values): int
    {
        if (! $this->isConnected()) {
            return 0;
        }
        
        try {
            return $this->redis->rPush($key, ...$values);
        } catch (Exception $e) {
            error_log("Redis rPush failed: " . $e->getMessage());
            return 0;
        }
    }
    
    public function lPop(string $key): mixed
    {
        if (! $this->isConnected()) {
            return null;
        }
        
        try {
            $value = $this->redis->lPop($key);
            return $value === false ? null : $value;
        } catch (Exception $e) {
            error_log("Redis lPop failed: " . $e->getMessage());
            return null;
        }
    }
    
    public function rPop(string $key): mixed
    {
        if (! $this->isConnected()) {
            return null;
        }
        
        try {
            $value = $this->redis->rPop($key);
            return $value === false ? null : $value;
        } catch (Exception $e) {
            error_log("Redis rPop failed: " . $e->getMessage());
            return null;
        }
    }
    
    public function lRange(string $key, int $start, int $end): array
    {
        if (! $this->isConnected()) {
            return [];
        }
        
        try {
            return $this->redis->lRange($key, $start, $end) ?: [];
        } catch (Exception $e) {
            error_log("Redis lRange failed: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Operações de Set
     */
    public function sAdd(string $key, mixed ...$values): int
    {
        if (! $this->isConnected()) {
            return 0;
        }
        
        try {
            return $this->redis->sAdd($key, ...$values);
        } catch (Exception $e) {
            error_log("Redis sAdd failed: " . $e->getMessage());
            return 0;
        }
    }
    
    public function sMembers(string $key): array
    {
        if (! $this->isConnected()) {
            return [];
        }
        
        try {
            return $this->redis->sMembers($key) ?: [];
        } catch (Exception $e) {
            error_log("Redis sMembers failed: " . $e->getMessage());
            return [];
        }
    }
    
    public function sIsMember(string $key, mixed $value): bool
    {
        if (! $this->isConnected()) {
            return false;
        }
        
        try {
            return $this->redis->sIsMember($key, $value);
        } catch (Exception $e) {
            error_log("Redis sIsMember failed: " . $e->getMessage());
            return false;
        }
    }
    
    public function sRem(string $key, mixed ...$values): int
    {
        if (! $this->isConnected()) {
            return 0;
        }
        
        try {
            return $this->redis->sRem($key, ...$values);
        } catch (Exception $e) {
            error_log("Redis sRem failed: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Pipeline para operações em lote
     */
    public function pipeline(callable $callback): array
    {
        if (! $this->isConnected()) {
            return [];
        }
        
        try {
            $pipe = $this->redis->pipeline();
            $callback($pipe);
            return $pipe->exec() ?: [];
        } catch (Exception $e) {
            error_log("Redis pipeline failed: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Multi/Exec para transações
     */
    public function transaction(callable $callback): array
    {
        if (! $this->isConnected()) {
            return [];
        }
        
        try {
            $this->redis->multi();
            $callback($this->redis);
            return $this->redis->exec() ?: [];
        } catch (Exception $e) {
            error_log("Redis transaction failed: " . $e->getMessage());
            $this->redis->discard();
            return [];
        }
    }
    
    /**
     * Informações do servidor Redis
     */
    public function info(): array
    {
        if (! $this->isConnected()) {
            return [];
        }
        
        try {
            return $this->redis->info() ?: [];
        } catch (Exception $e) {
            error_log("Redis info failed: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Estatísticas de uso
     */
    public function getStats(): array
    {
        if (! $this->isConnected()) {
            return [
                'connected' => false,
                'status' => 'disconnected'
            ];
        }
        
        $info = $this->info();
        
        return [
            'connected' => true,
            'status' => 'connected',
            'version' => $info['redis_version'] ?? 'unknown',
            'used_memory' => $info['used_memory_human'] ?? 'unknown',
            'connected_clients' => $info['connected_clients'] ?? 0,
            'total_commands_processed' => $info['total_commands_processed'] ?? 0,
            'keyspace_hits' => $info['keyspace_hits'] ?? 0,
            'keyspace_misses' => $info['keyspace_misses'] ?? 0,
            'hit_rate' => $this->calculateHitRate($info)
        ];
    }
    
    /**
     * Cache com callback (cache-aside pattern)
     */
    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $value = $this->get($key);
        
        if ($value !== null) {
            return $value;
        }
        
        $value = $callback();
        
        if ($value !== null) {
            $this->set($key, $value, $ttl);
        }
        
        return $value;
    }
    
    /**
     * Rate limiting
     */
    public function rateLimit(string $key, int $maxRequests, int $windowSeconds): array
    {
        if (! $this->isConnected()) {
            return [
                'allowed' => true,
                'remaining' => $maxRequests,
                'reset_at' => time() + $windowSeconds
            ];
        }
        
        try {
            $current = $this->increment($key);
            
            if ($current === 1) {
                $this->expire($key, $windowSeconds);
            }
            
            $ttl = $this->ttl($key);
            $resetAt = time() + max(0, $ttl);
            
            return [
                'allowed' => $current <= $maxRequests,
                'remaining' => max(0, $maxRequests - $current),
                'reset_at' => $resetAt,
                'current' => $current,
                'limit' => $maxRequests
            ];
            
        } catch (Exception $e) {
            error_log("Redis rate limit failed: " . $e->getMessage());
            return [
                'allowed' => true,
                'remaining' => $maxRequests,
                'reset_at' => time() + $windowSeconds
            ];
        }
    }
    
    /**
     * Fechar conexão
     */
    public function close(): void
    {
        if ($this->redis && $this->connected) {
            try {
                $this->redis->close();
            } catch (Exception $e) {
                error_log("Redis close failed: " . $e->getMessage());
            }
            $this->connected = false;
        }
    }
    
    /**
     * Destructor
     */
    public function __destruct()
    {
        $this->close();
    }
    
    /**
     * Configuração padrão
     */
    private function getDefaultConfig(): array
    {
        return [
            'host' => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['REDIS_PORT'] ?? 6379),
            'password' => $_ENV['REDIS_PASSWORD'] ?? '',
            'database' => (int) ($_ENV['REDIS_DB'] ?? 0),
            'timeout' => 5.0,
            'key_prefix' => $_ENV['REDIS_PREFIX'] ?? 'erp:'
        ];
    }
    
    /**
     * Calcular hit rate
     */
    private function calculateHitRate(array $info): float
    {
        $hits = (int) ($info['keyspace_hits'] ?? 0);
        $misses = (int) ($info['keyspace_misses'] ?? 0);
        $total = $hits + $misses;
        
        return $total > 0 ? round(($hits / $total) * 100, 2) : 0.0;
    }
}
