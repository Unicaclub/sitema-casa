<?php

declare(strict_types=1);

namespace ERP\Core\Auth\Contracts;

/**
 * Authenticatable Interface
 * 
 * Contract for user models that can be authenticated
 * 
 * @package ERP\Core\Auth\Contracts
 */
interface AuthenticatableInterface
{
    /**
     * Get the unique identifier for the user
     */
    public function getAuthIdentifier(): int|string;
    
    /**
     * Get the name of the unique identifier for the user
     */
    public function getAuthIdentifierName(): string;
    
    /**
     * Get the password for the user
     */
    public function getAuthPassword(): string;
    
    /**
     * Get the "remember token" for the user
     */
    public function getRememberToken(): ?string;
    
    /**
     * Set the "remember token" for the user
     */
    public function setRememberToken(string $value): void;
    
    /**
     * Get the name of the "remember token" column
     */
    public function getRememberTokenName(): string;
    
    /**
     * Get the user's roles for a specific tenant
     */
    public function getRoles(string $tenantId = null): array;
    
    /**
     * Get the user's permissions for a specific tenant
     */
    public function getPermissions(string $tenantId = null): array;
    
    /**
     * Check if user has role in tenant
     */
    public function hasRole(string $role, string $tenantId = null): bool;
    
    /**
     * Check if user has permission in tenant
     */
    public function hasPermission(string $permission, string $tenantId = null): bool;
    
    /**
     * Get user's tenants
     */
    public function getTenants(): array;
    
    /**
     * Check if user belongs to tenant
     */
    public function belongsToTenant(string $tenantId): bool;
}