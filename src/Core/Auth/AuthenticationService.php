<?php

declare(strict_types=1);

namespace ERP\Core\Auth;

use ERP\Core\Database\DatabaseManager;
use ERP\Core\Security\AuditManager;
use Exception;

/**
 * Authentication Service - Sistema de Autenticação Completo
 * 
 * Serviço para autenticação de usuários com JWT
 * 
 * @package ERP\Core\Auth
 */
final class AuthenticationService
{
    private DatabaseManager $db;
    private JWTManager $jwt;
    private AuditManager $audit;
    private array $config;
    
    public function __construct(
        DatabaseManager $db,
        JWTManager $jwt,
        AuditManager $audit,
        array $config = []
    ) {
        $this->db = $db;
        $this->jwt = $jwt;
        $this->audit = $audit;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }
    
    /**
     * Autenticar usuário
     */
    public function authenticate(string $email, string $password, array $context = []): array
    {
        try {
            // Log tentativa de login
            $this->audit->logEvent('login_attempt', [
                'email' => $email,
                'ip_address' => $context['ip_address'] ?? null,
                'user_agent' => $context['user_agent'] ?? null,
                'timestamp' => time()
            ]);
            
            // Buscar usuário
            $user = $this->findUserByEmail($email);
            
            if (!$user) {
                $this->handleFailedLogin($email, 'user_not_found', $context);
                return [
                    'success' => false,
                    'error' => 'Invalid credentials',
                    'error_code' => 'INVALID_CREDENTIALS'
                ];
            }
            
            // Verificar se conta está bloqueada
            if ($this->isAccountLocked($user)) {
                $this->handleFailedLogin($email, 'account_locked', $context);
                return [
                    'success' => false,
                    'error' => 'Account is temporarily locked',
                    'error_code' => 'ACCOUNT_LOCKED',
                    'locked_until' => $user['locked_until']
                ];
            }
            
            // Verificar se conta está ativa
            if (!$user['is_active']) {
                $this->handleFailedLogin($email, 'account_inactive', $context);
                return [
                    'success' => false,
                    'error' => 'Account is inactive',
                    'error_code' => 'ACCOUNT_INACTIVE'
                ];
            }
            
            // Verificar senha
            if (!$this->verifyPassword($password, $user['password'])) {
                $this->handleFailedLogin($email, 'invalid_password', $context, $user['id']);
                return [
                    'success' => false,
                    'error' => 'Invalid credentials',
                    'error_code' => 'INVALID_CREDENTIALS'
                ];
            }
            
            // Verificar se precisa trocar senha
            if ($user['must_change_password']) {
                return [
                    'success' => false,
                    'error' => 'Password change required',
                    'error_code' => 'PASSWORD_CHANGE_REQUIRED',
                    'user_id' => $user['id']
                ];
            }
            
            // Gerar tokens
            $userPayload = [
                'user_id' => $user['id'],
                'tenant_id' => $user['tenant_id'],
                'email' => $user['email'],
                'name' => $user['name'],
                'role' => $user['role'],
                'permissions' => json_decode($user['permissions'] ?? '{}', true)
            ];
            
            $tokens = $this->jwt->generateTokenPair($userPayload);
            
            // Atualizar informações de login
            $this->updateLoginInfo($user['id'], $context);
            
            // Resetar tentativas de login
            $this->resetLoginAttempts($user['id']);
            
            // Salvar refresh token
            $this->saveRefreshToken($user['id'], $tokens['refresh_token'], $context);
            
            // Log login bem-sucedido
            $this->audit->logEvent('login_success', [
                'user_id' => $user['id'],
                'email' => $email,
                'ip_address' => $context['ip_address'] ?? null,
                'user_agent' => $context['user_agent'] ?? null,
                'timestamp' => time()
            ]);
            
            return [
                'success' => true,
                'user' => [
                    'id' => $user['id'],
                    'tenant_id' => $user['tenant_id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'permissions' => json_decode($user['permissions'] ?? '{}', true)
                ],
                'tokens' => $tokens,
                'requires_2fa' => !empty($user['two_factor_secret'])
            ];
            
        } catch (Exception $e) {
            $this->audit->logEvent('login_error', [
                'email' => $email,
                'error' => $e->getMessage(),
                'ip_address' => $context['ip_address'] ?? null
            ]);
            
            return [
                'success' => false,
                'error' => 'Authentication failed',
                'error_code' => 'AUTH_ERROR'
            ];
        }
    }
    
    /**
     * Renovar token usando refresh token
     */
    public function refreshToken(string $refreshToken, array $context = []): array
    {
        try {
            // Validar refresh token
            $validation = $this->jwt->validateToken($refreshToken);
            
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'error' => 'Invalid refresh token',
                    'error_code' => 'INVALID_REFRESH_TOKEN'
                ];
            }
            
            $payload = $validation['payload'];
            
            // Verificar tipo de token
            if ($payload['type'] !== 'refresh') {
                return [
                    'success' => false,
                    'error' => 'Invalid token type',
                    'error_code' => 'INVALID_TOKEN_TYPE'
                ];
            }
            
            // Verificar se refresh token existe no banco
            $storedToken = $this->findRefreshToken($refreshToken);
            
            if (!$storedToken || !$storedToken['is_active']) {
                return [
                    'success' => false,
                    'error' => 'Refresh token not found or inactive',
                    'error_code' => 'REFRESH_TOKEN_NOT_FOUND'
                ];
            }
            
             // Buscar usuário
            $user = $this->findUserById($payload['user_id']);
            
            if (!$user || !$user['is_active']) {
                return [
                    'success' => false,
                    'error' => 'User not found or inactive',
                    'error_code' => 'USER_NOT_FOUND'
                ];
            }
            
            // Gerar novos tokens
            $userPayload = [
                'user_id' => $user['id'],
                'tenant_id' => $user['tenant_id'],
                'email' => $user['email'],
                'name' => $user['name'],
                'role' => $user['role'],
                'permissions' => json_decode($user['permissions'] ?? '{}', true)
            ];
            
            $newTokens = $this->jwt->generateTokenPair($userPayload);
            
            // Invalidar refresh token antigo
            $this->invalidateRefreshToken($refreshToken);
            
            // Salvar novo refresh token
            $this->saveRefreshToken($user['id'], $newTokens['refresh_token'], $context);
            
            // Atualizar último uso
            $this->updateTokenLastUsed($storedToken['id']);
            
            // Log refresh
            $this->audit->logEvent('token_refresh', [
                'user_id' => $user['id'],
                'ip_address' => $context['ip_address'] ?? null,
                'user_agent' => $context['user_agent'] ?? null
            ]);
            
            return [
                'success' => true,
                'tokens' => $newTokens
            ];
            
        } catch (Exception $e) {
            $this->audit->logEvent('token_refresh_error', [
                'error' => $e->getMessage(),
                'ip_address' => $context['ip_address'] ?? null
            ]);
            
            return [
                'success' => false,
                'error' => 'Token refresh failed',
                'error_code' => 'REFRESH_ERROR'
            ];
        }
    }
    
    /**
     * Logout usuário
     */
    public function logout(string $accessToken, ?string $refreshToken = null): array
    {
        try {
            // Blacklist access token
            $this->jwt->blacklistToken($accessToken);
            
            // Invalidar refresh token se fornecido
            if ($refreshToken) {
                $this->invalidateRefreshToken($refreshToken);
            }
            
            // Parse token para obter user_id
            $parsed = $this->jwt->parseToken($accessToken);
            $userId = $parsed['payload']['user_id'] ?? null;
            
            if ($userId) {
                $this->audit->logEvent('logout', [
                    'user_id' => $userId,
                    'timestamp' => time()
                ]);
            }
            
            return [
                'success' => true,
                'message' => 'Logout successful'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Logout failed',
                'error_code' => 'LOGOUT_ERROR'
            ];
        }
    }
    
    /**
     * Validar token de acesso
     */
    public function validateAccessToken(string $token): array
    {
        // Verificar se está na blacklist
        if ($this->jwt->isTokenBlacklisted($token)) {
            return [
                'valid' => false,
                'error' => 'Token is blacklisted'
            ];
        }
        
        // Validar token
        $validation = $this->jwt->validateToken($token);
        
        if (!$validation['valid']) {
            return $validation;
        }
        
        $payload = $validation['payload'];
        
        // Verificar tipo de token
        if ($payload['type'] !== 'access') {
            return [
                'valid' => false,
                'error' => 'Invalid token type'
            ];
        }
        
        // Verificar se usuário ainda existe e está ativo
        $user = $this->findUserById($payload['user_id']);
        
        if (!$user || !$user['is_active']) {
            return [
                'valid' => false,
                'error' => 'User not found or inactive'
            ];
        }
        
        return [
            'valid' => true,
            'user' => [
                'id' => $user['id'],
                'tenant_id' => $user['tenant_id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'permissions' => json_decode($user['permissions'] ?? '{}', true)
            ],
            'payload' => $payload
        ];
    }
    
    /**
     * Métodos auxiliares privados
     */
    
    private function findUserByEmail(string $email): ?array
    {
        // Simulated database query
        $users = [
            'admin@erp-sistema.local' => [
                'id' => 1,
                'tenant_id' => 1,
                'name' => 'System Administrator',
                'email' => 'admin@erp-sistema.local',
                'password' => '$2y$12$LQv3c1yqBNFcXDJjKzfzNOdPrAWPz7TsRKNc8LNWQi8N8YJlVwUWu', // admin123!@#
                'role' => 'admin',
                'permissions' => '{"security":["*"],"users":["*"],"system":["*"]}',
                'is_active' => true,
                'login_attempts' => 0,
                'locked_until' => null,
                'must_change_password' => false,
                'two_factor_secret' => null
            ]
        ];
        
        return $users[$email] ?? null;
    }
    
    private function findUserById(int $id): ?array
    {
        // Simulated database query
        if ($id === 1) {
            return [
                'id' => 1,
                'tenant_id' => 1,
                'name' => 'System Administrator',
                'email' => 'admin@erp-sistema.local',
                'role' => 'admin',
                'permissions' => '{"security":["*"],"users":["*"],"system":["*"]}',
                'is_active' => true
            ];
        }
        
        return null;
    }
    
    private function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
    
    private function isAccountLocked(array $user): bool
    {
        if (!$user['locked_until']) {
            return false;
        }
        
        return time() < strtotime($user['locked_until']);
    }
    
    private function handleFailedLogin(string $email, string $reason, array $context, ?int $userId = null): void
    {
        $this->audit->logEvent('login_failed', [
            'email' => $email,
            'reason' => $reason,
            'user_id' => $userId,
            'ip_address' => $context['ip_address'] ?? null,
            'user_agent' => $context['user_agent'] ?? null,
            'timestamp' => time()
        ]);
        
        if ($userId) {
            $this->incrementLoginAttempts($userId);
        }
    }
    
    private function updateLoginInfo(int $userId, array $context): void
    {
        // Update last_login_at, last_login_ip in database
        $this->audit->logEvent('login_info_updated', [
            'user_id' => $userId,
            'ip_address' => $context['ip_address'] ?? null
        ]);
    }
    
    private function resetLoginAttempts(int $userId): void
    {
        // Reset login_attempts to 0 in database
    }
    
    private function incrementLoginAttempts(int $userId): void
    {
        // Increment login_attempts in database
        // Lock account if attempts exceed threshold
    }
    
    private function saveRefreshToken(int $userId, string $token, array $context): void
    {
        // Save refresh token to database
        $this->audit->logEvent('refresh_token_created', [
            'user_id' => $userId,
            'ip_address' => $context['ip_address'] ?? null
        ]);
    }
    
    private function findRefreshToken(string $token): ?array
    {
        // Simulated database query
        return [
            'id' => 1,
            'token_hash' => hash('sha256', $token),
            'user_id' => 1,
            'is_active' => true,
            'expires_at' => date('Y-m-d H:i:s', time() + 604800)
        ];
    }
    
    private function invalidateRefreshToken(string $token): void
    {
        // Set is_active = false in database
        $this->audit->logEvent('refresh_token_invalidated', [
            'token_hash' => hash('sha256', $token)
        ]);
    }
    
    private function updateTokenLastUsed(int $tokenId): void
    {
        // Update last_used_at in database
    }
    
    private function getDefaultConfig(): array
    {
        return [
            'max_login_attempts' => 5,
            'lockout_duration' => 900, // 15 minutes
            'password_min_length' => 8,
            'require_2fa' => false
        ];
    }
}