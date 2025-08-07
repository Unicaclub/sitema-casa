<?php

namespace ERP\Core\Security;

use ERP\Core\Logger;
use ERP\Core\Cache;

/**
 * Gerenciador de Segurança Avançado
 */
class SecurityManager
{
    private Logger $logger;
    private Cache $cache;
    private array $config;
    
    public function __construct(Logger $logger, Cache $cache, array $config = [])
    {
        $this->logger = $logger;
        $this->cache = $cache;
        $this->config = array_merge([
            'max_login_attempts' => 5,
            'lockout_duration' => 900, // 15 minutos
            'session_timeout' => 3600,  // 1 hora
            'csrf_token_lifetime' => 1800, // 30 minutos
            'password_min_length' => 8,
            'require_2fa' => false,
            'ip_whitelist' => [],
            'rate_limit_requests' => 100,
            'rate_limit_window' => 300 // 5 minutos
        ], $config);
    }
    
    /**
     * Valida tentativa de login com proteção contra força bruta
     */
    public function validateLoginAttempt(string $email, string $ip): bool
    {
        $emailKey = "login_attempts:email:" . md5($email);
        $ipKey = "login_attempts:ip:" . md5($ip);
        
        $emailAttempts = (int) $this->cache->get($emailKey, 0);
        $ipAttempts = (int) $this->cache->get($ipKey, 0);
        
        if ($emailAttempts >= $this->config['max_login_attempts']) {
            $this->logger->warning('Login blocked - too many attempts for email', [
                'email' => $email,
                'ip' => $ip,
                'attempts' => $emailAttempts
            ]);
            return false;
        }
        
        if ($ipAttempts >= $this->config['max_login_attempts'] * 3) {
            $this->logger->warning('Login blocked - too many attempts from IP', [
                'email' => $email,
                'ip' => $ip,
                'attempts' => $ipAttempts
            ]);
            return false;
        }
        
        return true;
    }
    
    /**
     * Registra tentativa de login falhada
     */
    public function recordFailedLogin(string $email, string $ip): void
    {
        $emailKey = "login_attempts:email:" . md5($email);
        $ipKey = "login_attempts:ip:" . md5($ip);
        
        $emailAttempts = (int) $this->cache->get($emailKey, 0) + 1;
        $ipAttempts = (int) $this->cache->get($ipKey, 0) + 1;
        
        $this->cache->set($emailKey, $emailAttempts, $this->config['lockout_duration']);
        $this->cache->set($ipKey, $ipAttempts, $this->config['lockout_duration']);
        
        $this->logger->warning('Failed login attempt', [
            'email' => $email,
            'ip' => $ip,
            'email_attempts' => $emailAttempts,
            'ip_attempts' => $ipAttempts
        ]);
        
        // Alerta após múltiplas tentativas
        if ($emailAttempts >= 3 || $ipAttempts >= 10) {
            $this->sendSecurityAlert('Múltiplas tentativas de login falharam', [
                'email' => $email,
                'ip' => $ip,
                'email_attempts' => $emailAttempts,
                'ip_attempts' => $ipAttempts
            ]);
        }
    }
    
    /**
     * Limpa tentativas de login após sucesso
     */
    public function clearLoginAttempts(string $email, string $ip): void
    {
        $emailKey = "login_attempts:email:" . md5($email);
        $ipKey = "login_attempts:ip:" . md5($ip);
        
        $this->cache->delete($emailKey);
        // Manter contador de IP mas reduzir
        $ipAttempts = max(0, (int) $this->cache->get($ipKey, 0) - 2);
        if ($ipAttempts > 0) {
            $this->cache->set($ipKey, $ipAttempts, $this->config['lockout_duration']);
        } else {
            $this->cache->delete($ipKey);
        }
    }
    
    /**
     * Valida força da senha
     */
    public function validatePasswordStrength(string $password): array
    {
        $errors = [];
        
        if (strlen($password) < $this->config['password_min_length']) {
            $errors[] = "Senha deve ter pelo menos {$this->config['password_min_length']} caracteres";
        }
        
        if (! preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Senha deve conter pelo menos uma letra maiúscula';
        }
        
        if (! preg_match('/[a-z]/', $password)) {
            $errors[] = 'Senha deve conter pelo menos uma letra minúscula';
        }
        
        if (! preg_match('/[0-9]/', $password)) {
            $errors[] = 'Senha deve conter pelo menos um número';
        }
        
        if (! preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'Senha deve conter pelo menos um caractere especial';
        }
        
        // Verificar senhas comuns
        if ($this->isCommonPassword($password)) {
            $errors[] = 'Senha muito comum, escolha uma mais segura';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'score' => $this->calculatePasswordScore($password)
        ];
    }
    
    /**
     * Gera token CSRF seguro
     */
    public function generateCSRFToken(string $sessionId): string
    {
        $token = bin2hex(random_bytes(32));
        $key = "csrf_token:{$sessionId}";
        
        $this->cache->set($key, $token, $this->config['csrf_token_lifetime']);
        
        return $token;
    }
    
    /**
     * Valida token CSRF
     */
    public function validateCSRFToken(string $sessionId, string $token): bool
    {
        $key = "csrf_token:{$sessionId}";
        $stored = $this->cache->get($key);
        
        if (! $stored || ! hash_equals($stored, $token)) {
            $this->logger->warning('Invalid CSRF token', [
                'session_id' => $sessionId,
                'provided_token' => substr($token, 0, 8) . '...'
            ]);
            return false;
        }
        
        return true;
    }
    
    /**
     * Sanitiza entrada do usuário
     */
    public function sanitizeInput(mixed $input): mixed
    {
        if (is_string($input)) {
            // Remove caracteres de controle
            $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $input);
            
            // Sanitiza HTML
            $input = htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            
            return trim($input);
        }
        
        if (is_array($input)) {
            return array_map([$this, 'sanitizeInput'], $input);
        }
        
        return $input;
    }
    
    /**
     * Valida e sanitiza SQL para prevenir injection
     */
    public function validateSQLInput(string $input): bool
    {
        $dangerous = [
            'union', 'select', 'insert', 'update', 'delete', 'drop', 
            'create', 'alter', 'exec', 'execute', 'script', '--', 
            '/*', '*/', 'xp_', 'sp_'
        ];
        
        $input = strtolower($input);
        
        foreach ($dangerous as $keyword) {
            if (str_contains($input, $keyword)) {
                $this->logger->critical('SQL injection attempt detected', [
                    'input' => $input,
                    'keyword' => $keyword,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Rate limiting por IP
     */
    public function checkRateLimit(string $ip, string $action = 'general'): bool
    {
        $key = "rate_limit:{$action}:" . md5($ip);
        $current = (int) $this->cache->get($key, 0);
        
        if ($current >= $this->config['rate_limit_requests']) {
            $this->logger->warning('Rate limit exceeded', [
                'ip' => $ip,
                'action' => $action,
                'requests' => $current
            ]);
            return false;
        }
        
        $this->cache->set($key, $current + 1, $this->config['rate_limit_window']);
        return true;
    }
    
    /**
     * Verifica se IP está na whitelist
     */
    public function isIPWhitelisted(string $ip): bool
    {
        if (empty($this->config['ip_whitelist'])) {
            return true;
        }
        
        foreach ($this->config['ip_whitelist'] as $allowed) {
            if ($this->ipInRange($ip, $allowed)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Criptografa dados sensíveis
     */
    public function encrypt(string $data): string
    {
        $key = $this->getEncryptionKey();
        $iv = random_bytes(16);
        
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
        
        return base64_encode($iv . $encrypted);
    }
    
    /**
     * Descriptografa dados
     */
    public function decrypt(string $encryptedData): string
    {
        $key = $this->getEncryptionKey();
        $data = base64_decode($encryptedData);
        
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        
        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
    }
    
    /**
     * Gera hash seguro para senhas
     */
    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536, // 64 MB
            'time_cost' => 4,       // 4 iterações
            'threads' => 3          // 3 threads
        ]);
    }
    
    /**
     * Verifica hash de senha
     */
    public function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
    
    /**
     * Auditoria de segurança - registra evento
     */
    public function auditLog(string $action, array $context = []): void
    {
        $auditData = [
            'action' => $action,
            'user_id' => $context['user_id'] ?? null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'timestamp' => time(),
            'context' => $context
        ];
        
        $this->logger->info('Security audit', $auditData);
        
        // Armazenar em cache para relatórios rápidos
        $key = 'security_audit:' . date('Y-m-d-H');
        $logs = $this->cache->get($key, []);
        $logs[] = $auditData;
        $this->cache->set($key, $logs, 3600);
    }
    
    /**
     * Verifica se senha está na lista de senhas comuns
     */
    private function isCommonPassword(string $password): bool
    {
        $common = [
            'password', '123456', '123456789', 'qwerty', 'abc123',
            'password123', 'admin', 'letmein', 'welcome', 'monkey',
            '1234567890', 'password1', '123123', 'qwerty123'
        ];
        
        return in_array(strtolower($password), $common);
    }
    
    /**
     * Calcula score da senha (0-100)
     */
    private function calculatePasswordScore(string $password): int
    {
        $score = 0;
        $length = strlen($password);
        
        // Pontos por comprimento
        $score += min($length * 4, 25);
        
        // Pontos por complexidade
        if (preg_match('/[a-z]/', $password)) $score += 5;
        if (preg_match('/[A-Z]/', $password)) $score += 5;
        if (preg_match('/[0-9]/', $password)) $score += 10;
        if (preg_match('/[^A-Za-z0-9]/', $password)) $score += 15;
        
        // Pontos por variedade
        $unique = count(array_unique(str_split($password)));
        $score += min($unique * 2, 20);
        
        // Penalidade por padrões
        if (preg_match('/(.)\1{2,}/', $password)) $score -= 10; // Caracteres repetidos
        if (preg_match('/123|abc|qwe/i', $password)) $score -= 10; // Sequências
        
        return max(0, min(100, $score));
    }
    
    /**
     * Verifica se IP está em range (suporte CIDR)
     */
    private function ipInRange(string $ip, string $range): bool
    {
        if (str_contains($range, '/')) {
            list($subnet, $mask) = explode('/', $range);
            return (ip2long($ip) & ~((1 << (32 - $mask)) - 1)) === ip2long($subnet);
        }
        
        return $ip === $range;
    }
    
    /**
     * Obtém chave de criptografia do ambiente
     */
    private function getEncryptionKey(): string
    {
        $key = $_ENV['ENCRYPTION_KEY'] ?? 'default-key-change-in-production';
        return hash('sha256', $key, true);
    }
    
    /**
     * Envia alerta de segurança
     */
    private function sendSecurityAlert(string $message, array $context): void
    {
        // Implementar notificação (email, Slack, etc.)
        $this->logger->critical('Security Alert: ' . $message, $context);
        
        // Evitar spam de alertas
        $alertKey = 'security_alert:' . md5($message . serialize($context));
        if (! $this->cache->get($alertKey)) {
            $this->cache->set($alertKey, true, 300); // 5 minutos
            
            // Aqui enviaria email/notificação real
            // mail(), Slack webhook, etc.
        }
    }
}
