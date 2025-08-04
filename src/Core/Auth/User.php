<?php

declare(strict_types=1);

namespace ERP\Core\Auth;

use ERP\Core\Auth\Contracts\AuthenticatableInterface;
use ERP\Core\Database\DatabaseManager;

/**
 * Default User Model
 * 
 * Default implementation of authenticatable user
 * 
 * @package ERP\Core\Auth
 */
final class User implements AuthenticatableInterface
{
    private array $attributes;
    private ?DatabaseManager $database = null;
    
    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
    }
    
    /**
     * Set database manager for dynamic queries
     */
    public function setDatabase(DatabaseManager $database): self
    {
        $this->database = $database;
        return $this;
    }
    
    /**
     * Get attribute value
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
    
    /**
     * Set attribute value
     */
    public function setAttribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }
    
    /**
     * Get all attributes
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }
    
    /**
     * Get the unique identifier for the user
     */
    public function getAuthIdentifier(): int|string
    {
        return $this->attributes['id'];
    }
    
    /**
     * Get the name of the unique identifier for the user
     */
    public function getAuthIdentifierName(): string
    {
        return 'id';
    }
    
    /**
     * Get the password for the user
     */
    public function getAuthPassword(): string
    {
        return $this->attributes['password'] ?? '';
    }
    
    /**
     * Get the "remember token" for the user
     */
    public function getRememberToken(): ?string
    {
        return $this->attributes['remember_token'] ?? null;
    }
    
    /**
     * Set the "remember token" for the user
     */
    public function setRememberToken(string $value): void
    {
        $this->attributes['remember_token'] = $value;
    }
    
    /**
     * Get the name of the "remember token" column
     */
    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
    
    /**
     * Get the user's roles for a specific tenant
     */
    public function getRoles(string $tenantId = null): array
    {
        if (!$this->database) {
            return [];
        }
        
        $query = $this->database->table('user_roles')
            ->join('roles', 'user_roles.role_id', '=', 'roles.id')
            ->where('user_roles.user_id', $this->getAuthIdentifier())
            ->where('roles.active', true);
        
        if ($tenantId) {
            $query->where('user_roles.tenant_id', $tenantId);
        }
        
        return $query->pluck('roles.name')->toArray();
    }
    
    /**
     * Get the user's permissions for a specific tenant
     */
    public function getPermissions(string $tenantId = null): array
    {
        if (!$this->database) {
            return [];
        }
        
        // Get permissions from roles
        $rolePermissions = $this->database->table('user_roles')
            ->join('role_permissions', 'user_roles.role_id', '=', 'role_permissions.role_id')
            ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
            ->where('user_roles.user_id', $this->getAuthIdentifier())
            ->where('permissions.active', true)
            ->when($tenantId, fn($q) => $q->where('user_roles.tenant_id', $tenantId))
            ->pluck('permissions.name');
        
        // Get direct permissions
        $directPermissions = $this->database->table('user_permissions')
            ->join('permissions', 'user_permissions.permission_id', '=', 'permissions.id')
            ->where('user_permissions.user_id', $this->getAuthIdentifier())
            ->where('permissions.active', true)
            ->when($tenantId, fn($q) => $q->where('user_permissions.tenant_id', $tenantId))
            ->pluck('permissions.name');
        
        return array_unique(array_merge($rolePermissions->toArray(), $directPermissions->toArray()));
    }
    
    /**
     * Check if user has role in tenant
     */
    public function hasRole(string $role, string $tenantId = null): bool
    {
        return in_array($role, $this->getRoles($tenantId));
    }
    
    /**
     * Check if user has permission in tenant
     */
    public function hasPermission(string $permission, string $tenantId = null): bool
    {
        return in_array($permission, $this->getPermissions($tenantId));
    }
    
    /**
     * Get user's tenants
     */
    public function getTenants(): array
    {
        if (!$this->database) {
            return [];
        }
        
        return $this->database->table('user_tenants')
            ->join('tenants', 'user_tenants.tenant_id', '=', 'tenants.id')
            ->where('user_tenants.user_id', $this->getAuthIdentifier())
            ->where('user_tenants.active', true)
            ->where('tenants.active', true)
            ->select('tenants.*')
            ->get()
            ->toArray();
    }
    
    /**
     * Check if user belongs to tenant
     */
    public function belongsToTenant(string $tenantId): bool
    {
        if (!$this->database) {
            return false;
        }
        
        return $this->database->table('user_tenants')
            ->where('user_id', $this->getAuthIdentifier())
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->exists();
    }
    
    /**
     * Get user's email
     */
    public function getEmail(): string
    {
        return $this->attributes['email'] ?? '';
    }
    
    /**
     * Get user's name
     */
    public function getName(): string
    {
        return $this->attributes['name'] ?? '';
    }
    
    /**
     * Get user's first name
     */
    public function getFirstName(): string
    {
        return $this->attributes['first_name'] ?? '';
    }
    
    /**
     * Get user's last name
     */
    public function getLastName(): string
    {
        return $this->attributes['last_name'] ?? '';
    }
    
    /**
     * Get user's full name
     */
    public function getFullName(): string
    {
        $firstName = $this->getFirstName();
        $lastName = $this->getLastName();
        
        if ($firstName && $lastName) {
            return "{$firstName} {$lastName}";
        }
        
        return $this->getName() ?: $this->getEmail();
    }
    
    /**
     * Check if user is active
     */
    public function isActive(): bool
    {
        return (bool) ($this->attributes['active'] ?? false);
    }
    
    /**
     * Check if user email is verified
     */
    public function isEmailVerified(): bool
    {
        return !empty($this->attributes['email_verified_at']);
    }
    
    /**
     * Get user creation date
     */
    public function getCreatedAt(): ?string
    {
        return $this->attributes['created_at'] ?? null;
    }
    
    /**
     * Get user last update date
     */
    public function getUpdatedAt(): ?string
    {
        return $this->attributes['updated_at'] ?? null;
    }
    
    /**
     * Convert user to array
     */
    public function toArray(): array
    {
        $data = $this->attributes;
        
        // Remove sensitive data
        unset($data['password'], $data['remember_token']);
        
        return $data;
    }
    
    /**
     * Convert user to JSON
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }
    
    /**
     * Magic getter
     */
    public function __get(string $key): mixed
    {
        return $this->getAttribute($key);
    }
    
    /**
     * Magic setter
     */
    public function __set(string $key, mixed $value): void
    {
        $this->setAttribute($key, $value);
    }
    
    /**
     * Magic isset
     */
    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }
    
    /**
     * Magic unset
     */
    public function __unset(string $key): void
    {
        unset($this->attributes[$key]);
    }
    
    /**
     * String representation
     */
    public function __toString(): string
    {
        return $this->getFullName() ?: "User #{$this->getAuthIdentifier()}";
    }
}