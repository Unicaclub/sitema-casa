<?php

namespace ERP\Core;

/**
 * Sistema de Autenticação Multi-Empresa
 * Suporta multi-tenancy, 2FA, SSO e auditoria completa
 */
class Auth 
{
    private $database;
    private $cache;
    private $config;
    private $currentUser = null;
    private $currentCompany = null;
    
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->database = App::getInstance()->get('database');
        $this->cache = App::getInstance()->get('cache');
    }
    
    /**
     * Autentica usuário
     */
    public function login(string $email, string $password, string $companyCode = null): array
    {
        // Log da tentativa de login
        $this->logSecurityEvent('login_attempt', [
            'email' => $email,
            'company_code' => $companyCode,
            'ip' => $_SERVER['REMOTE_ADDR'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT']
        ]);
        
        // Verifica rate limiting
        $this->checkRateLimit($email);
        
        // Busca usuário
        $user = $this->findUser($email, $companyCode);
        if (!$user) {
            $this->incrementFailedAttempts($email);
            throw new AuthException('Credenciais inválidas');
        }
        
        // Verifica senha
        if (!password_verify($password, $user['password'])) {
            $this->incrementFailedAttempts($email);
            throw new AuthException('Credenciais inválidas');
        }
        
        // Verifica status do usuário
        if (!$user['active']) {
            throw new AuthException('Usuário inativo');
        }
        
        // Verifica status da empresa
        if (!$user['company_active']) {
            throw new AuthException('Empresa inativa');
        }
        
        // Verifica 2FA se habilitado
        if ($user['two_factor_enabled']) {
            return $this->requireTwoFactor($user);
        }
        
        // Login bem-sucedido
        $this->resetFailedAttempts($email);
        return $this->completeLogin($user);
    }
    
    /**
     * Valida código 2FA
     */
    public function validateTwoFactor(string $userId, string $code): array
    {
        $user = $this->database->table('users')
            ->where('id', $userId)
            ->where('active', 1)
            ->first();
            
        if (!$user) {
            throw new AuthException('Usuário não encontrado');
        }
        
        // Verifica código TOTP
        if (!$this->verifyTOTP($user['two_factor_secret'], $code)) {
            $this->logSecurityEvent('2fa_failure', [
                'user_id' => $userId,
                'ip' => $_SERVER['REMOTE_ADDR']
            ]);
            throw new AuthException('Código 2FA inválido');
        }
        
        return $this->completeLogin($user);
    }
    
    /**
     * Completa processo de login
     */
    private function completeLogin(array $user): array
    {
        // Atualiza último login
        $this->database->update('users', [
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $_SERVER['REMOTE_ADDR']
        ], ['id' => $user['id']]);
        
        // Gera token de sessão
        $sessionData = [
            'user_id' => $user['id'],
            'company_id' => $user['company_id'],
            'permissions' => $this->getUserPermissions($user['id']),
            'expires_at' => time() + $this->config['session_lifetime']
        ];
        
        $token = $this->generateSecureToken();
        $this->cache->set("session:{$token}", $sessionData, $this->config['session_lifetime']);
        
        // Log login bem-sucedido
        $this->logSecurityEvent('login_success', [
            'user_id' => $user['id'],
            'company_id' => $user['company_id'],
            'ip' => $_SERVER['REMOTE_ADDR']
        ]);
        
        return [
            'token' => $token,
            'user' => $this->sanitizeUser($user),
            'permissions' => $sessionData['permissions'],
            'expires_at' => $sessionData['expires_at']
        ];
    }
    
    /**
     * Autentica via token
     */
    public function authenticateToken(string $token): bool
    {
        $sessionData = $this->cache->get("session:{$token}");
        
        if (!$sessionData || $sessionData['expires_at'] < time()) {
            return false;
        }
        
        // Carrega usuário atual
        $user = $this->database->table('users')
            ->select('users.*', 'companies.name as company_name', 'companies.active as company_active')
            ->join('companies', 'users.company_id', '=', 'companies.id')
            ->where('users.id', $sessionData['user_id'])
            ->where('users.active', 1)
            ->where('companies.active', 1)
            ->first();
            
        if (!$user) {
            $this->cache->delete("session:{$token}");
            return false;
        }
        
        $this->currentUser = $user;
        $this->currentCompany = [
            'id' => $user['company_id'],
            'name' => $user['company_name']
        ];
        
        // Renova sessão se próximo do vencimento
        if ($sessionData['expires_at'] - time() < 1800) { // 30 minutos
            $sessionData['expires_at'] = time() + $this->config['session_lifetime'];
            $this->cache->set("session:{$token}", $sessionData, $this->config['session_lifetime']);
        }
        
        return true;
    }
    
    /**
     * Logout
     */
    public function logout(string $token): void
    {
        $sessionData = $this->cache->get("session:{$token}");
        
        if ($sessionData) {
            $this->logSecurityEvent('logout', [
                'user_id' => $sessionData['user_id'],
                'company_id' => $sessionData['company_id'],
                'ip' => $_SERVER['REMOTE_ADDR']
            ]);
        }
        
        $this->cache->delete("session:{$token}");
        $this->currentUser = null;
        $this->currentCompany = null;
    }
    
    /**
     * Busca usuário por email e empresa
     */
    private function findUser(string $email, string $companyCode = null): ?array
    {
        $query = $this->database->table('users')
            ->select('users.*', 'companies.name as company_name', 'companies.active as company_active')
            ->join('companies', 'users.company_id', '=', 'companies.id')
            ->where('users.email', $email);
            
        if ($companyCode) {
            $query->where('companies.code', $companyCode);
        }
        
        return $query->first();
    }
    
    /**
     * Obtém permissões do usuário
     */
    private function getUserPermissions(int $userId): array
    {
        $permissions = $this->database->select("
            SELECT DISTINCT p.name, p.module 
            FROM permissions p
            INNER JOIN role_permissions rp ON p.id = rp.permission_id
            INNER JOIN user_roles ur ON rp.role_id = ur.role_id
            WHERE ur.user_id = ?
        ", [$userId]);
        
        $organized = [];
        foreach ($permissions as $permission) {
            $organized[$permission['module']][] = $permission['name'];
        }
        
        return $organized;
    }
    
    /**
     * Verifica se usuário tem permissão
     */
    public function hasPermission(string $module, string $permission): bool
    {
        if (!$this->currentUser) {
            return false;
        }
        
        $permissions = $this->getUserPermissions($this->currentUser['id']);
        return in_array($permission, $permissions[$module] ?? []);
    }
    
    /**
     * Verifica rate limiting
     */
    private function checkRateLimit(string $email): void
    {
        $key = "login_attempts:{$email}";
        $attempts = $this->cache->get($key) ?? 0;
        
        if ($attempts >= $this->config['max_login_attempts']) {
            throw new AuthException('Muitas tentativas de login. Tente novamente em alguns minutos.');
        }
    }
    
    /**
     * Incrementa tentativas falhadas
     */
    private function incrementFailedAttempts(string $email): void
    {
        $key = "login_attempts:{$email}";
        $attempts = $this->cache->get($key) ?? 0;
        $this->cache->set($key, $attempts + 1, 900); // 15 minutos
    }
    
    /**
     * Reseta tentativas falhadas
     */
    private function resetFailedAttempts(string $email): void
    {
        $this->cache->delete("login_attempts:{$email}");
    }
    
    /**
     * Gera token seguro
     */
    private function generateSecureToken(): string
    {
        return bin2hex(random_bytes(32));
    }
    
    /**
     * Verifica TOTP (Google Authenticator)
     */
    private function verifyTOTP(string $secret, string $code): bool
    {
        // Implementação simplificada do TOTP
        $timeSlice = floor(time() / 30);
        
        // Verifica código atual e adjacentes (±1 período para compensar drift)
        for ($i = -1; $i <= 1; $i++) {
            $calculatedCode = $this->calculateTOTP($secret, $timeSlice + $i);
            if (hash_equals($calculatedCode, $code)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Calcula código TOTP
     */
    private function calculateTOTP(string $secret, int $timeSlice): string
    {
        $secretKey = base32_decode($secret);
        $time = pack('N*', 0) . pack('N*', $timeSlice);
        $hash = hash_hmac('sha1', $time, $secretKey, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $hashpart = substr($hash, $offset, 4);
        $value = unpack('N', $hashpart)[1] & 0x7FFFFFFF;
        return str_pad($value % 1000000, 6, '0', STR_PAD_LEFT);
    }
    
    /**
     * Requer segunda etapa (2FA)
     */
    private function requireTwoFactor(array $user): array
    {
        $tempToken = $this->generateSecureToken();
        $this->cache->set("2fa_pending:{$tempToken}", [
            'user_id' => $user['id'],
            'expires_at' => time() + 300 // 5 minutos
        ], 300);
        
        return [
            'requires_2fa' => true,
            'temp_token' => $tempToken
        ];
    }
    
    /**
     * Remove dados sensíveis do usuário
     */
    private function sanitizeUser(array $user): array
    {
        unset($user['password'], $user['two_factor_secret']);
        return $user;
    }
    
    /**
     * Log de eventos de segurança
     */
    private function logSecurityEvent(string $event, array $data): void
    {
        $this->database->insert('security_logs', [
            'event' => $event,
            'data' => json_encode($data),
            'ip_address' => $_SERVER['REMOTE_ADDR'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'],
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Obtém usuário atual
     */
    public function user(): ?array
    {
        return $this->currentUser;
    }
    
    /**
     * Obtém empresa atual
     */
    public function company(): ?array
    {
        return $this->currentCompany;
    }
    
    /**
     * Verifica se está autenticado
     */
    public function check(): bool
    {
        return $this->currentUser !== null;
    }
    
    /**
     * Obtém ID do usuário atual
     */
    public function id(): ?int
    {
        return $this->currentUser['id'] ?? null;
    }
    
    /**
     * Obtém ID da empresa atual
     */
    public function companyId(): ?int
    {
        return $this->currentCompany['id'] ?? null;
    }
}

/**
 * Exceção de autenticação
 */
class AuthException extends \Exception {}

/**
 * Middleware de autenticação
 */
class AuthMiddleware 
{
    public function handle(Request $request, \Closure $next): Response
    {
        $auth = App::getInstance()->get('auth');
        
        // Rotas públicas
        $publicRoutes = ['/api/v1/auth/login', '/api/v1/auth/register'];
        if (in_array($request->path(), $publicRoutes)) {
            return $next($request);
        }
        
        // Verifica token
        $token = $request->header('authorization');
        if (!$token || !str_starts_with($token, 'Bearer ')) {
            return new Response(['error' => 'Token não fornecido'], 401);
        }
        
        $token = substr($token, 7);
        if (!$auth->authenticateToken($token)) {
            return new Response(['error' => 'Token inválido ou expirado'], 401);
        }
        
        return $next($request);
    }
}

/**
 * Middleware de permissões
 */
class PermissionMiddleware 
{
    private $module;
    private $permission;
    
    public function __construct(string $module, string $permission)
    {
        $this->module = $module;
        $this->permission = $permission;
    }
    
    public function handle(Request $request, \Closure $next): Response
    {
        $auth = App::getInstance()->get('auth');
        
        if (!$auth->hasPermission($this->module, $this->permission)) {
            return new Response(['error' => 'Acesso negado'], 403);
        }
        
        return $next($request);
    }
}
