<?php

declare(strict_types=1);

namespace ERP\Core\Http;

use Closure;

/**
 * HTTP Route Class
 * 
 * Represents a single route with its configuration
 * 
 * @package ERP\Core\Http
 */
final class Route
{
    private array $middleware = [];
    private ?string $name = null;
    private ?string $namespace = null;
    private string $prefix = '';
    private ?string $domain = null;
    private array $parameters = [];
    private array $constraints = [];
    private array $defaults = [];
    private ?string $description = null;
    private array $tags = [];
    private bool $deprecated = false;
    
    public function __construct(
        private array $methods,
        private string $uri,
        private array|string|Closure $action
    ) {}
    
    /**
     * Get HTTP methods for this route
     */
    public function getMethods(): array
    {
        return $this->methods;
    }
    
    /**
     * Get route URI
     */
    public function getUri(): string
    {
        return $this->prefix . '/' . ltrim($this->uri, '/');
    }
    
    /**
     * Get raw URI without prefix
     */
    public function getRawUri(): string
    {
        return $this->uri;
    }
    
    /**
     * Get route action
     */
    public function getAction(): array|string|Closure
    {
        return $this->action;
    }
    
    /**
     * Add middleware to route
     */
    public function middleware(array|string $middleware): self
    {
        if (is_string($middleware)) {
            $middleware = [$middleware];
        }
        
        $this->middleware = array_merge($this->middleware, $middleware);
        
        return $this;
    }
    
    /**
     * Get route middleware
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }
    
    /**
     * Set route name
     */
    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }
    
    /**
     * Get route name
     */
    public function getName(): ?string
    {
        return $this->name;
    }
    
    /**
     * Set route namespace
     */
    public function namespace(string $namespace): self
    {
        $this->namespace = $namespace;
        return $this;
    }
    
    /**
     * Get route namespace
     */
    public function getNamespace(): ?string
    {
        return $this->namespace;
    }
    
    /**
     * Set route prefix
     */
    public function prefix(string $prefix): self
    {
        $this->prefix = '/' . trim($prefix, '/');
        return $this;
    }
    
    /**
     * Get route prefix
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }
    
    /**
     * Set route domain
     */
    public function domain(string $domain): self
    {
        $this->domain = $domain;
        return $this;
    }
    
    /**
     * Get route domain
     */
    public function getDomain(): ?string
    {
        return $this->domain;
    }
    
    /**
     * Set route parameters
     */
    public function setParameters(array $parameters): self
    {
        $this->parameters = $parameters;
        return $this;
    }
    
    /**
     * Get route parameters
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }
    
    /**
     * Get specific parameter
     */
    public function parameter(string $name, mixed $default = null): mixed
    {
        return $this->parameters[$name] ?? $default;
    }
    
    /**
     * Add parameter constraint
     */
    public function where(array|string $name, ?string $pattern = null): self
    {
        if (is_array($name)) {
            $this->constraints = array_merge($this->constraints, $name);
        } else {
            $this->constraints[$name] = $pattern;
        }
        
        return $this;
    }
    
    /**
     * Get parameter constraints
     */
    public function getConstraints(): array
    {
        return $this->constraints;
    }
    
    /**
     * Set default parameter values
     */
    public function defaults(array $defaults): self
    {
        $this->defaults = array_merge($this->defaults, $defaults);
        return $this;
    }
    
    /**
     * Get default parameter values
     */
    public function getDefaults(): array
    {
        return $this->defaults;
    }
    
    /**
     * Set route description for documentation
     */
    public function description(string $description): self
    {
        $this->description = $description;
        return $this;
    }
    
    /**
     * Get route description
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }
    
    /**
     * Add tags for categorization
     */
    public function tags(array|string $tags): self
    {
        if (is_string($tags)) {
            $tags = [$tags];
        }
        
        $this->tags = array_merge($this->tags, $tags);
        
        return $this;
    }
    
    /**
     * Get route tags
     */
    public function getTags(): array
    {
        return $this->tags;
    }
    
    /**
     * Mark route as deprecated
     */
    public function deprecated(bool $deprecated = true): self
    {
        $this->deprecated = $deprecated;
        return $this;
    }
    
    /**
     * Check if route is deprecated
     */
    public function isDeprecated(): bool
    {
        return $this->deprecated;
    }
    
    /**
     * Check if route matches given method
     */
    public function hasMethod(string $method): bool
    {
        return in_array(strtoupper($method), $this->methods);
    }
    
    /**
     * Check if route has middleware
     */
    public function hasMiddleware(string $middleware): bool
    {
        return in_array($middleware, $this->middleware);
    }
    
    /**
     * Get route signature for caching
     */
    public function getSignature(): string
    {
        return md5(serialize([
            'methods' => $this->methods,
            'uri' => $this->getUri(),
            'action' => $this->action,
            'middleware' => $this->middleware,
            'constraints' => $this->constraints,
            'defaults' => $this->defaults,
        ]));
    }
    
    /**
     * Convert route to array for serialization
     */
    public function toArray(): array
    {
        return [
            'methods' => $this->methods,
            'uri' => $this->getUri(),
            'raw_uri' => $this->uri,
            'action' => $this->serializeAction(),
            'middleware' => $this->middleware,
            'name' => $this->name,
            'namespace' => $this->namespace,
            'prefix' => $this->prefix,
            'domain' => $this->domain,
            'constraints' => $this->constraints,
            'defaults' => $this->defaults,
            'description' => $this->description,
            'tags' => $this->tags,
            'deprecated' => $this->deprecated,
        ];
    }
    
    /**
     * Serialize action for array conversion
     */
    private function serializeAction(): string
    {
        if ($this->action instanceof Closure) {
            return 'Closure';
        }
        
        if (is_array($this->action)) {
            return implode('@', $this->action);
        }
        
        return (string) $this->action;
    }
    
    /**
     * String representation of route
     */
    public function __toString(): string
    {
        return sprintf(
            '%s %s',
            implode('|', $this->methods),
            $this->getUri()
        );
    }
}