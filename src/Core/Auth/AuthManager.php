<?php

declare(strict_types=1);

namespace ERP\Core\Auth;

use ERP\Core\Database\DatabaseManager;
use ERP\Core\Cache\CacheManager;
use ERP\Core\Security\SecurityManager;
use ERP\Core\Auth\Guards\JwtGuard;
use ERP\Core\Auth\Guards\SessionGuard;
use ERP\Core\Auth\Providers\DatabaseUserProvider;
use ERP\Core\Auth\Contracts\AuthenticatableInterface;
use ERP\Core\Auth\Contracts\GuardInterface;
use ERP\Core\Auth\Contracts\UserProviderInterface;
use ERP\Core\Exceptions\AuthenticationException;
use ERP\Core\Exceptions\AuthorizationException;
use Carbon\Carbon;

/**
 * Advanced Multi-Tenant Authentication Manager
 * 
 * Features:
 * - JWT authentication with refresh tokens
 * - Multi-tenant user isolation
 * - Role-based access control (RBAC)
 * - Permission-based authorization
 * - Two-factor authentication (2FA)
 * - Rate limiting and brute force protection
 * - Session management
 * - Device tracking
 * - Audit logging
 * 
 * @package ERP\Core\Auth
 */
final class AuthManager
{
    private array $guards = [];
    private array $userProviders = [];
    private ?string $defaultGuard = null;
    private ?string $currentTenant = null;
    private ?AuthenticatableInterface $user = null;
    
    public function __construct(
        private DatabaseManager $database,
        private CacheManager $cache,
        private SecurityManager $security,
        private array $config = []
    ) {
        $this->defaultGuard = $config['default'] ?? 'jwt';
        $this->registerDefaultProviders();
        $this->registerDefaultGuards();
    }
    
    /**
     * Get authentication guard
     */
    public function guard(string $name = null): GuardInterface
    {
        $name = $name ?? $this->defaultGuard;
        
        if (!isset($this->guards[$name])) {
            $this->guards[$name] = $this->createGuard($name);
        }
        
        return $this->guards[$name];
    }
    
    /**
     * Set current tenant
     */
    public function setTenant(string $tenantId): self
    {
        $this->currentTenant = $tenantId;
        
        // Update guards with new tenant context
        foreach ($this->guards as $guard) {
            if (method_exists($guard, 'setTenant')) {
                $guard->setTenant($tenantId);
            }
        }
        
        return $this;
    }
    
    /**
     * Get current tenant
     */
    public function getTenant(): ?string
    {
        return $this->currentTenant;
    }
    
    /**
     * Attempt to authenticate user
     */
    public function attempt(array $credentials, string $tenantId = null, bool $remember = false): bool
    {
        if ($tenantId) {
            $this->setTenant($tenantId);
        }
        
        $guard = $this->guard();
        
        // Rate limiting check
        if (!$this->checkRateLimit($credentials['email'] ?? '', $tenantId)) {
            throw new AuthenticationException('Too many login attempts. Please try again later.');
        }
        
        $result = $guard->attempt($credentials, $remember);
        
        if (!$result) {
            $this->recordFailedAttempt($credentials['email'] ?? '', $tenantId);
        } else {
            $this->clearFailedAttempts($credentials['email'] ?? '', $tenantId);
            $this->recordSuccessfulLogin($guard->user());
        }
        
        return $result;
    }
    
    /**
     * Login user directly
     */
    public function login(AuthenticatableInterface $user, string $tenantId = null, bool $remember = false): bool
    {
        if ($tenantId) {
            $this->setTenant($tenantId);
        }
        
        // Verify user belongs to tenant
        if ($this->currentTenant && !$this->userBelongsToTenant($user, $this->currentTenant)) {
            throw new AuthenticationException('User does not belong to this tenant.');
        }
        
        $guard = $this->guard();
        $guard->login($user, $remember);
        
        $this->recordSuccessfulLogin($user);
        
        return true;
    }
    
    /**
     * Logout current user
     */
    public function logout(): void
    {
        $guard = $this->guard();
        $user = $guard->user();
        
        if ($user) {
            $this->recordLogout($user);
        }
        
        $guard->logout();
        $this->user = null;
    }
    
    /**
     * Get authenticated user
     */
    public function user(): ?AuthenticatableInterface
    {
        if ($this->user !== null) {
            return $this->user;
        }
        
        return $this->user = $this->guard()->user();
    }
    
    /**
     * Get user ID
     */
    public function id(): ?int
    {
        $user = $this->user();
        return $user?->getAuthIdentifier();
    }
    
    /**
     * Check if user is authenticated
     */
    public function check(): bool
    {
        return $this->guard()->check();
    }
    
    /**
     * Check if user is guest
     */
    public function guest(): bool
    {
        return !$this->check();
    }
    
    /**
     * Generate JWT token for user
     */
    public function generateToken(AuthenticatableInterface $user, array $claims = []): TokenPair
    {
        $jwtGuard = $this->guard('jwt');
        
        if (!$jwtGuard instanceof JwtGuard) {
            throw new \InvalidArgumentException('JWT guard not available');
        }
        
        return $jwtGuard->generateTokenPair($user, $claims);
    }
    
    /**
     * Refresh JWT token
     */
    public function refreshToken(string $refreshToken): TokenPair
    {
        $jwtGuard = $this->guard('jwt');
        
        if (!$jwtGuard instanceof JwtGuard) {
            throw new \InvalidArgumentException('JWT guard not available');
        }
        
        return $jwtGuard->refreshToken($refreshToken);
    }
    
    /**
     * Validate JWT token
     */
    public function validateToken(string $token): bool
    {
        $jwtGuard = $this->guard('jwt');
        
        if (!$jwtGuard instanceof JwtGuard) {
            return false;
        }
        
        return $jwtGuard->validateToken($token);
    }
    
    /**
     * Check if user has role
     */
    public function hasRole(string $role, string $tenantId = null): bool
    {
        $user = $this->user();
        
        if (!$user) {
            return false;
        }
        
        $tenantId = $tenantId ?? $this->currentTenant;
        
        return $this->getUserRoles($user, $tenantId)->contains($role);
    }
    
    /**
     * Check if user has any of the given roles
     */
    public function hasAnyRole(array $roles, string $tenantId = null): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($role, $tenantId)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if user has permission
     */
    public function can(string $permission, string $tenantId = null): bool
    {
        $user = $this->user();
        
        if (!$user) {
            return false;
        }
        
        $tenantId = $tenantId ?? $this->currentTenant;
        
        return $this->getUserPermissions($user, $tenantId)->contains($permission);
    }
    
    /**
     * Check if user cannot perform action
     */
    public function cannot(string $permission, string $tenantId = null): bool
    {
        return !$this->can($permission, $tenantId);
    }
    
    /**
     * Authorize user action
     */
    public function authorize(string $permission, string $tenantId = null): void
    {
        if (!$this->can($permission, $tenantId)) {
            throw new AuthorizationException("Insufficient permissions for: {$permission}");
        }
    }
    
    /**
     * Enable two-factor authentication
     */
    public function enableTwoFactor(AuthenticatableInterface $user): TwoFactorData
    {
        $secret = $this->security->generateTwoFactorSecret();
        $backupCodes = $this->security->generateBackupCodes();
        
        $this->database->table('user_two_factor')->updateOrInsert(
            ['user_id' => $user->getAuthIdentifier(), 'tenant_id' => $this->currentTenant],
            [
                'secret' => $this->security->encrypt($secret),
                'backup_codes' => $this->security->encrypt(json_encode($backupCodes)),
                'enabled' => false,
                'confirmed_at' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]
        );
        
        return new TwoFactorData($secret, $backupCodes);
    }
    
    /**
     * Confirm two-factor authentication setup
     */
    public function confirmTwoFactor(AuthenticatableInterface $user, string $code): bool
    {
        $twoFactor = $this->getTwoFactorData($user);
        
        if (!$twoFactor || !$this->security->verifyTwoFactorCode($twoFactor['secret'], $code)) {
            return false;
        }
        
        $this->database->table('user_two_factor')
            ->where('user_id', $user->getAuthIdentifier())
            ->where('tenant_id', $this->currentTenant)
            ->update([
                'enabled' => true,
                'confirmed_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        
        return true;
    }
    
    /**
     * Disable two-factor authentication
     */
    public function disableTwoFactor(AuthenticatableInterface $user): void
    {
        $this->database->table('user_two_factor')
            ->where('user_id', $user->getAuthIdentifier())
            ->where('tenant_id', $this->currentTenant)
            ->delete();
    }
    
    /**
     * Verify two-factor code
     */
    public function verifyTwoFactorCode(AuthenticatableInterface $user, string $code): bool
    {
        $twoFactor = $this->getTwoFactorData($user);
        
        if (!$twoFactor || !$twoFactor['enabled']) {
            return false;
        }
        
        // Check TOTP code
        if ($this->security->verifyTwoFactorCode($twoFactor['secret'], $code)) {
            return true;
        }
        
        // Check backup codes
        $backupCodes = json_decode($this->security->decrypt($twoFactor['backup_codes']), true);
        
        if (in_array($code, $backupCodes)) {
            // Remove used backup code
            $backupCodes = array_diff($backupCodes, [$code]);
            
            $this->database->table('user_two_factor')
                ->where('user_id', $user->getAuthIdentifier())
                ->where('tenant_id', $this->currentTenant)
                ->update([
                    'backup_codes' => $this->security->encrypt(json_encode(array_values($backupCodes))),
                    'updated_at' => Carbon::now(),
                ]);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if user has two-factor enabled
     */
    public function hasTwoFactorEnabled(AuthenticatableInterface $user): bool
    {
        $twoFactor = $this->getTwoFactorData($user);
        
        return $twoFactor && $twoFactor['enabled'];
    }
    
    /**
     * Get user sessions
     */
    public function getUserSessions(AuthenticatableInterface $user): array
    {
        return $this->database->table('user_sessions')
            ->where('user_id', $user->getAuthIdentifier())
            ->where('tenant_id', $this->currentTenant)
            ->where('expires_at', '>', Carbon::now())
            ->orderBy('last_activity', 'desc')
            ->get()
            ->toArray();
    }
    
    /**
     * Revoke user session
     */
    public function revokeSession(AuthenticatableInterface $user, string $sessionId): bool
    {
        return $this->database->table('user_sessions')
            ->where('user_id', $user->getAuthIdentifier())
            ->where('tenant_id', $this->currentTenant)
            ->where('id', $sessionId)
            ->delete() > 0;
    }
    
    /**
     * Revoke all user sessions except current
     */
    public function revokeOtherSessions(AuthenticatableInterface $user, string $currentSessionId = null): int
    {
        $query = $this->database->table('user_sessions')
            ->where('user_id', $user->getAuthIdentifier())
            ->where('tenant_id', $this->currentTenant);
        
        if ($currentSessionId) {
            $query->where('id', '!=', $currentSessionId);
        }
        
        return $query->delete();
    }
    
    /**
     * Get user provider
     */
    public function createUserProvider(string $provider = null): UserProviderInterface
    {
        $provider = $provider ?? $this->config['providers']['default'] ?? 'database';
        
        if (!isset($this->userProviders[$provider])) {
            $this->userProviders[$provider] = $this->createUserProviderInstance($provider);
        }
        
        return $this->userProviders[$provider];
    }
    
    /**
     * Create guard instance
     */
    private function createGuard(string $name): GuardInterface
    {
        $config = $this->config['guards'][$name] ?? [];
        $driver = $config['driver'] ?? $name;
        
        $provider = $this->createUserProvider($config['provider'] ?? null);
        
        return match ($driver) {
            'jwt' => new JwtGuard(
                $provider,
                $this->cache,
                $this->security,
                $config
            ),
            'session' => new SessionGuard(
                $provider,
                $this->cache,
                $config
            ),
            default => throw new \InvalidArgumentException("Guard driver [{$driver}] not supported")
        };
    }
    
    /**
     * Create user provider instance
     */
    private function createUserProviderInstance(string $provider): UserProviderInterface
    {
        $config = $this->config['providers'][$provider] ?? [];
        $driver = $config['driver'] ?? $provider;
        
        return match ($driver) {
            'database' => new DatabaseUserProvider(
                $this->database,
                $config['table'] ?? 'users',
                $config['model'] ?? null
            ),
            default => throw new \InvalidArgumentException("Provider driver [{$driver}] not supported")
        };
    }
    
    /**
     * Register default providers
     */
    private function registerDefaultProviders(): void
    {
        $this->config['providers'] = array_merge([
            'default' => 'database',
            'database' => [
                'driver' => 'database',
                'table' => 'users',
            ],
        ], $this->config['providers'] ?? []);
    }
    
    /**
     * Register default guards
     */
    private function registerDefaultGuards(): void
    {
        $this->config['guards'] = array_merge([
            'jwt' => [
                'driver' => 'jwt',
                'provider' => 'database',
            ],
            'session' => [
                'driver' => 'session',
                'provider' => 'database',
            ],
        ], $this->config['guards'] ?? []);
    }
    
    /**
     * Check rate limit for login attempts
     */
    private function checkRateLimit(string $email, ?string $tenantId): bool
    {
        $key = "login_attempts:{$tenantId}:{$email}";
        $attempts = (int) $this->cache->get($key, 0);
        
        return $attempts < ($this->config['rate_limit']['max_attempts'] ?? 5);
    }
    
    /**
     * Record failed login attempt
     */
    private function recordFailedAttempt(string $email, ?string $tenantId): void
    {
        $key = "login_attempts:{$tenantId}:{$email}";
        $attempts = (int) $this->cache->get($key, 0) + 1;
        $lockoutTime = $this->config['rate_limit']['lockout_minutes'] ?? 15;
        
        $this->cache->put($key, $attempts, $lockoutTime * 60);
        
        // Log the attempt
        $this->security->auditLog('failed_login', [
            'email' => $email,
            'tenant_id' => $tenantId,
            'attempts' => $attempts,
            'ip' => request()->getClientIp(),
            'user_agent' => request()->getUserAgent(),
        ]);
    }
    
    /**
     * Clear failed login attempts
     */
    private function clearFailedAttempts(string $email, ?string $tenantId): void
    {
        $key = "login_attempts:{$tenantId}:{$email}";
        $this->cache->forget($key);
    }
    
    /**
     * Record successful login
     */
    private function recordSuccessfulLogin(AuthenticatableInterface $user): void
    {
        $this->database->table('user_login_history')->insert([
            'user_id' => $user->getAuthIdentifier(),
            'tenant_id' => $this->currentTenant,
            'ip_address' => request()->getClientIp(),
            'user_agent' => request()->getUserAgent(),
            'login_at' => Carbon::now(),
        ]);
        
        $this->security->auditLog('successful_login', [
            'user_id' => $user->getAuthIdentifier(),
            'tenant_id' => $this->currentTenant,
        ]);
    }
    
    /**
     * Record logout
     */
    private function recordLogout(AuthenticatableInterface $user): void
    {
        $this->security->auditLog('logout', [
            'user_id' => $user->getAuthIdentifier(),
            'tenant_id' => $this->currentTenant,
        ]);
    }
    
    /**
     * Check if user belongs to tenant
     */
    private function userBelongsToTenant(AuthenticatableInterface $user, string $tenantId): bool
    {
        return $this->database->table('user_tenants')
            ->where('user_id', $user->getAuthIdentifier())
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->exists();
    }
    
    /**
     * Get user roles for tenant
     */
    private function getUserRoles(AuthenticatableInterface $user, ?string $tenantId): \Illuminate\Support\Collection
    {
        return $this->database->table('user_roles')
            ->join('roles', 'user_roles.role_id', '=', 'roles.id')
            ->where('user_roles.user_id', $user->getAuthIdentifier())
            ->where('user_roles.tenant_id', $tenantId)
            ->where('roles.active', true)
            ->pluck('roles.name');
    }
    
    /**
     * Get user permissions for tenant
     */
    private function getUserPermissions(AuthenticatableInterface $user, ?string $tenantId): \Illuminate\Support\Collection
    {
        $rolePermissions = $this->database->table('user_roles')
            ->join('role_permissions', 'user_roles.role_id', '=', 'role_permissions.role_id')
            ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
            ->where('user_roles.user_id', $user->getAuthIdentifier())
            ->where('user_roles.tenant_id', $tenantId)
            ->where('permissions.active', true)
            ->pluck('permissions.name');
        
        $directPermissions = $this->database->table('user_permissions')
            ->join('permissions', 'user_permissions.permission_id', '=', 'permissions.id')
            ->where('user_permissions.user_id', $user->getAuthIdentifier())
            ->where('user_permissions.tenant_id', $tenantId)
            ->where('permissions.active', true)
            ->pluck('permissions.name');
        
        return $rolePermissions->merge($directPermissions)->unique();
    }
    
    /**
     * Get two-factor data for user
     */
    private function getTwoFactorData(AuthenticatableInterface $user): ?array
    {
        $data = $this->database->table('user_two_factor')
            ->where('user_id', $user->getAuthIdentifier())
            ->where('tenant_id', $this->currentTenant)
            ->first();
        
        if ($data) {
            $data['secret'] = $this->security->decrypt($data['secret']);
        }
        
        return $data ? (array) $data : null;
    }
}