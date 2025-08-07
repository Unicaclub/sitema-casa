<?php

declare(strict_types=1);

namespace ERP\Core\Http;

use ERP\Core\Security\InputSanitizer;
use ERP\Core\Validation\Validator;

/**
 * Enhanced HTTP Request Class
 * 
 * Features:
 * - Input sanitization and validation
 * - File upload handling
 * - JSON support
 * - Multi-tenant support
 * - Rate limiting headers
 * - Security headers validation
 * 
 * @package ERP\Core\Http
 */
final class Request
{
    private array $query;
    private array $request;
    private array $attributes;
    private array $cookies;
    private array $files;
    private array $server;
    private array $headers;
    private ?string $content = null;
    private array $json = [];
    private ?string $method = null;
    private ?string $pathInfo = null;
    private ?InputSanitizer $sanitizer = null;
    private ?Validator $validator = null;
    
    public function __construct(
        array $query = [],
        array $request = [],
        array $attributes = [],
        array $cookies = [],
        array $files = [],
        array $server = [],
        ?string $content = null
    ) {
        $this->query = $query;
        $this->request = $request;
        $this->attributes = $attributes;
        $this->cookies = $cookies;
        $this->files = $files;
        $this->server = $server;
        $this->content = $content;
        $this->headers = $this->getHeadersFromServer($server);
        
        $this->parseJsonContent();
    }
    
    /**
     * Create request from PHP globals
     */
    public static function createFromGlobals(): self
    {
        return new self(
            $_GET,
            $_POST,
            [],
            $_COOKIE,
            $_FILES,
            $_SERVER,
            file_get_contents('php://input') ?: null
        );
    }
    
    /**
     * Get HTTP method
     */
    public function getMethod(): string
    {
        if ($this->method !== null) {
            return $this->method;
        }
        
        $method = strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
        
        // Check for method override
        if ($method === 'POST') {
            $override = $this->headers['X-HTTP-METHOD-OVERRIDE'] ?? 
                       $this->request['_method'] ?? null;
            
            if ($override && in_array(strtoupper($override), ['PUT', 'PATCH', 'DELETE'])) {
                $method = strtoupper($override);
            }
        }
        
        return $this->method = $method;
    }
    
    /**
     * Get request URI
     */
    public function getUri(): string
    {
        return $this->server['REQUEST_URI'] ?? '/';
    }
    
    /**
     * Get path info
     */
    public function getPathInfo(): string
    {
        if ($this->pathInfo !== null) {
            return $this->pathInfo;
        }
        
        $requestUri = $this->getUri();
        $scriptName = $this->server['SCRIPT_NAME'] ?? '';
        
        // Remove query string
        if (($pos = strpos($requestUri, '?')) !== false) {
            $requestUri = substr($requestUri, 0, $pos);
        }
        
        // Remove script name from path
        if ($scriptName && str_starts_with($requestUri, dirname($scriptName))) {
            $pathInfo = substr($requestUri, strlen(dirname($scriptName)));
        } else {
            $pathInfo = $requestUri;
        }
        
        return $this->pathInfo = '/' . ltrim($pathInfo, '/');
    }
    
    /**
     * Get query parameter
     */
    public function query(string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }
        
        return $this->query[$key] ?? $default;
    }
    
    /**
     * Get request parameter (POST data)
     */
    public function request(string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->request;
        }
        
        return $this->request[$key] ?? $default;
    }
    
    /**
     * Get input value from query or request
     */
    public function input(string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return array_merge($this->query, $this->request, $this->json);
        }
        
        return $this->json[$key] ?? 
               $this->request[$key] ?? 
               $this->query[$key] ?? 
               $default;
    }
    
    /**
     * Get all input data
     */
    public function all(): array
    {
        return array_merge($this->query, $this->request, $this->json);
    }
    
    /**
     * Get only specified keys from input
     */
    public function only(array $keys): array
    {
        $input = $this->all();
        
        return array_intersect_key($input, array_flip($keys));
    }
    
    /**
     * Get all input except specified keys
     */
    public function except(array $keys): array
    {
        $input = $this->all();
        
        return array_diff_key($input, array_flip($keys));
    }
    
    /**
     * Check if input has key
     */
    public function has(string|array $key): bool
    {
        if (is_array($key)) {
            foreach ($key as $k) {
                if (! $this->has($k)) {
                    return false;
                }
            }
            return true;
        }
        
        $input = $this->all();
        return array_key_exists($key, $input) && $input[$key] !== null && $input[$key] !== '';
    }
    
    /**
     * Check if input is filled
     */
    public function filled(string|array $key): bool
    {
        if (is_array($key)) {
            foreach ($key as $k) {
                if (! $this->filled($k)) {
                    return false;
                }
            }
            return true;
        }
        
        return $this->has($key) && trim((string) $this->input($key)) !== '';
    }
    
    /**
     * Get sanitized input
     */
    public function safe(string $key = null, mixed $default = null): mixed
    {
        if ($this->sanitizer === null) {
            $this->sanitizer = new InputSanitizer();
        }
        
        if ($key === null) {
            return $this->sanitizer->sanitize($this->all());
        }
        
        $value = $this->input($key, $default);
        
        return $this->sanitizer->sanitize($value);
    }
    
    /**
     * Get validated input
     */
    public function validated(array $rules): array
    {
        if ($this->validator === null) {
            $this->validator = new Validator();
        }
        
        return $this->validator->validate($this->all(), $rules);
    }
    
    /**
     * Get cookie value
     */
    public function cookie(string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->cookies;
        }
        
        return $this->cookies[$key] ?? $default;
    }
    
    /**
     * Get uploaded file
     */
    public function file(string $key): ?UploadedFile
    {
        if (! isset($this->files[$key])) {
            return null;
        }
        
        $file = $this->files[$key];
        
        if (is_array($file)) {
            return new UploadedFile(
                $file['tmp_name'],
                $file['name'],
                $file['type'],
                $file['size'],
                $file['error']
            );
        }
        
        return null;
    }
    
    /**
     * Check if request has file
     */
    public function hasFile(string $key): bool
    {
        $file = $this->file($key);
        
        return $file !== null && $file->isValid();
    }
    
    /**
     * Get header value
     */
    public function header(string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->headers;
        }
        
        $key = str_replace('_', '-', strtolower($key));
        
        return $this->headers[$key] ?? $default;
    }
    
    /**
     * Get server value
     */
    public function server(string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->server;
        }
        
        return $this->server[$key] ?? $default;
    }
    
    /**
     * Get client IP address
     */
    public function getClientIp(): string
    {
        $ipKeys = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_CLIENT_IP',            // Proxy
            'HTTP_X_FORWARDED_FOR',      // Load balancer/proxy
            'HTTP_X_FORWARDED',          // Proxy
            'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster
            'HTTP_FORWARDED_FOR',        // Proxy
            'HTTP_FORWARDED',            // Proxy
            'REMOTE_ADDR'                // Standard
        ];
        
        foreach ($ipKeys as $key) {
            if (! empty($this->server[$key])) {
                $ips = explode(',', $this->server[$key]);
                $ip = trim($ips[0]);
                
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * Get user agent
     */
    public function getUserAgent(): string
    {
        return $this->header('User-Agent') ?? '';
    }
    
    /**
     * Check if request is AJAX
     */
    public function isAjax(): bool
    {
        return strtolower($this->header('X-Requested-With') ?? '') === 'xmlhttprequest';
    }
    
    /**
     * Check if request expects JSON
     */
    public function expectsJson(): bool
    {
        return str_contains($this->header('Accept') ?? '', 'application/json');
    }
    
    /**
     * Check if request is JSON
     */
    public function isJson(): bool
    {
        return str_contains($this->header('Content-Type') ?? '', 'application/json');
    }
    
    /**
     * Get JSON data
     */
    public function json(string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->json;
        }
        
        return $this->json[$key] ?? $default;
    }
    
    /**
     * Get raw content
     */
    public function getContent(): ?string
    {
        return $this->content;
    }
    
    /**
     * Get tenant ID from headers
     */
    public function getTenantId(): ?string
    {
        return $this->header('X-Tenant-ID');
    }
    
    /**
     * Get bearer token
     */
    public function getBearerToken(): ?string
    {
        $header = $this->header('Authorization');
        
        if ($header && str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }
        
        return null;
    }
    
    /**
     * Get request ID for tracing
     */
    public function getRequestId(): string
    {
        return $this->header('X-Request-ID') ?? uniqid('req_', true);
    }
    
    /**
     * Set attribute
     */
    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }
    
    /**
     * Get attribute
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
    
    /**
     * Get all attributes
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }
    
    /**
     * Parse JSON content
     */
    private function parseJsonContent(): void
    {
        if ($this->isJson() && $this->content) {
            $decoded = json_decode($this->content, true);
            
            if (json_last_error() === JSON_ERROR_NONE) {
                $this->json = $decoded ?? [];
            }
        }
    }
    
    /**
     * Extract headers from server variables
     */
    private function getHeadersFromServer(array $server): array
    {
        $headers = [];
        
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = substr($key, 5);
                $name = str_replace('_', '-', strtolower($name));
                $headers[$name] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'])) {
                $name = str_replace('_', '-', strtolower($key));
                $headers[$name] = $value;
            }
        }
        
        return $headers;
    }
    
    /**
     * Convert request to array for debugging
     */
    public function toArray(): array
    {
        return [
            'method' => $this->getMethod(),
            'uri' => $this->getUri(),
            'path' => $this->getPathInfo(),
            'query' => $this->query,
            'request' => $this->request,
            'json' => $this->json,
            'headers' => $this->headers,
            'client_ip' => $this->getClientIp(),
            'user_agent' => $this->getUserAgent(),
            'is_ajax' => $this->isAjax(),
            'is_json' => $this->isJson(),
            'tenant_id' => $this->getTenantId(),
        ];
    }
}
