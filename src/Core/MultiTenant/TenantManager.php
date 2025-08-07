<?php

namespace Core\MultiTenant;

use Core\Database\Database;
use Core\Auth\Auth;
use Core\Cache\CacheManager;
use Core\Logger;
use InvalidArgumentException;
use RuntimeException;

/**
 * TenantManager - Gerenciador central de multi-tenancy
 * 
 * Responsável por:
 * - Gerenciar contexto do tenant atual
 * - Validar acesso a dados por tenant
 * - Garantir isolamento de dados
 * - Integrar com cache e logs
 */
class TenantManager
{
    private Database $database;
    private CacheManager $cache;
    private Logger $logger;
    private ?int $currentTenantId = null;
    private ?array $currentTenant = null;
    
    public function __construct(Database $database, CacheManager $cache, Logger $logger)
    {
        $this->database = $database;
        $this->cache = $cache;
        $this->logger = $logger;
    }
    
    /**
     * Define o tenant atual baseado no usuário autenticado
     */
    public function setCurrentTenant(?int $tenantId): void
    {
        if ($tenantId !== null && !$this->tenantExists($tenantId)) {
            throw new InvalidArgumentException("Tenant ID {$tenantId} não existe");
        }
        
        $this->currentTenantId = $tenantId;
        $this->currentTenant = null;
        
        if ($tenantId) {
            $this->logger->info('Tenant context set', ['tenant_id' => $tenantId]);
        }
    }
    
    /**
     * Obtém o ID do tenant atual
     */
    public function getCurrentTenantId(): ?int
    {
        return $this->currentTenantId;
    }
    
    /**
     * Obtém dados completos do tenant atual
     */
    public function getCurrentTenant(): ?array
    {
        if (! $this->currentTenantId) {
            return null;
        }
        
        if ($this->currentTenant === null) {
            $cacheKey = "tenant:{$this->currentTenantId}";
            $this->currentTenant = $this->cache->remember($cacheKey, 3600, function () {
                return $this->database->table('companies')
                    ->where('id', $this->currentTenantId)
                    ->first();
            });
        }
        
        return $this->currentTenant;
    }
    
    /**
     * Verifica se um tenant existe
     */
    public function tenantExists(int $tenantId): bool
    {
        $cacheKey = "tenant_exists:{$tenantId}";
        return $this->cache->remember($cacheKey, 3600, function () use ($tenantId) {
            return $this->database->table('companies')
                ->where('id', $tenantId)
                ->where('active', true)
                ->exists();
        });
    }
    
    /**
     * Valida se o usuário tem acesso ao tenant
     */
    public function validateTenantAccess(int $userId, int $tenantId): bool
    {
        $cacheKey = "tenant_access:{$userId}:{$tenantId}";
        return $this->cache->remember($cacheKey, 1800, function () use ($userId, $tenantId) {
            return $this->database->table('users')
                ->where('id', $userId)
                ->where('company_id', $tenantId)
                ->where('active', true)
                ->exists();
        });
    }
    
    /**
     * Força validação de tenant em uma query
     */
    public function scopeToTenant($query, ?int $tenantId = null): void
    {
        $tenantId = $tenantId ?? $this->getCurrentTenantId();
        
        if (! $tenantId) {
            throw new RuntimeException('Nenhum tenant definido para escopo da query');
        }
        
        // Detecta se a tabela tem company_id ou tenant_id
        $tableName = $query->from ?? '';
        if ($this->tableHasColumn($tableName, 'tenant_id')) {
            $query->where('tenant_id', $tenantId);
        } elseif ($this->tableHasColumn($tableName, 'company_id')) {
            $query->where('company_id', $tenantId);
        } else {
            $this->logger->warning('Table without tenant isolation', [
                'table' => $tableName,
                'tenant_id' => $tenantId
            ]);
        }
    }
    
    /**
     * Gera chave de cache com prefixo do tenant
     */
    public function tenantCacheKey(string $key, ?int $tenantId = null): string
    {
        $tenantId = $tenantId ?? $this->getCurrentTenantId();
        return "tenant:{$tenantId}:{$key}";
    }
    
    /**
     * Invalida cache de um tenant específico
     */
    public function invalidateTenantCache(int $tenantId): void
    {
        $pattern = "tenant:{$tenantId}:*";
        $this->cache->deleteByPattern($pattern);
        $this->logger->info('Tenant cache invalidated', ['tenant_id' => $tenantId]);
    }
    
    /**
     * Lista todos os tenants ativos
     */
    public function getActiveTenants(): array
    {
        return $this->cache->remember('active_tenants', 3600, function () {
            return $this->database->table('companies')
                ->where('active', true)
                ->select(['id', 'name', 'code'])
                ->get();
        });
    }
    
    /**
     * Obtém estatísticas de uso por tenant
     */
    public function getTenantStats(int $tenantId): array
    {
        $cacheKey = $this->tenantCacheKey('stats');
        return $this->cache->remember($cacheKey, 1800, function () use ($tenantId) {
            $stats = [];
            
            // Estatísticas das principais entidades
            $tables = [
                'users' => 'company_id',
                'vendas' => 'tenant_id', 
                'clientes' => 'tenant_id',
                'produtos' => 'tenant_id',
                'transacoes_financeiras' => 'tenant_id'
            ];
            
            foreach ($tables as $table => $tenantColumn) {
                try {
                    $stats[$table] = $this->database->table($table)
                        ->where($tenantColumn, $tenantId)
                        ->count();
                } catch (\Exception $e) {
                    $stats[$table] = 0;
                }
            }
            
            return $stats;
        });
    }
    
    /**
     * Verifica se uma tabela tem uma coluna específica
     */
    private function tableHasColumn(string $table, string $column): bool
    {
        if (! $table) return false;
        
        $cacheKey = "table_schema:{$table}:{$column}";
        return $this->cache->remember($cacheKey, 86400, function () use ($table, $column) {
            try {
                $result = $this->database->select("
                    SELECT COUNT(*) as count 
                    FROM INFORMATION_SCHEMA.COLUMNS 
                    WHERE TABLE_SCHEMA = DATABASE() 
                    AND TABLE_NAME = ? 
                    AND COLUMN_NAME = ?
                ", [$table, $column]);
                
                return ($result[0]['count'] ?? 0) > 0;
            } catch (\Exception $e) {
                return false;
            }
        });
    }
    
    /**
     * Executa callback com contexto de tenant temporário
     */
    public function withTenant(int $tenantId, callable $callback): mixed
    {
        $originalTenant = $this->currentTenantId;
        
        try {
            $this->setCurrentTenant($tenantId);
            return $callback();
        } finally {
            $this->setCurrentTenant($originalTenant);
        }
    }
    
    /**
     * Valida que dados pertencem ao tenant atual
     */
    public function validateOwnership(string $table, int $recordId, ?int $tenantId = null): bool
    {
        $tenantId = $tenantId ?? $this->getCurrentTenantId();
        
        if (! $tenantId) {
            return false;
        }
        
        $tenantColumn = $this->tableHasColumn($table, 'tenant_id') ? 'tenant_id' : 'company_id';
        
        return $this->database->table($table)
            ->where('id', $recordId)
            ->where($tenantColumn, $tenantId)
            ->exists();
    }
    
    /**
     * Registra tentativa de acesso cruzado entre tenants
     */
    public function logCrossTenantAccess(int $userId, int $requestedTenantId, string $resource): void
    {
        $this->logger->critical('Cross-tenant access attempt', [
            'user_id' => $userId,
            'current_tenant_id' => $this->getCurrentTenantId(),
            'requested_tenant_id' => $requestedTenantId,
            'resource' => $resource,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
    }
}
