<?php

declare(strict_types=1);

namespace ERP\Core\Auth\Guards;

use ERP\Core\Auth\Contracts\AuthenticatableInterface;
use ERP\Core\Auth\Contracts\GuardInterface;
use ERP\Core\Auth\Contracts\UserProviderInterface;
use ERP\Core\Auth\TokenPair;
use ERP\Core\Cache\CacheManager;
use ERP\Core\Security\SecurityManager;
use ERP\Core\Exceptions\AuthenticationException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Carbon\Carbon;

/**
 * JWT Authentication Guard
 * 
 * Features:
 * - JWT token generation and validation
 * - Access and refresh token management
 * - Multi-tenant token support
 * - Token blacklisting
 * - Custom claims support
 * - Token rotation
 * 
 * @package ERP\Core\Auth\Guards
 */
final class JwtGuard implements GuardInterface
{
    private ?AuthenticatableInterface $user = null;
    private ?string $token = null;
    private ?array $payload = null;
    private ?string $tenantId = null;
    
    public function __construct(
        private UserProviderInterface $provider,
        private CacheManager $cache,
        private SecurityManager $security,
        private array $config = []
    ) {}
    
    /**
     * Set tenant context
     */
    public function setTenant(string $tenantId): self
    {
        $this->tenantId = $tenantId;
        return $this;
    }
    
    /**
     * Get current tenant
     */
    public function getTenant(): ?string
    {
        return $this->tenantId;
    }
    
    /**
     * Determine if the current user is authenticated
     */
    public function check(): bool
    {
        return $this->user() !== null;
    }
    
    /**
     * Determine if the current user is a guest
     */
    public function guest(): bool
    {
        return !$this->check();
    }
    
    /**
     * Get the currently authenticated user
     */
    public function user(): ?AuthenticatableInterface
    {
        if ($this->user !== null) {
            return $this->user;
        }
        
        if ($this->token && $this->validateToken($this->token)) {
            $payload = $this->getTokenPayload($this->token);
            
            if ($payload && isset($payload['sub'])) {
                $this->user = $this->provider->retrieveById($payload['sub']);
            }
        }
        
        return $this->user;
    }
    
    /**
     * Get the ID for the currently authenticated user
     */
    public function id(): int|string|null
    {
        $user = $this->user();
        return $user?->getAuthIdentifier();
    }
    
    /**
     * Validate a user's credentials
     */
    public function validate(array $credentials = []): bool
    {
        $user = $this->provider->retrieveByCredentials($credentials);
        
        if ($user && $this->provider->validateCredentials($user, $credentials)) {
            // Check tenant membership if tenant is set
            if ($this->tenantId && !$user->belongsToTenant($this->tenantId)) {
                return false;
            }
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Attempt to authenticate a user using the given credentials
     */
    public function attempt(array $credentials = [], bool $remember = false): bool
    {
        $user = $this->provider->retrieveByCredentials($credentials);
        
        if ($user && $this->provider->validateCredentials($user, $credentials)) {
            // Check tenant membership if tenant is set
            if ($this->tenantId && !$user->belongsToTenant($this->tenantId)) {
                return false;
            }
            
            $this->login($user, $remember);
            return true;
        }
        
        return false;
    }
    
    /**
     * Log a user into the application without sessions or cookies
     */
    public function once(array $credentials = []): bool
    {
        if ($this->validate($credentials)) {
            $this->user = $this->provider->retrieveByCredentials($credentials);
            return true;
        }
        
        return false;
    }
    
    /**
     * Log the given user ID into the application
     */
    public function loginUsingId(int|string $id, bool $remember = false): ?AuthenticatableInterface
    {
        $user = $this->provider->retrieveById($id);
        
        if ($user) {
            // Check tenant membership if tenant is set
            if ($this->tenantId && !$user->belongsToTenant($this->tenantId)) {
                return null;
            }
            
            $this->login($user, $remember);
            return $user;
        }
        
        return null;
    }
    
    /**
     * Log the given user ID into the application without sessions or cookies
     */
    public function onceUsingId(int|string $id): ?AuthenticatableInterface
    {
        $user = $this->provider->retrieveById($id);
        
        if ($user) {
            // Check tenant membership if tenant is set
            if ($this->tenantId && !$user->belongsToTenant($this->tenantId)) {
                return null;
            }
            
            $this->user = $user;
            return $user;
        }
        
        return null;
    }
    
    /**
     * Determine if the user was authenticated via "remember me" cookie
     */
    public function viaRemember(): bool
    {
        return false; // JWT doesn't use remember tokens
    }
    
    /**
     * Log the user out of the application
     */
    public function logout(): void
    {
        if ($this->token) {
            $this->blacklistToken($this->token);
        }
        
        $this->user = null;
        $this->token = null;
        $this->payload = null;
    }
    
    /**
     * Set the current user
     */
    public function setUser(AuthenticatableInterface $user): self
    {
        $this->user = $user;
        return $this;
    }
    
    /**
     * Get the user provider used by the guard
     */
    public function getProvider(): UserProviderInterface
    {
        return $this->provider;
    }
    
    /**
     * Log user into the application
     */
    public function login(AuthenticatableInterface $user, bool $remember = false): void
    {
        $this->user = $user;
        
        // Generate JWT token pair
        $tokenPair = $this->generateTokenPair($user);
        $this->token = $tokenPair->accessToken;
    }
    
    /**
     * Set the token for the guard
     */
    public function setToken(string $token): self
    {
        $this->token = $token;
        $this->user = null; // Reset user to force re-authentication
        return $this;
    }
    
    /**
     * Get the current token
     */
    public function getToken(): ?string
    {
        return $this->token;
    }
    
    /**
     * Generate JWT token pair for user
     */
    public function generateTokenPair(AuthenticatableInterface $user, array $customClaims = []): TokenPair
    {
        $now = Carbon::now();
        $accessExpiry = $now->copy()->addMinutes($this->config['access_ttl'] ?? 60);
        $refreshExpiry = $now->copy()->addDays($this->config['refresh_ttl'] ?? 30);
        
        // Base claims
        $baseClaims = [
            'iss' => $this->config['issuer'] ?? 'erp-sistema',
            'aud' => $this->config['audience'] ?? 'erp-sistema',
            'iat' => $now->timestamp,
            'nbf' => $now->timestamp,
            'sub' => $user->getAuthIdentifier(),
            'tenant_id' => $this->tenantId,
            'user_email' => $user->getAuthIdentifierName(),
            'roles' => $user->getRoles($this->tenantId),
            'permissions' => $user->getPermissions($this->tenantId),
        ];
        
        // Access token claims
        $accessClaims = array_merge($baseClaims, $customClaims, [
            'exp' => $accessExpiry->timestamp,
            'type' => 'access',
            'jti' => uniqid('access_', true),
        ]);
        
        // Refresh token claims
        $refreshClaims = array_merge($baseClaims, [
            'exp' => $refreshExpiry->timestamp,
            'type' => 'refresh',
            'jti' => uniqid('refresh_', true),
        ]);
        
        $secret = $this->getJwtSecret();
        $algorithm = $this->config['algorithm'] ?? 'HS256';
        
        $accessToken = JWT::encode($accessClaims, $secret, $algorithm);
        $refreshToken = JWT::encode($refreshClaims, $secret, $algorithm);
        
        return new TokenPair(
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresIn: $accessExpiry->diffInSeconds($now),
            expiresAt: $accessExpiry,
            scopes: $user->getPermissions($this->tenantId)
        );
    }
    
    /**
     * Refresh JWT token using refresh token
     */
    public function refreshToken(string $refreshToken): TokenPair
    {
        if (! $this->validateToken($refreshToken)) {
            throw new AuthenticationException('Invalid refresh token');
        }
        
        $payload = $this->getTokenPayload($refreshToken);
        
        if (! $payload || ($payload['type'] ?? '') !== 'refresh') {
            throw new AuthenticationException('Token is not a refresh token');
        }
        
        $user = $this->provider->retrieveById($payload['sub']);
        
        if (! $user) {
            throw new AuthenticationException('User not found');
        }
        
        // Check tenant membership
        if ($this->tenantId && !$user->belongsToTenant($this->tenantId)) {
            throw new AuthenticationException('User does not belong to tenant');
        }
        
        // Blacklist old refresh token
        $this->blacklistToken($refreshToken);
        
        // Generate new token pair
        return $this->generateTokenPair($user);
    }
    
    /**
     * Validate JWT token
     */
    public function validateToken(string $token): bool
    {
        try {
            // Check if token is blacklisted
            if ($this->isTokenBlacklisted($token)) {
                return false;
            }
            
            $secret = $this->getJwtSecret();
            $algorithm = $this->config['algorithm'] ?? 'HS256';
            
            JWT::decode($token, new Key($secret, $algorithm));
            
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
    
    /**
     * Get token payload
     */
    public function getTokenPayload(string $token): ?array
    {
        try {
            $secret = $this->getJwtSecret();
            $algorithm = $this->config['algorithm'] ?? 'HS256';
            
            $decoded = JWT::decode($token, new Key($secret, $algorithm));
            
            return (array) $decoded;
        } catch (\Throwable) {
            return null;
        }
    }
    
    /**
     * Blacklist token
     */
    private function blacklistToken(string $token): void
    {
        $payload = $this->getTokenPayload($token);
        
        if ($payload && isset($payload['exp'], $payload['jti'])) {
            $ttl = max(0, $payload['exp'] - time());
            $this->cache->put("blacklist:{$payload['jti']}", true, $ttl);
        }
    }
    
    /**
     * Check if token is blacklisted
     */
    private function isTokenBlacklisted(string $token): bool
    {
        $payload = $this->getTokenPayload($token);
        
        if ($payload && isset($payload['jti'])) {
            return $this->cache->has("blacklist:{$payload['jti']}");
        }
        
        return false;
    }
    
    /**
     * Get JWT secret from configuration
     */
    private function getJwtSecret(): string
    {
        $secret = $this->config['secret'] ?? $this->security->getJwtSecret();
        
        if (empty($secret)) {
            throw new \RuntimeException('JWT secret not configured');
        }
        
        return $secret;
    }
    
    /**
     * Revoke all tokens for user
     */
    public function revokeAllTokens(AuthenticatableInterface $user): void
    {
        // This would typically involve adding the user to a revocation list
        // or incrementing a token version number in the database
        $this->cache->put("user_token_version:{$user->getAuthIdentifier()}", time(), 86400 * 30);
    }
    
    /**
     * Get user from token
     */
    public function getUserFromToken(string $token): ?AuthenticatableInterface
    {
        if (! $this->validateToken($token)) {
            return null;
        }
        
        $payload = $this->getTokenPayload($token);
        
        if ($payload && isset($payload['sub'])) {
            return $this->provider->retrieveById($payload['sub']);
        }
        
        return null;
    }
}
