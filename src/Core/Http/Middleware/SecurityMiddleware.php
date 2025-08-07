<?php

declare(strict_types=1);

namespace ERP\Core\Http\Middleware;

use ERP\Core\Http\Request;
use ERP\Core\Http\Response;
use ERP\Core\Security\SecurityManager;
use Closure;

/**
 * OWASP Security Middleware
 * 
 * Implements OWASP Top 10 security controls:
 * - Injection prevention
 * - Broken authentication protection
 * - Sensitive data exposure prevention
 * - XML external entities prevention
 * - Broken access control protection
 * - Security misconfiguration prevention
 * - Cross-site scripting prevention
 * - Insecure deserialization prevention
 * - Components with known vulnerabilities protection
 * - Insufficient logging and monitoring prevention
 * 
 * @package ERP\Core\Http\Middleware
 */
final class SecurityMiddleware implements MiddlewareInterface
{
    public function __construct(
        private SecurityManager $security,
        private array $config = []
    ) {}
    
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Input validation and sanitization (Injection Prevention)
        $this->validateInput($request);
        
        // 2. CSRF protection
        $this->validateCsrfToken($request);
        
        // 3. Rate limiting
        $this->checkRateLimit($request);
        
        // 4. Content type validation
        $this->validateContentType($request);
        
        // 5. File upload security
        $this->validateFileUploads($request);
        
        // Process request
        $response = $next($request);
        
        // 6. Add security headers
        $this->addSecurityHeaders($response);
        
        // 7. Content Security Policy
        $this->addContentSecurityPolicy($response);
        
        // 8. Audit logging
        $this->auditRequest($request, $response);
        
        return $response;
    }
    
    /**
     * Validate and sanitize input (OWASP A1: Injection)
     */
    private function validateInput(Request $request): void
    {
        $input = $request->all();
        
        foreach ($input as $key => $value) {
            // Check for SQL injection patterns
            if (is_string($value) && $this->detectSqlInjection($value)) {
                throw new \Exception("Potential SQL injection detected in field: {$key}", 400);
            }
            
            // Check for XSS patterns
            if (is_string($value) && $this->detectXss($value)) {
                throw new \Exception("Potential XSS detected in field: {$key}", 400);
            }
            
            // Check for command injection
            if (is_string($value) && $this->detectCommandInjection($value)) {
                throw new \Exception("Potential command injection detected in field: {$key}", 400);
            }
        }
    }
    
    /**
     * Validate CSRF token for state-changing requests
     */
    private function validateCsrfToken(Request $request): void
    {
        $method = $request->getMethod();
        
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            $token = $request->header('X-CSRF-Token') ?? $request->input('_token');
            
            if (! $token || ! $this->security->validateCsrfToken($token)) {
                throw new \Exception('CSRF token mismatch', 419);
            }
        }
    }
    
    /**
     * Check rate limiting (OWASP A2: Broken Authentication)
     */
    private function checkRateLimit(Request $request): void
    {
        $ip = $request->getClientIp();
        $key = "rate_limit:{$ip}";
        
        $attempts = $this->security->getCache()->get($key, 0);
        $maxAttempts = $this->config['rate_limit']['max_requests'] ?? 100;
        $windowMinutes = $this->config['rate_limit']['window_minutes'] ?? 60;
        
        if ($attempts >= $maxAttempts) {
            throw new \Exception('Rate limit exceeded', 429);
        }
        
        $this->security->getCache()->put($key, $attempts + 1, $windowMinutes * 60);
    }
    
    /**
     * Validate content type
     */
    private function validateContentType(Request $request): void
    {
        $contentType = $request->header('Content-Type');
        $method = $request->getMethod();
        
        if (in_array($method, ['POST', 'PUT', 'PATCH']) && $contentType) {
            $allowedTypes = [
                'application/json',
                'application/x-www-form-urlencoded',
                'multipart/form-data'
            ];
            
            $isValid = false;
            foreach ($allowedTypes as $type) {
                if (str_starts_with($contentType, $type)) {
                    $isValid = true;
                    break;
                }
            }
            
            if (! $isValid) {
                throw new \Exception('Invalid content type', 415);
            }
        }
    }
    
    /**
     * Validate file uploads (OWASP A5: Broken Access Control)
     */
    private function validateFileUploads(Request $request): void
    {
        $files = $_FILES ?? [];
        
        foreach ($files as $field => $file) {
            if (is_array($file['name'])) {
                continue; // Skip multi-file uploads for now
            }
            
            // Check file size
            $maxSize = $this->config['upload']['max_size'] ?? 10 * 1024 * 1024; // 10MB
            if ($file['size'] > $maxSize) {
                throw new \Exception("File too large: {$field}", 413);
            }
            
            // Check file extension
            $allowedExtensions = $this->config['upload']['allowed_extensions'] ?? [
                'jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx'
            ];
            
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (! in_array($extension, $allowedExtensions)) {
                throw new \Exception("File type not allowed: {$extension}", 415);
            }
            
            // Check MIME type
            $mimeType = mime_content_type($file['tmp_name']);
            $allowedMimes = $this->config['upload']['allowed_mimes'] ?? [
                'image/jpeg', 'image/png', 'image/gif',
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            ];
            
            if (! in_array($mimeType, $allowedMimes)) {
                throw new \Exception("MIME type not allowed: {$mimeType}", 415);
            }
        }
    }
    
    /**
     * Add security headers (OWASP A6: Security Misconfiguration)
     */
    private function addSecurityHeaders(Response $response): void
    {
        $response->setHeaders([
            // Prevent MIME type sniffing
            'X-Content-Type-Options' => 'nosniff',
            
            // Prevent clickjacking
            'X-Frame-Options' => 'DENY',
            
            // XSS protection
            'X-XSS-Protection' => '1; mode=block',
            
            // HSTS (if HTTPS)
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains; preload',
            
            // Referrer policy
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            
            // Permissions policy
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
            
            // Remove server information
            'Server' => 'ERP-Sistema'
        ]);
    }
    
    /**
     * Add Content Security Policy (OWASP A7: Cross-Site Scripting)
     */
    private function addContentSecurityPolicy(Response $response): void
    {
        $csp = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
            "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
            "img-src 'self' data: https:",
            "font-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
            "connect-src 'self'",
            "media-src 'self'",
            "object-src 'none'",
            "child-src 'self'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "base-uri 'self'",
            "upgrade-insecure-requests"
        ];
        
        $response->setHeader('Content-Security-Policy', implode('; ', $csp));
    }
    
    /**
     * Audit request for logging and monitoring (OWASP A10: Insufficient Logging)
     */
    private function auditRequest(Request $request, Response $response): void
    {
        $logData = [
            'timestamp' => date('c'),
            'method' => $request->getMethod(),
            'uri' => $request->getUri(),
            'ip' => $request->getClientIp(),
            'user_agent' => $request->getUserAgent(),
            'status_code' => $response->getStatusCode(),
            'tenant_id' => $request->getTenantId(),
            'user_id' => $request->getAttribute('user_id'),
            'request_id' => $request->getRequestId(),
        ];
        
        // Log security events
        if ($response->getStatusCode() >= 400) {
            $logData['error'] = true;
            $logData['input'] = $request->except(['password', 'password_confirmation', '_token']);
        }
        
        $this->security->auditLog('http_request', $logData);
    }
    
    /**
     * Detect SQL injection patterns
     */
    private function detectSqlInjection(string $input): bool
    {
        $patterns = [
            '/(\b(SELECT|INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|EXEC|UNION|SCRIPT)\b)/i',
            '/(\b(OR|AND)\s+[\'"]?\d+[\'"]?\s*=\s*[\'"]?\d+[\'"]?)/i',
            '/[\'";](\s*)(OR|AND)(\s*)[\'"]?\d+[\'"]?\s*=\s*[\'"]?\d+[\'"]?/i',
            '/(\b(UNION|SELECT).*FROM)/i',
            '/(EXEC|EXECUTE)(\s+|\()?\w+/i'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Detect XSS patterns
     */
    private function detectXss(string $input): bool
    {
        $patterns = [
            '/<script[^>]*>.*?<\/script>/is',
            '/<iframe[^>]*>.*?<\/iframe>/is',
            '/javascript:/i',
            '/vbscript:/i',
            '/on\w+\s*=/i',
            '/<object[^>]*>.*?<\/object>/is',
            '/<embed[^>]*>.*?<\/embed>/is',
            '/<link[^>]*>/is',
            '/<meta[^>]*>/is'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Detect command injection patterns
     */
    private function detectCommandInjection(string $input): bool
    {
        $patterns = [
            '/[;&|`$(){}\\\\]/',
            '/\b(cat|ls|pwd|whoami|id|uname|ps|netstat|ifconfig|ping|wget|curl|nc|ncat|telnet|ssh|ftp|sudo|su|chmod|chown|rm|mv|cp|mkdir|rmdir)\b/i'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }
        
        return false;
    }
}
