<?php

declare(strict_types=1);

namespace ERP\Core\Auth;

use Carbon\Carbon;

/**
 * JWT Token Pair
 * 
 * Represents an access/refresh token pair for JWT authentication
 * 
 * @package ERP\Core\Auth
 */
final readonly class TokenPair
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public int $expiresIn,
        public string $tokenType = 'Bearer',
        public Carbon $expiresAt = new Carbon(),
        public array $scopes = []
    ) {}
    
    /**
     * Check if access token is expired
     */
    public function isExpired(): bool
    {
        return Carbon::now()->isAfter($this->expiresAt);
    }
    
    /**
     * Get seconds until expiration
     */
    public function secondsUntilExpiration(): int
    {
        return max(0, $this->expiresAt->diffInSeconds(Carbon::now()));
    }
    
    /**
     * Convert to array for JSON response
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'token_type' => $this->tokenType,
            'expires_in' => $this->expiresIn,
            'expires_at' => $this->expiresAt->toISOString(),
            'scopes' => $this->scopes,
        ];
    }
    
    /**
     * Convert to JSON
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }
    
    /**
     * Get authorization header value
     */
    public function getAuthorizationHeader(): string
    {
        return "{$this->tokenType} {$this->accessToken}";
    }
}