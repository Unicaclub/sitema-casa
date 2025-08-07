<?php

namespace ERP\Core\Security\Middleware;

use ERP\Core\Security\SecurityManager;
use ERP\Core\Logger;

/**
 * Middleware de Segurança Avançado
 */
class SecurityMiddleware
{
    private SecurityManager $security;
    private Logger $logger;
    
    public function __construct(SecurityManager $security, Logger $logger)
    {
        $this->security = $security;
        $this->logger = $logger;
    }
    
    /**
     * Processa request aplicando validações de segurança
     */
    public function handle($request, \Closure $next)
    {
        $ip = $this->getClientIP();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        // 1. Verificar IP whitelist
        if (! $this->security->isIPWhitelisted($ip)) {
            $this->logger->warning('Access denied - IP not whitelisted', ['ip' => $ip]);
            return $this->blockRequest('Access denied', 403);
        }
        
        // 2. Rate limiting
        if (! $this->security->checkRateLimit($ip, 'api')) {
            return $this->blockRequest('Rate limit exceeded', 429);
        }
        
        // 3. Detectar bots maliciosos
        if ($this->isMaliciousBot($userAgent)) {
            $this->logger->warning('Malicious bot detected', [
                'ip' => $ip,
                'user_agent' => $userAgent
            ]);
            return $this->blockRequest('Access denied', 403);
        }
        
        // 4. Validar headers de segurança
        if (! $this->validateSecurityHeaders()) {
            return $this->blockRequest('Invalid security headers', 400);
        }
        
        // 5. Sanitizar input
        $this->sanitizeRequestData($request);
        
        // 6. Validar CSRF para requests POST/PUT/DELETE
        if (in_array($request->getMethod(), ['POST', 'PUT', 'DELETE', 'PATCH'])) {
            if (! $this->validateCSRF($request)) {
                return $this->blockRequest('CSRF token invalid', 403);
            }
        }
        
        // 7. Detectar tentativas de SQL injection
        if (! $this->validateSQLInjection($request)) {
            return $this->blockRequest('Security violation detected', 403);
        }
        
        // 8. Detectar tentativas de XSS
        if (! $this->validateXSS($request)) {
            return $this->blockRequest('XSS attempt detected', 403);
        }
        
        // 9. Validar tamanho da requisição
        if (! $this->validateRequestSize($request)) {
            return $this->blockRequest('Request too large', 413);
        }
        
        // 10. Log da requisição para auditoria
        $this->security->auditLog('api_request', [
            'method' => $request->getMethod(),
            'uri' => $request->getUri(),
            'ip' => $ip,
            'user_agent' => $userAgent
        ]);
        
        // Adicionar headers de segurança na resposta
        $response = $next($request);
        return $this->addSecurityHeaders($response);
    }
    
    /**
     * Obtém IP real do cliente (considerando proxies)
     */
    private function getClientIP(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // Proxy padrão
            'HTTP_X_FORWARDED',          // Proxy
            'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster
            'HTTP_FORWARDED_FOR',        // Proxy
            'HTTP_FORWARDED',            // Proxy
            'REMOTE_ADDR'                // IP direto
        ];
        
        foreach ($headers as $header) {
            if (! empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * Detecta bots maliciosos baseado no User-Agent
     */
    private function isMaliciousBot(string $userAgent): bool
    {
        $maliciousPatterns = [
            'sqlmap', 'nikto', 'nmap', 'masscan', 'zmap',
            'acunetix', 'nessus', 'openvas', 'w3af',
            'havij', 'pangolin', 'jsql', 'bsqlbf',
            'sql power injector', 'marathon tool',
            'libwww-perl', 'python-urllib', 'python-requests',
            'curl', 'wget', 'httpclient', 'java/',
            'go-http-client', 'scrapy', 'spider', 'crawler'
        ];
        
        $userAgent = strtolower($userAgent);
        
        foreach ($maliciousPatterns as $pattern) {
            if (str_contains($userAgent, $pattern)) {
                return true;
            }
        }
        
        // Detectar User-Agents muito curtos ou suspeitos
        if (strlen($userAgent) < 10 || empty($userAgent)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Valida headers de segurança obrigatórios
     */
    private function validateSecurityHeaders(): bool
    {
        // Para requests HTTPS, validar se não há downgrade
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && 
                $_SERVER['HTTP_X_FORWARDED_PROTO'] !== 'https') {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Sanitiza dados da requisição
     */
    private function sanitizeRequestData($request): void
    {
        if (method_exists($request, 'all')) {
            $data = $request->all();
            $sanitized = $this->security->sanitizeInput($data);
            
            // Atualizar dados da requisição com versão sanitizada
            foreach ($sanitized as $key => $value) {
                $request->merge([$key => $value]);
            }
        }
    }
    
    /**
     * Valida token CSRF
     */
    private function validateCSRF($request): bool
    {
        $sessionId = session_id();
        $token = $request->header('X-CSRF-TOKEN') ?? 
                $request->input('_token') ?? 
                $request->header('X-XSRF-TOKEN');
        
        if (! $token || ! $sessionId) {
            return false;
        }
        
        return $this->security->validateCSRFToken($sessionId, $token);
    }
    
    /**
     * Detecta tentativas de SQL injection
     */
    private function validateSQLInjection($request): bool
    {
        $data = [];
        
        if (method_exists($request, 'all')) {
            $data = array_merge($data, $request->all());
        }
        
        // Verificar query string
        if (! empty($_SERVER['QUERY_STRING'])) {
            $data['_query'] = $_SERVER['QUERY_STRING'];
        }
        
        // Verificar headers suspeitos
        $suspiciousHeaders = ['X-Forwarded-For', 'User-Agent', 'Referer'];
        foreach ($suspiciousHeaders as $header) {
            if (isset($_SERVER['HTTP_' . str_replace('-', '_', strtoupper($header))])) {
                $data['_header_' . $header] = $_SERVER['HTTP_' . str_replace('-', '_', strtoupper($header))];
            }
        }
        
        // Validar cada campo
        foreach ($data as $key => $value) {
            if (is_string($value) && !$this->security->validateSQLInput($value)) {
                $this->security->auditLog('sql_injection_attempt', [
                    'field' => $key,
                    'value' => substr($value, 0, 100),
                    'ip' => $this->getClientIP()
                ]);
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Detecta tentativas de XSS
     */
    private function validateXSS($request): bool
    {
        $xssPatterns = [
            '/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi',
            '/<iframe\b[^<]*(?:(?!<\/iframe>)<[^<]*)*<\/iframe>/mi',
            '/javascript:/i',
            '/vbscript:/i',
            '/onload\s*=/i',
            '/onerror\s*=/i',
            '/onclick\s*=/i',
            '/onmouseover\s*=/i',
            '/<img[^>]+src[^>]*>/i',
            '/eval\s*\(/i',
            '/document\.cookie/i',
            '/document\.write/i'
        ];
        
        $data = method_exists($request, 'all') ? $request->all() : [];
        
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                foreach ($xssPatterns as $pattern) {
                    if (preg_match($pattern, $value)) {
                        $this->security->auditLog('xss_attempt', [
                            'field' => $key,
                            'value' => substr($value, 0, 100),
                            'pattern' => $pattern,
                            'ip' => $this->getClientIP()
                        ]);
                        return false;
                    }
                }
            }
        }
        
        return true;
    }
    
    /**
     * Valida tamanho da requisição
     */
    private function validateRequestSize($request): bool
    {
        $maxSize = 10 * 1024 * 1024; // 10MB
        $contentLength = $_SERVER['CONTENT_LENGTH'] ?? 0;
        
        return $contentLength <= $maxSize;
    }
    
    /**
     * Adiciona headers de segurança na resposta
     */
    private function addSecurityHeaders($response)
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'X-XSS-Protection' => '1; mode=block',
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' https:; connect-src 'self'; frame-ancestors 'none';",
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, proxy-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0'
        ];
        
        foreach ($headers as $name => $value) {
            if (method_exists($response, 'header')) {
                $response->header($name, $value);
            } else {
                header("$name: $value");
            }
        }
        
        return $response;
    }
    
    /**
     * Bloqueia requisição com resposta de erro
     */
    private function blockRequest(string $message, int $code = 403)
    {
        http_response_code($code);
        
        return json_encode([
            'error' => $message,
            'code' => $code,
            'timestamp' => time()
        ]);
    }
}
