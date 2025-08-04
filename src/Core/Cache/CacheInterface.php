<?php

declare(strict_types=1);

namespace ERP\Core\Cache;

/**
 * Interface do Sistema de Cache
 * 
 * Define contratos para implementações de cache
 * 
 * @package ERP\Core\Cache
 */
interface CacheInterface
{
    /**
     * Armazenar item no cache
     */
    public function put(string $chave, mixed $valor, int $ttl = null): bool;
    
    /**
     * Obter item do cache
     */
    public function get(string $chave, mixed $padrao = null): mixed;
    
    /**
     * Verificar se item existe no cache
     */
    public function has(string $chave): bool;
    
    /**
     * Remover item do cache
     */
    public function forget(string $chave): bool;
    
    /**
     * Limpar todo o cache
     */
    public function flush(): bool;
    
    /**
     * Incrementar valor numérico
     */
    public function increment(string $chave, int $valor = 1): int;
    
    /**
     * Decrementar valor numérico
     */
    public function decrement(string $chave, int $valor = 1): int;
    
    /**
     * Obter múltiplos itens do cache
     */
    public function many(array $chaves): array;
    
    /**
     * Armazenar múltiplos itens no cache
     */
    public function putMany(array $valores, int $ttl = null): bool;
    
    /**
     * Obter ou definir item no cache
     */
    public function remember(string $chave, callable $callback, int $ttl = null): mixed;
}