<?php

declare(strict_types=1);

namespace ERP\Core\Auth\Contracts;

/**
 * Authentication Guard Interface
 * 
 * Contract for authentication guards
 * 
 * @package ERP\Core\Auth\Contracts
 */
interface GuardInterface
{
    /**
     * Determine if the current user is authenticated
     */
    public function check(): bool;
    
    /**
     * Determine if the current user is a guest
     */
    public function guest(): bool;
    
    /**
     * Get the currently authenticated user
     */
    public function user(): ?AuthenticatableInterface;
    
    /**
     * Get the ID for the currently authenticated user
     */
    public function id(): int|string|null;
    
    /**
     * Validate a user's credentials
     */
    public function validate(array $credentials = []): bool;
    
    /**
     * Attempt to authenticate a user using the given credentials
     */
    public function attempt(array $credentials = [], bool $remember = false): bool;
    
    /**
     * Log a user into the application without sessions or cookies
     */
    public function once(array $credentials = []): bool;
    
    /**
     * Log the given user ID into the application
     */
    public function loginUsingId(int|string $id, bool $remember = false): ?AuthenticatableInterface;
    
    /**
     * Log the given user ID into the application without sessions or cookies
     */
    public function onceUsingId(int|string $id): ?AuthenticatableInterface;
    
    /**
     * Determine if the user was authenticated via "remember me" cookie
     */
    public function viaRemember(): bool;
    
    /**
     * Log the user out of the application
     */
    public function logout(): void;
    
    /**
     * Set the current user
     */
    public function setUser(AuthenticatableInterface $user): self;
    
    /**
     * Get the user provider used by the guard
     */
    public function getProvider(): UserProviderInterface;
}