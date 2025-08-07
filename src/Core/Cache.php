<?php

namespace ERP\Core;

/**
 * Sistema de Cache com Redis
 * Suporta tags, expiração e cache distribuído
 */
class Cache 
{
    private $redis;
    private $config;
    private $prefix;
    
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->prefix = $config['prefix'] ?? 'erp:';
        $this->connect();
    }
    
    /**
     * Conecta ao Redis
     */
    private function connect(): void
    {
        $this->redis = new \Redis();
        
        $connected = $this->redis->connect(
            $this->config['host'],
            $this->config['port'],
            $this->config['timeout'] ?? 5
        );
        
        if (! $connected) {
            throw new \Exception('Não foi possível conectar ao Redis');
        }
        
        if (! empty($this->config['password'])) {
            $this->redis->auth($this->config['password']);
        }
        
        if (isset($this->config['database'])) {
            $this->redis->select($this->config['database']);
        }
    }
    
    /**
     * Armazena valor no cache
     */
    public function set(string $key, $value, int $ttl = 3600): bool
    {
        $key = $this->prefix . $key;
        $serialized = serialize($value);
        
        if ($ttl > 0) {
            return $this->redis->setex($key, $ttl, $serialized);
        } else {
            return $this->redis->set($key, $serialized);
        }
    }
    
    /**
     * Obtém valor do cache
     */
    public function get(string $key, $default = null)
    {
        $key = $this->prefix . $key;
        $value = $this->redis->get($key);
        
        if ($value === false) {
            return $default;
        }
        
        return unserialize($value);
    }
    
    /**
     * Verifica se chave existe
     */
    public function has(string $key): bool
    {
        $key = $this->prefix . $key;
        return $this->redis->exists($key) > 0;
    }
    
    /**
     * Remove valor do cache
     */
    public function delete(string $key): bool
    {
        $key = $this->prefix . $key;
        return $this->redis->del($key) > 0;
    }
    
    /**
     * Remove múltiplas chaves
     */
    public function deleteMultiple(array $keys): bool
    {
        $prefixedKeys = array_map(fn($key) => $this->prefix . $key, $keys);
        return $this->redis->del($prefixedKeys) > 0;
    }
    
    /**
     * Limpa todo o cache
     */
    public function clear(): bool
    {
        return $this->redis->flushDB();
    }
    
    /**
     * Incrementa valor numérico
     */
    public function increment(string $key, int $value = 1): int
    {
        $key = $this->prefix . $key;
        return $this->redis->incrBy($key, $value);
    }
    
    /**
     * Decrementa valor numérico
     */
    public function decrement(string $key, int $value = 1): int
    {
        $key = $this->prefix . $key;
        return $this->redis->decrBy($key, $value);
    }
    
    /**
     * Cache remember - obtém ou define valor
     */
    public function remember(string $key, \Closure $callback, int $ttl = 3600)
    {
        $value = $this->get($key);
        
        if ($value !== null) {
            return $value;
        }
        
        $value = $callback();
        $this->set($key, $value, $ttl);
        
        return $value;
    }
    
    /**
     * Cache com tags
     */
    public function tags(array $tags): TaggedCache
    {
        return new TaggedCache($this->redis, $this->prefix, $tags);
    }
    
    /**
     * Lock distribuído
     */
    public function lock(string $key, int $ttl = 10): bool
    {
        $lockKey = $this->prefix . 'lock:' . $key;
        return $this->redis->set($lockKey, 1, ['nx', 'ex' => $ttl]);
    }
    
    /**
     * Libera lock
     */
    public function unlock(string $key): bool
    {
        $lockKey = $this->prefix . 'lock:' . $key;
        return $this->redis->del($lockKey) > 0;
    }
    
    /**
     * Busca chaves por padrão
     */
    public function keys(string $pattern): array
    {
        $pattern = $this->prefix . $pattern;
        $keys = $this->redis->keys($pattern);
        
        // Remove prefixo das chaves retornadas
        return array_map(function($key) {
            return substr($key, strlen($this->prefix));
        }, $keys);
    }
    
    /**
     * Obtém informações do Redis
     */
    public function info(): array
    {
        return $this->redis->info();
    }
    
    /**
     * Obtém estatísticas de uso
     */
    public function stats(): array
    {
        $info = $this->redis->info();
        
        return [
            'used_memory' => $info['used_memory_human'] ?? '0B',
            'connected_clients' => $info['connected_clients'] ?? 0,
            'total_commands_processed' => $info['total_commands_processed'] ?? 0,
            'keyspace_hits' => $info['keyspace_hits'] ?? 0,
            'keyspace_misses' => $info['keyspace_misses'] ?? 0,
            'hit_rate' => $this->calculateHitRate($info)
        ];
    }
    
    /**
     * Calcula taxa de acerto do cache
     */
    private function calculateHitRate(array $info): float
    {
        $hits = $info['keyspace_hits'] ?? 0;
        $misses = $info['keyspace_misses'] ?? 0;
        $total = $hits + $misses;
        
        return $total > 0 ? round(($hits / $total) * 100, 2) : 0;
    }
}

/**
 * Cache com Tags
 */
class TaggedCache 
{
    private $redis;
    private $prefix;
    private $tags;
    
    public function __construct(\Redis $redis, string $prefix, array $tags)
    {
        $this->redis = $redis;
        $this->prefix = $prefix;
        $this->tags = $tags;
    }
    
    /**
     * Armazena valor com tags
     */
    public function set(string $key, $value, int $ttl = 3600): bool
    {
        $fullKey = $this->prefix . $key;
        $serialized = serialize($value);
        
        // Armazena o valor
        $result = $ttl > 0 
            ? $this->redis->setex($fullKey, $ttl, $serialized)
            : $this->redis->set($fullKey, $serialized);
        
        // Associa chave às tags
        foreach ($this->tags as $tag) {
            $tagKey = $this->prefix . 'tag:' . $tag;
            $this->redis->sAdd($tagKey, $key);
            
            if ($ttl > 0) {
                $this->redis->expire($tagKey, $ttl + 3600); // Tag expira 1h depois
            }
        }
        
        return $result;
    }
    
    /**
     * Obtém valor por chave
     */
    public function get(string $key, $default = null)
    {
        $fullKey = $this->prefix . $key;
        $value = $this->redis->get($fullKey);
        
        return $value !== false ? unserialize($value) : $default;
    }
    
    /**
     * Remove cache por tag
     */
    public function flush(): bool
    {
        foreach ($this->tags as $tag) {
            $tagKey = $this->prefix . 'tag:' . $tag;
            $keys = $this->redis->sMembers($tagKey);
            
            if (! empty($keys)) {
                // Remove todas as chaves da tag
                $fullKeys = array_map(fn($key) => $this->prefix . $key, $keys);
                $this->redis->del($fullKeys);
                
                // Remove a tag
                $this->redis->del($tagKey);
            }
        }
        
        return true;
    }
    
    /**
     * Lista chaves de uma tag
     */
    public function getKeys(string $tag): array
    {
        $tagKey = $this->prefix . 'tag:' . $tag;
        return $this->redis->sMembers($tagKey);
    }
}

/**
 * Middleware de Cache
 */
class CacheMiddleware 
{
    public function handle(Request $request, \Closure $next): Response
    {
        // Só faz cache de GETs
        if ($request->method() !== 'GET') {
            return $next($request);
        }
        
        $cache = App::getInstance()->get('cache');
        $cacheKey = $this->generateCacheKey($request);
        
        // Verifica se existe cache
        $cached = $cache->get($cacheKey);
        if ($cached !== null) {
            $response = new Response($cached['data'], $cached['status']);
            $response->header('X-Cache', 'HIT');
            return $response;
        }
        
        // Executa request
        $response = $next($request);
        
        // Cacheia response se for 200
        if ($response->getStatusCode() === 200) {
            $cache->set($cacheKey, [
                'data' => $response->getData(),
                'status' => $response->getStatusCode()
            ], 300); // 5 minutos
            
            $response->header('X-Cache', 'MISS');
        }
        
        return $response;
    }
    
    private function generateCacheKey(Request $request): string
    {
        $auth = App::getInstance()->get('auth');
        $companyId = $auth->companyId() ?? 'guest';
        $userId = $auth->id() ?? 'guest';
        
        return 'request:' . $companyId . ':' . $userId . ':' . md5($request->path() . serialize($request->query()));
    }
}
