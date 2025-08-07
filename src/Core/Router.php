<?php

namespace ERP\Core;

/**
 * Sistema de Roteamento Modular
 * Suporta middleware, grupos de rotas e módulos
 */
class Router 
{
    private $routes = [];
    private $middleware = [];
    private $container;
    private $currentGroup = '';
    private $groupMiddleware = [];
    
    public function __construct(Container $container)
    {
        $this->container = $container;
    }
    
    /**
     * Adiciona middleware global
     */
    public function addMiddleware($middleware): void
    {
        $this->middleware[] = $middleware;
    }
    
    /**
     * Grupo de rotas com prefixo e middleware
     */
    public function group(string $prefix, array $middleware, \Closure $callback): void
    {
        $previousGroup = $this->currentGroup;
        $previousMiddleware = $this->groupMiddleware;
        
        $this->currentGroup = trim($previousGroup . '/' . $prefix, '/');
        $this->groupMiddleware = array_merge($this->groupMiddleware, $middleware);
        
        $callback($this);
        
        $this->currentGroup = $previousGroup;
        $this->groupMiddleware = $previousMiddleware;
    }
    
    /**
     * Registra rota GET
     */
    public function get(string $path, $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }
    
    /**
     * Registra rota POST
     */
    public function post(string $path, $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }
    
    /**
     * Registra rota PUT
     */
    public function put(string $path, $handler): void
    {
        $this->addRoute('PUT', $path, $handler);
    }
    
    /**
     * Registra rota DELETE
     */
    public function delete(string $path, $handler): void
    {
        $this->addRoute('DELETE', $path, $handler);
    }
    
    /**
     * Registra rota PATCH
     */
    public function patch(string $path, $handler): void
    {
        $this->addRoute('PATCH', $path, $handler);
    }
    
    /**
     * Adiciona rota ao sistema
     */
    private function addRoute(string $method, string $path, $handler): void
    {
        $fullPath = '/' . trim($this->currentGroup . '/' . $path, '/');
        $fullPath = $fullPath === '/' ? '/' : rtrim($fullPath, '/');
        
        $this->routes[] = [
            'method' => $method,
            'path' => $fullPath,
            'handler' => $handler,
            'middleware' => $this->groupMiddleware,
            'pattern' => $this->pathToRegex($fullPath)
        ];
    }
    
    /**
     * Converte path para regex
     */
    private function pathToRegex(string $path): string
    {
        // Substitui parâmetros {id} por regex
        $pattern = preg_replace('/\{([^}]+)\}/', '(?P<$1>[^/]+)', $path);
        return '#^' . $pattern . '$#';
    }
    
    /**
     * Processa requisição
     */
    public function handle(): Response
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        
        // Encontra rota correspondente
        $route = $this->findRoute($method, $path);
        
        if (! $route) {
            return new Response(['error' => 'Route not found'], 404);
        }
        
        // Extrai parâmetros da URL
        $params = $this->extractParams($route, $path);
        
        // Cria request object
        $request = new Request($params);
        
        // Executa middleware
        $middlewareStack = array_merge($this->middleware, $route['middleware']);
        
        return $this->executeMiddleware($middlewareStack, $request, function($request) use ($route) {
            return $this->executeHandler($route['handler'], $request);
        });
    }
    
    /**
     * Encontra rota correspondente
     */
    private function findRoute(string $method, string $path): ?array
    {
        foreach ($this->routes as $route) {
            if ($route['method'] === $method && preg_match($route['pattern'], $path)) {
                return $route;
            }
        }
        
        return null;
    }
    
    /**
     * Extrai parâmetros da URL
     */
    private function extractParams(array $route, string $path): array
    {
        $matches = [];
        preg_match($route['pattern'], $path, $matches);
        
        // Remove matches numéricos, mantém apenas nomeados
        return array_filter($matches, function($key) {
            return !is_numeric($key);
        }, ARRAY_FILTER_USE_KEY);
    }
    
    /**
     * Executa middleware em cadeia
     */
    private function executeMiddleware(array $middleware, Request $request, \Closure $next): Response
    {
        if (empty($middleware)) {
            return $next($request);
        }
        
        $current = array_shift($middleware);
        
        return $current->handle($request, function($request) use ($middleware, $next) {
            return $this->executeMiddleware($middleware, $request, $next);
        });
    }
    
    /**
     * Executa handler da rota
     */
    private function executeHandler($handler, Request $request): Response
    {
        if (is_string($handler)) {
            // Controller@method format
            if (strpos($handler, '@') !== false) {
                [$controller, $method] = explode('@', $handler);
                $controllerInstance = new $controller($this->container);
                return $controllerInstance->$method($request);
            }
            
            // Function name
            return $handler($request);
        }
        
        if (is_callable($handler)) {
            return $handler($request);
        }
        
        if (is_array($handler)) {
            [$controller, $method] = $handler;
            $controllerInstance = new $controller($this->container);
            return $controllerInstance->$method($request);
        }
        
        throw new \Exception('Invalid route handler');
    }
    
    /**
     * Lista todas as rotas registradas
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}

/**
 * Classe Request
 */
class Request 
{
    private $params;
    private $query;
    private $body;
    private $headers;
    private $files;
    
    public function __construct(array $params = [])
    {
        $this->params = $params;
        $this->query = $_GET;
        $this->body = $this->parseBody();
        $this->headers = $this->parseHeaders();
        $this->files = $_FILES;
    }
    
    private function parseBody()
    {
        $contentType = $this->header('content-type');
        
        if (strpos($contentType, 'application/json') !== false) {
            return json_decode(file_get_contents('php://input'), true) ?? [];
        }
        
        return $_POST;
    }
    
    private function parseHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $header = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$header] = $value;
            }
        }
        return $headers;
    }
    
    public function param(string $key, $default = null)
    {
        return $this->params[$key] ?? $default;
    }
    
    public function query(string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->query;
        }
        return $this->query[$key] ?? $default;
    }
    
    public function input(string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->body;
        }
        return $this->body[$key] ?? $default;
    }
    
    public function header(string $key, $default = null)
    {
        return $this->headers[strtolower($key)] ?? $default;
    }
    
    public function file(string $key)
    {
        return $this->files[$key] ?? null;
    }
    
    public function method(): string
    {
        return $_SERVER['REQUEST_METHOD'];
    }
    
    public function path(): string
    {
        return parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    }
    
    public function isAjax(): bool
    {
        return $this->header('x-requested-with') === 'XMLHttpRequest';
    }
    
    public function isJson(): bool
    {
        return strpos($this->header('content-type'), 'application/json') !== false;
    }
}

/**
 * Classe Response
 */
class Response 
{
    private $data;
    private $statusCode;
    private $headers;
    
    public function __construct($data = null, int $statusCode = 200, array $headers = [])
    {
        $this->data = $data;
        $this->statusCode = $statusCode;
        $this->headers = array_merge([
            'Content-Type' => 'application/json',
            'X-Powered-By' => 'ERP-Sistema'
        ], $headers);
    }
    
    public function send(): void
    {
        http_response_code($this->statusCode);
        
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        
        if ($this->data !== null) {
            if ($this->headers['Content-Type'] === 'application/json') {
                echo json_encode($this->data, JSON_UNESCAPED_UNICODE);
            } else {
                echo $this->data;
            }
        }
    }
    
    public function json($data): self
    {
        $this->data = $data;
        $this->headers['Content-Type'] = 'application/json';
        return $this;
    }
    
    public function html(string $html): self
    {
        $this->data = $html;
        $this->headers['Content-Type'] = 'text/html';
        return $this;
    }
    
    public function redirect(string $url, int $statusCode = 302): self
    {
        $this->statusCode = $statusCode;
        $this->headers['Location'] = $url;
        return $this;
    }
    
    public function status(int $statusCode): self
    {
        $this->statusCode = $statusCode;
        return $this;
    }
    
    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }
}
