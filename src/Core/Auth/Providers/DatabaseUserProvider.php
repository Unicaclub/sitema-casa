<?php

declare(strict_types=1);

namespace ERP\Core\Auth\Providers;

use ERP\Core\Auth\Contracts\AuthenticatableInterface;
use ERP\Core\Auth\Contracts\UserProviderInterface;
use ERP\Core\Database\DatabaseManager;
use ERP\Core\Auth\User;

/**
 * Database User Provider
 * 
 * Retrieves users from database for authentication
 * 
 * @package ERP\Core\Auth\Providers
 */
final class DatabaseUserProvider implements UserProviderInterface
{
    public function __construct(
        private DatabaseManager $database,
        private string $table = 'users',
        private ?string $model = null
    ) {}
    
    /**
     * Retrieve a user by their unique identifier
     */
    public function retrieveById(int|string $identifier): ?AuthenticatableInterface
    {
        $user = $this->database->table($this->table)
            ->where('id', $identifier)
            ->where('active', true)
            ->first();
        
        return $user ? $this->createUserModel($user) : null;
    }
    
    /**
     * Retrieve a user by their unique identifier and "remember me" token
     */
    public function retrieveByToken(int|string $identifier, string $token): ?AuthenticatableInterface
    {
        $user = $this->database->table($this->table)
            ->where('id', $identifier)
            ->where('remember_token', $token)
            ->where('active', true)
            ->first();
        
        return $user ? $this->createUserModel($user) : null;
    }
    
    /**
     * Update the "remember me" token for the given user in storage
     */
    public function updateRememberToken(AuthenticatableInterface $user, string $token): void
    {
        $this->database->table($this->table)
            ->where('id', $user->getAuthIdentifier())
            ->update(['remember_token' => $token]);
    }
    
    /**
     * Retrieve a user by the given credentials
     */
    public function retrieveByCredentials(array $credentials): ?AuthenticatableInterface
    {
        $query = $this->database->table($this->table);
        
        foreach ($credentials as $key => $value) {
            if ($key === 'password') {
                continue;
            }
            
            $query->where($key, $value);
        }
        
        $user = $query->where('active', true)->first();
        
        return $user ? $this->createUserModel($user) : null;
    }
    
    /**
     * Validate a user against the given credentials
     */
    public function validateCredentials(AuthenticatableInterface $user, array $credentials): bool
    {
        $password = $credentials['password'] ?? '';
        
        return password_verify($password, $user->getAuthPassword());
    }
    
    /**
     * Rehash the user's password if required and supported
     */
    public function rehashPasswordIfRequired(AuthenticatableInterface $user, array $credentials, bool $force = false): void
    {
        $password = $credentials['password'] ?? '';
        $currentHash = $user->getAuthPassword();
        
        if ($force || password_needs_rehash($currentHash, PASSWORD_DEFAULT)) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            
            $this->database->table($this->table)
                ->where('id', $user->getAuthIdentifier())
                ->update(['password' => $newHash]);
        }
    }
    
    /**
     * Retrieve users by tenant
     */
    public function retrieveByTenant(string $tenantId): array
    {
        $users = $this->database->table($this->table)
            ->join('user_tenants', 'users.id', '=', 'user_tenants.user_id')
            ->where('user_tenants.tenant_id', $tenantId)
            ->where('user_tenants.active', true)
            ->where('users.active', true)
            ->select('users.*')
            ->get();
        
        return array_map(fn($user) => $this->createUserModel($user), $users->toArray());
    }
    
    /**
     * Check if email exists in tenant
     */
    public function emailExistsInTenant(string $email, string $tenantId): bool
    {
        return $this->database->table($this->table)
            ->join('user_tenants', 'users.id', '=', 'user_tenants.user_id')
            ->where('users.email', $email)
            ->where('user_tenants.tenant_id', $tenantId)
            ->where('user_tenants.active', true)
            ->where('users.active', true)
            ->exists();
    }
    
    /**
     * Create user model instance
     */
    private function createUserModel(object|array $userData): AuthenticatableInterface
    {
        $data = is_object($userData) ? (array) $userData : $userData;
        
        if ($this->model && class_exists($this->model)) {
            return new $this->model($data);
        }
        
        return new User($data);
    }
    
    /**
     * Create a new user
     */
    public function createUser(array $userData): AuthenticatableInterface
    {
        // Hash password if provided
        if (isset($userData['password'])) {
            $userData['password'] = password_hash($userData['password'], PASSWORD_DEFAULT);
        }
        
        $userData['created_at'] = date('Y-m-d H:i:s');
        $userData['updated_at'] = date('Y-m-d H:i:s');
        
        $id = $this->database->table($this->table)->insertGetId($userData);
        
        return $this->retrieveById($id);
    }
    
    /**
     * Update user
     */
    public function updateUser(AuthenticatableInterface $user, array $userData): bool
    {
        // Hash password if provided
        if (isset($userData['password'])) {
            $userData['password'] = password_hash($userData['password'], PASSWORD_DEFAULT);
        }
        
        $userData['updated_at'] = date('Y-m-d H:i:s');
        
        return $this->database->table($this->table)
            ->where('id', $user->getAuthIdentifier())
            ->update($userData) > 0;
    }
    
    /**
     * Delete user (soft delete)
     */
    public function deleteUser(AuthenticatableInterface $user): bool
    {
        return $this->database->table($this->table)
            ->where('id', $user->getAuthIdentifier())
            ->update([
                'active' => false,
                'deleted_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]) > 0;
    }
    
    /**
     * Assign user to tenant
     */
    public function assignToTenant(AuthenticatableInterface $user, string $tenantId, array $roles = []): bool
    {
        // Check if already assigned
        $exists = $this->database->table('user_tenants')
            ->where('user_id', $user->getAuthIdentifier())
            ->where('tenant_id', $tenantId)
            ->exists();
        
        if ($exists) {
            return true;
        }
        
        // Assign to tenant
        $this->database->table('user_tenants')->insert([
            'user_id' => $user->getAuthIdentifier(),
            'tenant_id' => $tenantId,
            'active' => true,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Assign roles if provided
        foreach ($roles as $role) {
            $this->assignRole($user, $role, $tenantId);
        }
        
        return true;
    }
    
    /**
     * Remove user from tenant
     */
    public function removeFromTenant(AuthenticatableInterface $user, string $tenantId): bool
    {
        return $this->database->table('user_tenants')
            ->where('user_id', $user->getAuthIdentifier())
            ->where('tenant_id', $tenantId)
            ->delete() > 0;
    }
    
    /**
     * Assign role to user in tenant
     */
    public function assignRole(AuthenticatableInterface $user, string $roleName, string $tenantId): bool
    {
        // Get role ID
        $role = $this->database->table('roles')
            ->where('name', $roleName)
            ->where('active', true)
            ->first();
        
        if (!$role) {
            return false;
        }
        
        // Check if already assigned
        $exists = $this->database->table('user_roles')
            ->where('user_id', $user->getAuthIdentifier())
            ->where('role_id', $role->id)
            ->where('tenant_id', $tenantId)
            ->exists();
        
        if ($exists) {
            return true;
        }
        
        // Assign role
        $this->database->table('user_roles')->insert([
            'user_id' => $user->getAuthIdentifier(),
            'role_id' => $role->id,
            'tenant_id' => $tenantId,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        return true;
    }
    
    /**
     * Remove role from user in tenant
     */
    public function removeRole(AuthenticatableInterface $user, string $roleName, string $tenantId): bool
    {
        $role = $this->database->table('roles')
            ->where('name', $roleName)
            ->first();
        
        if (!$role) {
            return false;
        }
        
        return $this->database->table('user_roles')
            ->where('user_id', $user->getAuthIdentifier())
            ->where('role_id', $role->id)
            ->where('tenant_id', $tenantId)
            ->delete() > 0;
    }
}