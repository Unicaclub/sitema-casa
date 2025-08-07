<?php

declare(strict_types=1);

namespace ERP\Core\Http;

use ERP\Core\Container\Container;
use ERP\Core\Events\EventDispatcher;
use ERP\Core\Security\SecurityManager;
use ERP\Core\Http\Middleware\MiddlewareInterface;
use ERP\Core\Exceptions\RouteNotFoundException;
use ERP\Core\Exceptions\MethodNotAllowedException;
use Closure;

/**
 * Advanced RESTful Router
 * 
 * Features:
 * - RESTful routing patterns
 * - Route groups with middleware
 * - Parameter constraints and validation
 * - Route caching for performance
 * - OpenAPI documentation generation
 * - Rate limiting per route
 * - CORS handling
 * - Request/Response transformers
 * 
 * @package ERP\Core\Http
 */
final class Router
{
    private array $routes = [];
    private array $routeGroups = [];
    private array $middleware = [];
    private array $globalMiddleware = [];
    private array $routeMiddleware = [];
    private array $middlewareGroups = [];
    private array $patterns = [];
    private array $routeCache = [];
    private ?Route $current = null;
    
    public function __construct(
        private Container $container,
        private EventDispatcher $events,
        private SecurityManager $security
    ) {
        $this->registerDefaultPatterns();
        $this->registerDefaultMiddleware();
    }
    
    /**
     * Add a GET route
     */
    public function get(string $uri, array|string|Closure $action): Route
    {
        return $this->addRoute(['GET', 'HEAD'], $uri, $action);
    }
    
    /**
     * Add a POST route
     */
    public function post(string $uri, array|string|Closure $action): Route
    {
        return $this->addRoute(['POST'], $uri, $action);
    }
    
    /**
     * Add a PUT route
     */
    public function put(string $uri, array|string|Closure $action): Route
    {
        return $this->addRoute(['PUT'], $uri, $action);
    }
    
    /**
     * Add a PATCH route
     */
    public function patch(string $uri, array|string|Closure $action): Route
    {
        return $this->addRoute(['PATCH'], $uri, $action);
    }
    
    /**
     * Add a DELETE route
     */
    public function delete(string $uri, array|string|Closure $action): Route
    {
        return $this->addRoute(['DELETE'], $uri, $action);
    }
    
    /**
     * Add an OPTIONS route
     */
    public function options(string $uri, array|string|Closure $action): Route
    {
        return $this->addRoute(['OPTIONS'], $uri, $action);
    }
    
    /**
     * Add a route that responds to any HTTP method
     */
    public function any(string $uri, array|string|Closure $action): Route
    {
        return $this->addRoute(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], $uri, $action);
    }
    
    /**
     * Add a route that responds to multiple HTTP methods
     */
    public function match(array $methods, string $uri, array|string|Closure $action): Route
    {
        return $this->addRoute(array_map('strtoupper', $methods), $uri, $action);
    }
    
    /**
     * Create a RESTful resource routes
     */
    public function resource(string $name, string $controller, array $options = []): RouteRegistrar
    {
        $registrar = new RouteRegistrar($this);
        
        return $registrar->resource($name, $controller, $options);
    }
    
    /**
     * Create API resource routes (excludes create/edit forms)
     */
    public function apiResource(string $name, string $controller, array $options = []): RouteRegistrar
    {
        $options['except'] = array_merge($options['except'] ?? [], ['create', 'edit']);
        
        return $this->resource($name, $controller, $options);
    }
    
    /**
     * Create a route group with shared attributes
     */
    public function group(array $attributes, Closure $callback): void
    {
        $this->routeGroups[] = $attributes;
        
        $callback($this);
        
        array_pop($this->routeGroups);
    }
    
    /**
     * Add route to collection
     */
    private function addRoute(array $methods, string $uri, array|string|Closure $action): Route
    {
        $route = new Route($methods, $uri, $action);
        
        // Apply group attributes
        if (! empty($this->routeGroups)) {
            $route = $this->applyGroupAttributes($route);
        }
        
        $this->routes[] = $route;
        
        return $route;
    }
    
    /**
     * Apply group attributes to route
     */
    private function applyGroupAttributes(Route $route): Route
    {
        foreach ($this->routeGroups as $group) {
            if (isset($group['prefix'])) {
                $route->prefix($group['prefix']);
            }
            
            if (isset($group['middleware'])) {
                $route->middleware($group['middleware']);
            }
            
            if (isset($group['namespace'])) {
                $route->namespace($group['namespace']);
            }
            
            if (isset($group['name'])) {
                $route->name($group['name']);
            }
            
            if (isset($group['domain'])) {
                $route->domain($group['domain']);
            }
        }
        
        return $route;
    }
    
    /**
     * Dispatch the request to matching route
     */
    public function dispatch(Request $request): Response
    {
        $this->events->dispatch('router.matching', [$request]);
        
        $route = $this->findRoute($request);
        
        if ($route === null) {
            throw new RouteNotFoundException("Route not found for {$request->getMethod()} {$request->getPathInfo()}");
        }
        
        $this->current = $route;
        
        $this->events->dispatch('router.matched', [$route, $request]);
        
        return $this->runRoute($request, $route);
    }
    
    /**
     * Find matching route for request
     */
    private function findRoute(Request $request): ?Route
    {
        $method = $request->getMethod();
        $pathInfo = $request->getPathInfo();
        
        // Check cache first
        $cacheKey = $method . ':' . $pathInfo;
        if (isset($this->routeCache[$cacheKey])) {
            return $this->routeCache[$cacheKey];
        }
        
        $allowedMethods = [];
        
        foreach ($this->routes as $route) {
            // Check if path matches
            if ($this->matchesPath($route, $pathInfo)) {
                // Check if method is allowed
                if (in_array($method, $route->getMethods())) {
                    // Extract parameters
                    $parameters = $this->extractParameters($route, $pathInfo);
                    $route->setParameters($parameters);
                    
                    // Cache the match
                    $this->routeCache[$cacheKey] = $route;
                    
                    return $route;
                }
                
                $allowedMethods = array_merge($allowedMethods, $route->getMethods());
            }
        }
        
        // If path matches but method doesn't, throw method not allowed
        if (! empty($allowedMethods)) {
            throw new MethodNotAllowedException("Method {$method} not allowed. Allowed: " . implode(', ', array_unique($allowedMethods)));
        }
        
        return null;
    }
    
    /**
     * Check if route path matches request path
     */
    private function matchesPath(Route $route, string $path): bool
    {
        $pattern = $this->compileRoute($route);
        
        return (bool) preg_match($pattern, $path);
    }
    
    /**
     * Compile route pattern to regex
     */
    private function compileRoute(Route $route): string
    {
        $uri = $route->getUri();
        
        // Replace named parameters with regex patterns
        $pattern = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\??(\:[^}]+)?\}/', function ($matches) {
            $name = $matches[1];
            $isOptional = str_ends_with($matches[0], '?}');
            $constraint = $matches[2] ?? null;
            
            if ($constraint) {
                $pattern = substr($constraint, 1); // Remove the ':'
            } else {
                $pattern = $this->patterns[$name] ?? '[^/]+';
            }
            
            if ($isOptional) {
                return "(?P<{$name}>{$pattern})?";
            }
            
            return "(?P<{$name}>{$pattern})";
        }, $uri);
        
        return '#^' . $pattern . '$#';
    }
    
    /**
     * Extract parameters from matched route
     */
    private function extractParameters(Route $route, string $path): array
    {
        $pattern = $this->compileRoute($route);
        
        if (preg_match($pattern, $path, $matches)) {
            $parameters = [];
            
            foreach ($matches as $key => $value) {
                if (is_string($key) && $value !== '') {
                    $parameters[$key] = $value;
                }
            }
            
            return $parameters;
        }
        
        return [];
    }
    
    /**
     * Run the matched route
     */
    private function runRoute(Request $request, Route $route): Response
    {
        // Build middleware pipeline
        $middleware = $this->gatherRouteMiddleware($route);
        
        // Create pipeline
        $pipeline = array_reduce(
            array_reverse($middleware),
            fn($next, $middleware) => fn($request) => $this->callMiddleware($middleware, $request, $next),
            fn($request) => $this->callAction($request, $route)
        );
        
        return $pipeline($request);
    }
    
    /**
     * Gather middleware for route
     */
    private function gatherRouteMiddleware(Route $route): array
    {
        $middleware = array_merge($this->globalMiddleware, $route->getMiddleware());
        
        // Resolve middleware names to instances
        return array_map(fn($middleware) => $this->resolveMiddleware($middleware), $middleware);
    }
    
    /**
     * Resolve middleware instance
     */
    private function resolveMiddleware(string|MiddlewareInterface $middleware): MiddlewareInterface
    {
        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }
        
        // Check if it's a registered middleware alias
        if (isset($this->routeMiddleware[$middleware])) {
            $middleware = $this->routeMiddleware[$middleware];
        }
        
        // Check if it's a middleware group
        if (isset($this->middlewareGroups[$middleware])) {
            throw new \InvalidArgumentException("Middleware groups not supported in this context");
        }
        
        // Resolve from container
        return $this->container->make($middleware);
    }
    
    /**
     * Call middleware
     */
    private function callMiddleware(MiddlewareInterface $middleware, Request $request, Closure $next): Response
    {
        return $middleware->handle($request, $next);
    }
    
    /**
     * Call route action
     */
    private function callAction(Request $request, Route $route): Response
    {
        $action = $route->getAction();
        
        if ($action instanceof Closure) {
            $parameters = $this->resolveMethodParameters($action, $route->getParameters(), $request);
            return $action(...$parameters);
        }
        
        if (is_string($action)) {
            return $this->callControllerAction($action, $route, $request);
        }
        
        if (is_array($action)) {
            [$controller, $method] = $action;
            return $this->callControllerMethod($controller, $method, $route, $request);
        }
        
        throw new \InvalidArgumentException('Invalid route action');
    }
    
    /**
     * Call controller action
     */
    private function callControllerAction(string $action, Route $route, Request $request): Response
    {
        if (! str_contains($action, '@')) {
            throw new \InvalidArgumentException("Invalid controller action format: {$action}");
        }
        
        [$controller, $method] = explode('@', $action, 2);
        
        return $this->callControllerMethod($controller, $method, $route, $request);
    }
    
    /**
     * Call controller method
     */
    private function callControllerMethod(string $controller, string $method, Route $route, Request $request): Response
    {
        // Apply namespace if set
        if ($route->getNamespace()) {
            $controller = $route->getNamespace() . '\\' . ltrim($controller, '\\');
        }
        
        $instance = $this->container->make($controller);
        
        if (! method_exists($instance, $method)) {
            throw new \BadMethodCallException("Method {$method} does not exist on controller {$controller}");
        }
        
        $parameters = $this->resolveMethodParameters(
            new \ReflectionMethod($instance, $method),
            $route->getParameters(),
            $request
        );
        
        $response = $instance->{$method}(...$parameters);
        
        // Convert response to Response object if needed
        if (! $response instanceof Response) {
            $response = new Response($response);
        }
        
        return $response;
    }
    
    /**
     * Resolve method parameters with dependency injection
     */
    private function resolveMethodParameters(\ReflectionMethod|\ReflectionFunction $reflector, array $routeParameters, Request $request): array
    {
        $parameters = [];
        
        foreach ($reflector->getParameters() as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();
            
            // Check if parameter is in route parameters
            if (array_key_exists($name, $routeParameters)) {
                $parameters[] = $routeParameters[$name];
                continue;
            }
            
            // Check if parameter is Request
            if ($type && $type->getName() === Request::class) {
                $parameters[] = $request;
                continue;
            }
            
            // Try to resolve from container
            if ($type && !$type->isBuiltin()) {
                try {
                    $parameters[] = $this->container->make($type->getName());
                    continue;
                } catch (\Throwable) {
                    // Fall through to default value
                }
            }
            
            // Use default value if available
            if ($parameter->isDefaultValueAvailable()) {
                $parameters[] = $parameter->getDefaultValue();
                continue;
            }
            
            throw new \InvalidArgumentException("Unable to resolve parameter {$name}");
        }
        
        return $parameters;
    }
    
    /**
     * Register global middleware
     */
    public function globalMiddleware(array|string $middleware): void
    {
        if (is_string($middleware)) {
            $middleware = [$middleware];
        }
        
        $this->globalMiddleware = array_merge($this->globalMiddleware, $middleware);
    }
    
    /**
     * Register route middleware alias
     */
    public function aliasMiddleware(string $name, string $class): void
    {
        $this->routeMiddleware[$name] = $class;
    }
    
    /**
     * Register middleware group
     */
    public function middlewareGroup(string $name, array $middleware): void
    {
        $this->middlewareGroups[$name] = $middleware;
    }
    
    /**
     * Register parameter pattern
     */
    public function pattern(string $key, string $pattern): void
    {
        $this->patterns[$key] = $pattern;
    }
    
    /**
     * Register multiple parameter patterns
     */
    public function patterns(array $patterns): void
    {
        foreach ($patterns as $key => $pattern) {
            $this->pattern($key, $pattern);
        }
    }
    
    /**
     * Get current route
     */
    public function current(): ?Route
    {
        return $this->current;
    }
    
    /**
     * Get all routes
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
    
    /**
     * Generate URL for named route
     */
    public function url(string $name, array $parameters = []): string
    {
        foreach ($this->routes as $route) {
            if ($route->getName() === $name) {
                return $this->generateUrl($route, $parameters);
            }
        }
        
        throw new \InvalidArgumentException("Route {$name} not found");
    }
    
    /**
     * Generate URL for route
     */
    private function generateUrl(Route $route, array $parameters = []): string
    {
        $uri = $route->getUri();
        
        // Replace parameters in URI
        foreach ($parameters as $key => $value) {
            $uri = str_replace(
                ['{' . $key . '}', '{' . $key . '?}'],
                (string) $value,
                $uri
            );
        }
        
        // Remove optional parameters that weren't provided
        $uri = preg_replace('/\{[^}]+\?\}/', '', $uri);
        
        return $uri;
    }
    
    /**
     * Clear route cache
     */
    public function clearCache(): void
    {
        $this->routeCache = [];
    }
    
    /**
     * Register default parameter patterns
     */
    private function registerDefaultPatterns(): void
    {
        $this->patterns([
            'id' => '[0-9]+',
            'uuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}',
            'slug' => '[a-z0-9-]+',
            'alpha' => '[a-zA-Z]+',
            'num' => '[0-9]+',
            'alphanum' => '[a-zA-Z0-9]+',
        ]);
    }
    
    /**
     * Register default middleware aliases
     */
    private function registerDefaultMiddleware(): void
    {
        $this->aliasMiddleware('auth', \ERP\Core\Http\Middleware\AuthMiddleware::class);
        $this->aliasMiddleware('guest', \ERP\Core\Http\Middleware\GuestMiddleware::class);
        $this->aliasMiddleware('throttle', \ERP\Core\Http\Middleware\ThrottleMiddleware::class);
        $this->aliasMiddleware('cors', \ERP\Core\Http\Middleware\CorsMiddleware::class);
        $this->aliasMiddleware('security', \ERP\Core\Http\Middleware\SecurityMiddleware::class);
        $this->aliasMiddleware('tenant', \ERP\Core\Http\Middleware\TenantMiddleware::class);
        $this->aliasMiddleware('audit', \ERP\Core\Http\Middleware\AuditMiddleware::class);
        
        $this->middlewareGroup('api', [
            'throttle:60,1',
            'auth:api',
            'security',
            'cors',
            'audit'
        ]);
        
        $this->middlewareGroup('web', [
            'security',
            'cors'
        ]);
    }
}
