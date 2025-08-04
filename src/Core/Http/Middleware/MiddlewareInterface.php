<?php

declare(strict_types=1);

namespace ERP\Core\Http\Middleware;

use ERP\Core\Http\Request;
use ERP\Core\Http\Response;
use Closure;

/**
 * Middleware Interface
 * 
 * Contract for HTTP middleware
 * 
 * @package ERP\Core\Http\Middleware
 */
interface MiddlewareInterface
{
    /**
     * Handle an incoming request
     */
    public function handle(Request $request, Closure $next): Response;
}