<?php

namespace Core\MultiTenant;

use Core\Database\QueryBuilder;

/**
 * TenantScoped - Trait para aplicar escopo automático de tenant em models
 * 
 * Automaticamente adiciona filtro por tenant_id em todas as queries
 * Previne vazamento de dados entre tenants
 */
trait TenantScoped
{
    /**
     * Campo usado para tenant (tenant_id ou company_id)
     */
    protected function getTenantColumn(): string
    {
        return property_exists($this, 'tenantColumn') ? $this->tenantColumn : 'tenant_id';
    }
    
    /**
     * Aplica escopo de tenant automaticamente
     */
    public function scopeTenant(QueryBuilder $query, ?int $tenantId = null): QueryBuilder
    {
        $tenantManager = app(TenantManager::class);
        $tenantId = $tenantId ?? $tenantManager->getCurrentTenantId();
        
        if ($tenantId) {
            $tenantColumn = $this->getTenantColumn();
            return $query->where($tenantColumn, $tenantId);
        }
        
        return $query;
    }
    
    /**
     * Aplica escopo global de tenant para todas as queries
     */
    public static function bootTenantScoped(): void
    {
        static::addGlobalScope('tenant', function (QueryBuilder $query) {
            $tenantManager = app(TenantManager::class);
            $tenantId = $tenantManager->getCurrentTenantId();
            
            if ($tenantId) {
                $instance = new static();
                $tenantColumn = $instance->getTenantColumn();
                $query->where($tenantColumn, $tenantId);
            }
        });
        
        // Ao criar um novo registro, define tenant_id automaticamente
        static::creating(function ($model) {
            $tenantManager = app(TenantManager::class);
            $tenantId = $tenantManager->getCurrentTenantId();
            
            if ($tenantId) {
                $tenantColumn = $model->getTenantColumn();
                if (!isset($model->attributes[$tenantColumn])) {
                    $model->setAttribute($tenantColumn, $tenantId);
                }
            }
        });
    }
    
    /**
     * Query sem escopo de tenant (usar com cuidado)
     */
    public static function withoutTenantScope(): QueryBuilder
    {
        return static::withoutGlobalScope('tenant');
    }
    
    /**
     * Query com tenant específico
     */
    public static function forTenant(int $tenantId): QueryBuilder
    {
        return static::withoutGlobalScope('tenant')->where(
            (new static())->getTenantColumn(), 
            $tenantId
        );
    }
    
    /**
     * Verifica se o registro pertence ao tenant atual
     */
    public function belongsToCurrentTenant(): bool
    {
        $tenantManager = app(TenantManager::class);
        $currentTenantId = $tenantManager->getCurrentTenantId();
        $tenantColumn = $this->getTenantColumn();
        
        return $this->getAttribute($tenantColumn) === $currentTenantId;
    }
    
    /**
     * Valida se o registro pode ser acessado pelo tenant atual
     */
    public function validateTenantAccess(): void
    {
        if (!$this->belongsToCurrentTenant()) {
            $tenantManager = app(TenantManager::class);
            $tenantColumn = $this->getTenantColumn();
            
            $tenantManager->logCrossTenantAccess(
                auth()->id() ?? 0,
                $this->getAttribute($tenantColumn),
                static::class . ':' . $this->getKey()
            );
            
            throw new \InvalidArgumentException('Resource not found or access denied');
        }
    }
}