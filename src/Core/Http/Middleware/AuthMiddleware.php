<?php

declare(strict_types=1);

namespace ERP\Core\Http\Middleware;

use ERP\Core\Http\Request;
use ERP\Core\Http\Response;
use ERP\Core\Auth\AuthManager;
use ERP\Core\Exceptions\AuthenticationException;
use Closure;

/**
 * Authentication Middleware
 * 
 * Ensures requests are authenticated
 * 
 * @package ERP\Core\Http\Middleware
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AuthManager $auth
    ) {}
    
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->getBearerToken();
        
        if (!$token) {
            throw new AuthenticationException('Authentication token required', 401);
        }
        
        // Set token for JWT guard
        $guard = $this->auth->guard('jwt');
        $guard->setToken($token);
        
        // Set tenant from request header
        $tenantId = $request->getTenantId();
        if ($tenantId) {
            $this->auth->setTenant($tenantId);
        }
        
        if (!$this->auth->check()) {
            throw new AuthenticationException('Invalid or expired token', 401);
        }
        
        // Add user info to request attributes
        $user = $this->auth->user();
        $request->setAttribute('user', $user);
        $request->setAttribute('user_id', $user->getAuthIdentifier());
        
        return $next($request);
    }
}