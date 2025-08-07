<?php

declare(strict_types=1);

namespace ERP\Core\Cache;

/**
 * Cache Manager - Sistema de Cache Multi-Layer
 * 
 * Gerenciador de cache com suporte a múltiplas camadas
 * 
 * @package ERP\Core\Cache
 */
final class CacheManager
{
    private RedisManager $redis;
    private array $layers = [];
    private array $stats = [];
    
    public function __construct(RedisManager $redis)
    {
        $this->redis = $redis;
        $this->initializeLayers();
    }
    
    /**
     * Inicializar camadas de cache
     */
    private function initializeLayers(): void
    {
        $this->layers = [
            'security' => [
                'prefix' => 'sec:',
                'default_ttl' => 300, // 5 minutes
                'max_ttl' => 3600 // 1 hour
            ],
            'user_sessions' => [
                'prefix' => 'sess:',
                'default_ttl' => 1800, // 30 minutes
                'max_ttl' => 86400 // 24 hours
            ],
            'threat_intel' => [
                'prefix' => 'ti:',
                'default_ttl' => 1800, // 30 minutes
                'max_ttl' => 86400 // 24 hours
            ],
            'performance' => [
                'prefix' => 'perf:',
                'default_ttl' => 60, // 1 minute
                'max_ttl' => 300 // 5 minutes
            ],
            'api_responses' => [
                'prefix' => 'api:',
                'default_ttl' => 300, // 5 minutes
                'max_ttl' => 1800 // 30 minutes
            ],
            'database_queries' => [
                'prefix' => 'db:',
                'default_ttl' => 600, // 10 minutes
                'max_ttl' => 3600 // 1 hour
            ]
        ];
    }
    
    /**
     * Cache de eventos de segurança
     */
    public function cacheSecurityEvent(string $eventId, array $eventData, int $ttl = 0): bool
    {
        $key = $this->buildKey('security', "event:{$eventId}");
        $ttl = $ttl ?: $this->layers['security']['default_ttl'];
        
        return $this->redis->set($key, $eventData, $ttl);
    }
    
    public function getSecurityEvent(string $eventId): ?array
    {
        $key = $this->buildKey('security', "event:{$eventId}");
        return $this->redis->get($key);
    }
    
    /**
     * Cache de ameaças detectadas
     */
    public function cacheThreatData(string $threatId, array $threatData, int $ttl = 0): bool
    {
        $key = $this->buildKey('threat_intel', "threat:{$threatId}");
        $ttl = $ttl ?: $this->layers['threat_intel']['default_ttl'];
        
        return $this->redis->set($key, $threatData, $ttl);
    }
    
    public function getThreatData(string $threatId): ?array
    {
        $key = $this->buildKey('threat_intel', "threat:{$threatId}");
        return $this->redis->get($key);
    }
    
    /**
     * Cache de IoCs (Indicators of Compromise)
     */
    public function cacheIOC(string $iocValue, string $iocType, array $iocData, int $ttl = 0): bool
    {
        $key = $this->buildKey('threat_intel', "ioc:{$iocType}:{$iocValue}");
        $ttl = $ttl ?: $this->layers['threat_intel']['default_ttl'];
        
        return $this->redis->set($key, $iocData, $ttl);
    }
    
    public function getIOC(string $iocValue, string $iocType): ?array
    {
        $key = $this->buildKey('threat_intel', "ioc:{$iocType}:{$iocValue}");
        return $this->redis->get($key);
    }
    
    /**
     * Cache de sessões de usuário
     */
    public function cacheUserSession(string $sessionId, array $sessionData, int $ttl = 0): bool
    {
        $key = $this->buildKey('user_sessions', $sessionId);
        $ttl = $ttl ?: $this->layers['user_sessions']['default_ttl'];
        
        return $this->redis->set($key, $sessionData, $ttl);
    }
    
    public function getUserSession(string $sessionId): ?array
    {
        $key = $this->buildKey('user_sessions', $sessionId);
        return $this->redis->get($key);
    }
    
    public function invalidateUserSession(string $sessionId): bool
    {
        $key = $this->buildKey('user_sessions', $sessionId);
        return $this->redis->delete($key);
    }
    
    /**
     * Cache de métricas de performance
     */
    public function cachePerformanceMetric(string $metricName, mixed $value, int $ttl = 0): bool
    {
        $key = $this->buildKey('performance', $metricName);
        $ttl = $ttl ?: $this->layers['performance']['default_ttl'];
        
        return $this->redis->set($key, $value, $ttl);
    }
    
    public function getPerformanceMetric(string $metricName): mixed
    {
        $key = $this->buildKey('performance', $metricName);
        return $this->redis->get($key);
    }
    
    /**
     * Cache de respostas de API
     */
    public function cacheAPIResponse(string $endpoint, array $params, mixed $response, int $ttl = 0): bool
    {
        $cacheKey = $this->generateAPIKey($endpoint, $params);
        $key = $this->buildKey('api_responses', $cacheKey);
        $ttl = $ttl ?: $this->layers['api_responses']['default_ttl'];
        
        return $this->redis->set($key, $response, $ttl);
    }
    
    public function getAPIResponse(string $endpoint, array $params): mixed
    {
        $cacheKey = $this->generateAPIKey($endpoint, $params);
        $key = $this->buildKey('api_responses', $cacheKey);
        return $this->redis->get($key);
    }
    
    /**
     * Cache de queries de banco
     */
    public function cacheQuery(string $sql, array $params, mixed $result, int $ttl = 0): bool
    {
        $cacheKey = $this->generateQueryKey($sql, $params);
        $key = $this->buildKey('database_queries', $cacheKey);
        $ttl = $ttl ?: $this->layers['database_queries']['default_ttl'];
        
        return $this->redis->set($key, $result, $ttl);
    }
    
    public function getQueryResult(string $sql, array $params): mixed
    {
        $cacheKey = $this->generateQueryKey($sql, $params);
        $key = $this->buildKey('database_queries', $cacheKey);
        return $this->redis->get($key);
    }
    
    /**
     * Rate limiting por usuário
     */
    public function checkUserRateLimit(int $userId, string $action, int $maxRequests = 100, int $windowSeconds = 3600): array
    {
        $key = $this->buildKey('security', "rate_limit:user:{$userId}:{$action}");
        return $this->redis->rateLimit($key, $maxRequests, $windowSeconds);
    }
    
    /**
     * Rate limiting por IP
     */
    public function checkIPRateLimit(string $ip, string $action, int $maxRequests = 60, int $windowSeconds = 60): array
    {
        $key = $this->buildKey('security', "rate_limit:ip:{$ip}:{$action}");
        return $this->redis->rateLimit($key, $maxRequests, $windowSeconds);
    }
    
    /**
     * Blacklist de tokens JWT
     */
    public function blacklistToken(string $jti, int $ttl): bool
    {
        $key = $this->buildKey('security', "blacklist:jwt:{$jti}");
        return $this->redis->set($key, true, $ttl);
    }
    
    public function isTokenBlacklisted(string $jti): bool
    {
        $key = $this->buildKey('security', "blacklist:jwt:{$jti}");
        return $this->redis->exists($key);
    }
    
    /**
     * Cache de configurações do sistema
     */
    public function cacheSystemConfig(string $configKey, mixed $value, int $ttl = 3600): bool
    {
        $key = $this->buildKey('api_responses', "config:{$configKey}");
        return $this->redis->set($key, $value, $ttl);
    }
    
    public function getSystemConfig(string $configKey): mixed
    {
        $key = $this->buildKey('api_responses', "config:{$configKey}");
        return $this->redis->get($key);
    }
    
    /**
     * Contadores de estatísticas
     */
    public function incrementCounter(string $counterName, int $value = 1): int
    {
        $key = $this->buildKey('performance', "counter:{$counterName}");
        return $this->redis->increment($key, $value);
    }
    
    public function getCounter(string $counterName): int
    {
        $key = $this->buildKey('performance', "counter:{$counterName}");
        return (int) $this->redis->get($key);
    }
    
    /**
     * Cache com tags para invalidação em grupo
     */
    public function cacheWithTags(string $cacheKey, mixed $value, array $tags, int $ttl = 0): bool
    {
        // Cache o valor
        $this->redis->set($cacheKey, $value, $ttl);
        
        // Associar às tags
        foreach ($tags as $tag) {
            $tagKey = $this->buildKey('api_responses', "tag:{$tag}");
            $this->redis->sAdd($tagKey, $cacheKey);
        }
        
        return true;
    }
    
    public function invalidateByTag(string $tag): int
    {
        $tagKey = $this->buildKey('api_responses', "tag:{$tag}");
        $keys = $this->redis->sMembers($tagKey);
        
        $deleted = 0;
        foreach ($keys as $key) {
            if ($this->redis->delete($key)) {
                $deleted++;
            }
        }
        
        // Limpar a tag
        $this->redis->delete($tagKey);
        
        return $deleted;
    }
    
    /**
     * Limpeza de cache por padrão
     */
    public function clearByPattern(string $pattern): int
    {
        $keys = $this->redis->keys($pattern);
        $deleted = 0;
        
        foreach ($keys as $key) {
            if ($this->redis->delete($key)) {
                $deleted++;
            }
        }
        
        return $deleted;
    }
    
    /**
     * Limpar cache por camada
     */
    public function clearLayer(string $layer): int
    {
        if (! isset($this->layers[$layer])) {
            return 0;
        }
        
        $pattern = $this->layers[$layer]['prefix'] . '*';
        return $this->clearByPattern($pattern);
    }
    
    /**
     * Estatísticas de cache
     */
    public function getCacheStats(): array
    {
        $redisStats = $this->redis->getStats();
        
        $layerStats = [];
        foreach ($this->layers as $layerName => $layerConfig) {
            $pattern = $layerConfig['prefix'] . '*';
            $keys = $this->redis->keys($pattern);
            $layerStats[$layerName] = [
                'key_count' => count($keys),
                'prefix' => $layerConfig['prefix'],
                'default_ttl' => $layerConfig['default_ttl']
            ];
        }
        
        return [
            'redis' => $redisStats,
            'layers' => $layerStats,
            'total_operations' => $this->stats
        ];
    }
    
    /**
     * Warm up cache com dados críticos
     */
    public function warmUp(): array
    {
        $warmedUp = [];
        
        // Warm up configurações críticas
        $criticalConfigs = ['system_status', 'security_settings', 'api_limits'];
        foreach ($criticalConfigs as $config) {
            // Simular carregamento de configuração
            $this->cacheSystemConfig($config, $this->loadConfigFromDB($config));
            $warmedUp[] = $config;
        }
        
        return [
            'warmed_up' => $warmedUp,
            'count' => count($warmedUp),
            'timestamp' => time()
        ];
    }
    
    /**
     * Métodos auxiliares privados
     */
    
    private function buildKey(string $layer, string $key): string
    {
        $prefix = $this->layers[$layer]['prefix'] ?? '';
        return $prefix . $key;
    }
    
    private function generateAPIKey(string $endpoint, array $params): string
    {
        ksort($params);
        return md5($endpoint . serialize($params));
    }
    
    private function generateQueryKey(string $sql, array $params): string
    {
        return md5($sql . serialize($params));
    }
    
    private function loadConfigFromDB(string $configKey): mixed
    {
        // Simular carregamento do banco
        return match ($configKey) {
            'system_status' => ['status' => 'operational', 'version' => '2.0.0'],
            'security_settings' => ['max_login_attempts' => 5, 'session_timeout' => 1800],
            'api_limits' => ['rate_limit' => 1000, 'burst_limit' => 100],
            default => null
        };
    }
}
