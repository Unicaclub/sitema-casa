<?php

declare(strict_types=1);

namespace ERP\Core\Auth\Contracts;

/**
 * User Provider Interface
 * 
 * Contract for user providers that retrieve users from storage
 * 
 * @package ERP\Core\Auth\Contracts
 */
interface UserProviderInterface
{
    /**
     * Retrieve a user by their unique identifier
     */
    public function retrieveById(int|string $identifier): ?AuthenticatableInterface;
    
    /**
     * Retrieve a user by their unique identifier and "remember me" token
     */
    public function retrieveByToken(int|string $identifier, string $token): ?AuthenticatableInterface;
    
    /**
     * Update the "remember me" token for the given user in storage
     */
    public function updateRememberToken(AuthenticatableInterface $user, string $token): void;
    
    /**
     * Retrieve a user by the given credentials
     */
    public function retrieveByCredentials(array $credentials): ?AuthenticatableInterface;
    
    /**
     * Validate a user against the given credentials
     */
    public function validateCredentials(AuthenticatableInterface $user, array $credentials): bool;
    
    /**
     * Rehash the user's password if required and supported
     */
    public function rehashPasswordIfRequired(AuthenticatableInterface $user, array $credentials, bool $force = false): void;
    
    /**
     * Retrieve users by tenant
     */
    public function retrieveByTenant(string $tenantId): array;
    
    /**
     * Check if email exists in tenant
     */
    public function emailExistsInTenant(string $email, string $tenantId): bool;
}