<?php

declare(strict_types=1);

namespace ERP\Core\Auth\Guards;

use ERP\Core\Auth\Contracts\AuthenticatableInterface;
use ERP\Core\Auth\Contracts\GuardInterface;
use ERP\Core\Auth\Contracts\UserProviderInterface;
use ERP\Core\Cache\CacheManager;
use ERP\Core\Security\SecurityManager;

/**
 * Session Authentication Guard
 * 
 * Traditional session-based authentication for web applications
 * 
 * @package ERP\Core\Auth\Guards
 */
final class SessionGuard implements GuardInterface
{
    private ?AuthenticatableInterface $user = null;
    private bool $viaRemember = false;
    private ?string $tenantId = null;
    
    public function __construct(
        private UserProviderInterface $provider,
        private CacheManager $cache,
        private array $config = []
    ) {
        $this->startSession();
    }
    
    /**
     * Set tenant context
     */
    public function setTenant(string $tenantId): self
    {
        $this->tenantId = $tenantId;
        $_SESSION['tenant_id'] = $tenantId;
        return $this;
    }
    
    /**
     * Get current tenant
     */
    public function getTenant(): ?string
    {
        return $this->tenantId ?? $_SESSION['tenant_id'] ?? null;
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
        
        $id = $_SESSION['user_id'] ?? null;
        
        if ($id) {
            $this->user = $this->provider->retrieveById($id);
            
            // Check tenant membership if tenant is set
            if ($this->user && $this->getTenant() && !$this->user->belongsToTenant($this->getTenant())) {
                $this->logout();
                return null;
            }
        }
        
        // Try remember token if no session user
        if (! $this->user && $this->isRememberTokenValid()) {
            $this->loginViaRemember();
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
            if ($this->getTenant() && !$user->belongsToTenant($this->getTenant())) {
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
            if ($this->getTenant() && !$user->belongsToTenant($this->getTenant())) {
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
            if ($this->getTenant() && !$user->belongsToTenant($this->getTenant())) {
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
            if ($this->getTenant() && !$user->belongsToTenant($this->getTenant())) {
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
        return $this->viaRemember;
    }
    
    /**
     * Log the user out of the application
     */
    public function logout(): void
    {
        // Clear remember token if exists
        if (isset($_COOKIE['remember_token'])) {
            setcookie('remember_token', '', time() - 3600, '/', '', true, true);
        }
        
        // Clear session
        $_SESSION = [];
        
        // Destroy session
        if (session_id()) {
            session_destroy();
        }
        
        $this->user = null;
        $this->viaRemember = false;
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
        $this->updateSession($user->getAuthIdentifier());
        
        if ($remember) {
            $this->queueRememberCookie($user);
        }
        
        $this->user = $user;
    }
    
    /**
     * Update the session with the given ID
     */
    protected function updateSession(int|string $id): void
    {
        $_SESSION['user_id'] = $id;
        $_SESSION['login_time'] = time();
        
        // Regenerate session ID for security
        if (session_id()) {
            session_regenerate_id(true);
        }
    }
    
    /**
     * Queue the remember cookie
     */
    protected function queueRememberCookie(AuthenticatableInterface $user): void
    {
        $token = $this->generateRememberToken();
        
        $this->provider->updateRememberToken($user, $token);
        
        setcookie(
            'remember_token',
            $user->getAuthIdentifier() . '|' . $token,
            time() + (60 * 60 * 24 * 30), // 30 days
            '/',
            '',
            true, // secure
            true  // httponly
        );
    }
    
    /**
     * Generate a remember token
     */
    protected function generateRememberToken(): string
    {
        return bin2hex(random_bytes(32));
    }
    
    /**
     * Check if remember token is valid
     */
    protected function isRememberTokenValid(): bool
    {
        if (! isset($_COOKIE['remember_token'])) {
            return false;
        }
        
        $cookie = $_COOKIE['remember_token'];
        
        if (strpos($cookie, '|') === false) {
            return false;
        }
        
        [$id, $token] = explode('|', $cookie, 2);
        
        $user = $this->provider->retrieveByToken($id, $token);
        
        return $user !== null;
    }
    
    /**
     * Login user via remember token
     */
    protected function loginViaRemember(): void
    {
        if (! isset($_COOKIE['remember_token'])) {
            return;
        }
        
        $cookie = $_COOKIE['remember_token'];
        
        if (strpos($cookie, '|') === false) {
            return;
        }
        
        [$id, $token] = explode('|', $cookie, 2);
        
        $user = $this->provider->retrieveByToken($id, $token);
        
        if ($user) {
            // Check tenant membership if tenant is set
            if ($this->getTenant() && !$user->belongsToTenant($this->getTenant())) {
                return;
            }
            
            $this->updateSession($user->getAuthIdentifier());
            $this->user = $user;
            $this->viaRemember = true;
        }
    }
    
    /**
     * Start session if not already started
     */
    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'cookie_lifetime' => 0,
                'cookie_path' => '/',
                'cookie_domain' => '',
                'cookie_secure' => true,
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                'use_strict_mode' => true,
                'use_cookies' => true,
                'use_only_cookies' => true,
            ]);
        }
    }
}
