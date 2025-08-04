<?php

declare(strict_types=1);

namespace ERP\Core\Http;

/**
 * Enhanced HTTP Response Class
 * 
 * Features:
 * - JSON responses with consistent format
 * - File downloads
 * - Streaming responses
 * - Cache headers
 * - Security headers
 * - Compression support
 * 
 * @package ERP\Core\Http
 */
final class Response
{
    private array $headers = [];
    private int $statusCode = 200;
    private string $statusText = 'OK';
    private string $version = '1.1';
    private array $cookies = [];
    
    private static array $statusTexts = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        204 => 'No Content',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        304 => 'Not Modified',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        409 => 'Conflict',
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
    ];
    
    public function __construct(
        private mixed $content = '',
        int $status = 200,
        array $headers = []
    ) {
        $this->setStatusCode($status);
        $this->setHeaders($headers);
        $this->setDefaultHeaders();
    }
    
    /**
     * Create JSON response
     */
    public static function json(
        mixed $data = null,
        int $status = 200,
        array $headers = [],
        int $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ): self {
        $response = new self('', $status, $headers);
        
        $response->setHeader('Content-Type', 'application/json');
        
        if ($data !== null) {
            $response->setContent(json_encode($data, $options));
        }
        
        return $response;
    }
    
    /**
     * Create success JSON response
     */
    public static function success(
        mixed $data = null,
        string $message = null,
        int $status = 200,
        array $headers = []
    ): self {
        $payload = [
            'success' => true,
            'status' => $status,
            'timestamp' => date('c'),
        ];
        
        if ($message !== null) {
            $payload['message'] = $message;
        }
        
        if ($data !== null) {
            $payload['data'] = $data;
        }
        
        return self::json($payload, $status, $headers);
    }
    
    /**
     * Create error JSON response
     */
    public static function error(
        string $message,
        int $status = 400,
        array $errors = [],
        array $headers = []
    ): self {
        $payload = [
            'success' => false,
            'status' => $status,
            'message' => $message,
            'timestamp' => date('c'),
        ];
        
        if (!empty($errors)) {
            $payload['errors'] = $errors;
        }
        
        return self::json($payload, $status, $headers);
    }
    
    /**
     * Create validation error response
     */
    public static function validationError(
        array $errors,
        string $message = 'Validation failed',
        int $status = 422
    ): self {
        return self::error($message, $status, $errors);
    }
    
    /**
     * Create paginated response
     */
    public static function paginated(
        array $data,
        array $pagination,
        string $message = null,
        int $status = 200
    ): self {
        $payload = [
            'success' => true,
            'status' => $status,
            'timestamp' => date('c'),
            'data' => $data,
            'pagination' => $pagination,
        ];
        
        if ($message !== null) {
            $payload['message'] = $message;
        }
        
        return self::json($payload, $status);
    }
    
    /**
     * Create file download response
     */
    public static function download(
        string $filePath,
        string $name = null,
        array $headers = []
    ): self {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("File not found: {$filePath}");
        }
        
        $name = $name ?? basename($filePath);
        $size = filesize($filePath);
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
        
        $defaultHeaders = [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) $size,
            'Content-Disposition' => "attachment; filename=\"{$name}\"",
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];
        
        $response = new self(file_get_contents($filePath), 200, array_merge($defaultHeaders, $headers));
        
        return $response;
    }
    
    /**
     * Create streaming response
     */
    public static function stream(callable $callback, int $status = 200, array $headers = []): self
    {
        $response = new self('', $status, $headers);
        
        ob_start();
        $callback();
        $content = ob_get_clean();
        
        $response->setContent($content);
        
        return $response;
    }
    
    /**
     * Create redirect response
     */
    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url]);
    }
    
    /**
     * Set response content
     */
    public function setContent(mixed $content): self
    {
        $this->content = $content;
        return $this;
    }
    
    /**
     * Get response content
     */
    public function getContent(): mixed
    {
        return $this->content;
    }
    
    /**
     * Set status code
     */
    public function setStatusCode(int $code, string $text = null): self
    {
        $this->statusCode = $code;
        $this->statusText = $text ?? self::$statusTexts[$code] ?? 'Unknown';
        
        return $this;
    }
    
    /**
     * Get status code
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
    
    /**
     * Get status text
     */
    public function getStatusText(): string
    {
        return $this->statusText;
    }
    
    /**
     * Set header
     */
    public function setHeader(string $name, string $value): self
    {
        $this->headers[strtolower($name)] = $value;
        return $this;
    }
    
    /**
     * Set multiple headers
     */
    public function setHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->setHeader($name, $value);
        }
        
        return $this;
    }
    
    /**
     * Get header
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
    
    /**
     * Get all headers
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
    
    /**
     * Check if header exists
     */
    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }
    
    /**
     * Remove header
     */
    public function removeHeader(string $name): self
    {
        unset($this->headers[strtolower($name)]);
        return $this;
    }
    
    /**
     * Set cookie
     */
    public function setCookie(
        string $name,
        string $value,
        int $expire = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax'
    ): self {
        $this->cookies[] = [
            'name' => $name,
            'value' => $value,
            'expire' => $expire,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => $httpOnly,
            'samesite' => $sameSite,
        ];
        
        return $this;
    }
    
    /**
     * Set cache headers
     */
    public function cache(int $maxAge, bool $public = false): self
    {
        $this->setHeader('Cache-Control', ($public ? 'public' : 'private') . ", max-age={$maxAge}");
        $this->setHeader('Expires', gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT');
        
        return $this;
    }
    
    /**
     * Set no-cache headers
     */
    public function noCache(): self
    {
        $this->setHeaders([
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
        
        return $this;
    }
    
    /**
     * Set security headers
     */
    public function securityHeaders(): self
    {
        $this->setHeaders([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'X-XSS-Protection' => '1; mode=block',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Content-Security-Policy' => "default-src 'self'",
        ]);
        
        return $this;
    }
    
    /**
     * Set CORS headers
     */
    public function cors(array $origins = ['*'], array $methods = ['GET', 'POST', 'PUT', 'DELETE']): self
    {
        $this->setHeaders([
            'Access-Control-Allow-Origin' => implode(', ', $origins),
            'Access-Control-Allow-Methods' => implode(', ', $methods),
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, X-Tenant-ID',
            'Access-Control-Max-Age' => '86400',
        ]);
        
        return $this;
    }
    
    /**
     * Send response to client
     */
    public function send(): void
    {
        $this->sendHeaders();
        $this->sendContent();
        
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }
    
    /**
     * Send headers
     */
    private function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        
        // Send status line
        header("HTTP/{$this->version} {$this->statusCode} {$this->statusText}");
        
        // Send headers
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        
        // Send cookies
        foreach ($this->cookies as $cookie) {
            setcookie(
                $cookie['name'],
                $cookie['value'],
                [
                    'expires' => $cookie['expire'],
                    'path' => $cookie['path'],
                    'domain' => $cookie['domain'],
                    'secure' => $cookie['secure'],
                    'httponly' => $cookie['httponly'],
                    'samesite' => $cookie['samesite'],
                ]
            );
        }
    }
    
    /**
     * Send content
     */
    private function sendContent(): void
    {
        echo $this->content;
    }
    
    /**
     * Set default headers
     */
    private function setDefaultHeaders(): void
    {
        $this->setHeaders([
            'X-Powered-By' => 'ERP-Sistema/2.0',
            'X-Request-ID' => uniqid('res_', true),
            'X-Response-Time' => number_format((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2) . 'ms',
        ]);
    }
    
    /**
     * Check if response is successful
     */
    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
    
    /**
     * Check if response is redirect
     */
    public function isRedirect(): bool
    {
        return $this->statusCode >= 300 && $this->statusCode < 400;
    }
    
    /**
     * Check if response is client error
     */
    public function isClientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }
    
    /**
     * Check if response is server error
     */
    public function isServerError(): bool
    {
        return $this->statusCode >= 500;
    }
    
    /**
     * Convert response to string
     */
    public function __toString(): string
    {
        return (string) $this->content;
    }
}