<?php

declare(strict_types=1);

namespace ERP\Core\Http\Middleware;

use ERP\Core\Http\Request;
use ERP\Core\Http\Response;
use Closure;

/**
 * CORS Middleware
 * 
 * Handles Cross-Origin Resource Sharing
 * 
 * @package ERP\Core\Http\Middleware
 */
final class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private array $config = []
    ) {}
    
    public function handle(Request $request, Closure $next): Response
    {
        // Handle preflight requests
        if ($request->getMethod() === 'OPTIONS') {
            return $this->handlePreflightRequest($request);
        }
        
        $response = $next($request);
        
        return $this->addCorsHeaders($request, $response);
    }
    
    /**
     * Handle preflight OPTIONS request
     */
    private function handlePreflightRequest(Request $request): Response
    {
        $response = new Response('', 200);
        
        return $this->addCorsHeaders($request, $response);
    }
    
    /**
     * Add CORS headers to response
     */
    private function addCorsHeaders(Request $request, Response $response): Response
    {
        $origin = $request->header('Origin');
        $allowedOrigins = $this->config['allowed_origins'] ?? ['*'];
        
        // Check if origin is allowed
        if (in_array('*', $allowedOrigins) || ($origin && in_array($origin, $allowedOrigins))) {
            $response->setHeader('Access-Control-Allow-Origin', $origin ?? '*');
        }
        
        $response->setHeaders([
            'Access-Control-Allow-Methods' => implode(', ', $this->config['allowed_methods'] ?? [
                'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'
            ]),
            'Access-Control-Allow-Headers' => implode(', ', $this->config['allowed_headers'] ?? [
                'Content-Type', 'Authorization', 'X-Requested-With', 'X-Tenant-ID', 'X-CSRF-Token'
            ]),
            'Access-Control-Expose-Headers' => implode(', ', $this->config['exposed_headers'] ?? [
                'X-Request-ID', 'X-Response-Time'
            ]),
            'Access-Control-Max-Age' => (string) ($this->config['max_age'] ?? 86400),
            'Access-Control-Allow-Credentials' => $this->config['allow_credentials'] ? 'true' : 'false',
        ]);
        
        return $response;
    }
}