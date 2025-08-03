<?php

namespace ERP\Core;

/**
 * Classe principal da aplicação ERP
 * Gerencia inicialização, roteamento e dependências
 */
class App 
{
    private static $instance = null;
    private $container;
    private $router;
    private $config;
    private $modules = [];
    
    private function __construct()
    {
        $this->loadConfig();
        $this->initContainer();
        $this->initRouter();
        $this->loadModules();
    }
    
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Carrega configurações do sistema
     */
    private function loadConfig(): void
    {
        $configPath = __DIR__ . '/../../config/';
        $this->config = [
            'database' => require $configPath . 'database.php',
            'auth' => require $configPath . 'auth.php',
            'cache' => require $configPath . 'cache.php',
            'modules' => require $configPath . 'modules.php',
            'security' => require $configPath . 'security.php'
        ];
    }
    
    /**
     * Inicializa container de dependências
     */
    private function initContainer(): void
    {
        $this->container = new Container();
        
        // Registro de serviços core
        $this->container->bind('database', function() {
            return new Database($this->config['database']);
        });
        
        $this->container->bind('auth', function() {
            return new Auth($this->config['auth']);
        });
        
        $this->container->bind('cache', function() {
            return new Cache($this->config['cache']);
        });
        
        $this->container->bind('logger', function() {
            return new Logger();
        });
        
        $this->container->bind('eventBus', function() {
            return new EventBus();
        });
    }
    
    /**
     * Inicializa sistema de roteamento
     */
    private function initRouter(): void
    {
        $this->router = new Router($this->container);
        
        // Middleware global
        $this->router->addMiddleware(new SecurityMiddleware());
        $this->router->addMiddleware(new AuthMiddleware());
        $this->router->addMiddleware(new CacheMiddleware());
        $this->router->addMiddleware(new LoggingMiddleware());
    }
    
    /**
     * Carrega todos os módulos do sistema
     */
    private function loadModules(): void
    {
        $moduleConfig = $this->config['modules'];
        
        foreach ($moduleConfig['enabled'] as $moduleName) {
            $moduleClass = "\\ERP\\Modules\\{$moduleName}\\{$moduleName}Module";
            
            if (class_exists($moduleClass)) {
                $module = new $moduleClass($this->container);
                $module->register();
                $this->modules[$moduleName] = $module;
            }
        }
    }
    
    /**
     * Executa a aplicação
     */
    public function run(): void
    {
        try {
            // Log início da requisição
            $this->container->get('logger')->info('Request started', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'uri' => $_SERVER['REQUEST_URI'],
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            
            // Processa a requisição
            $response = $this->router->handle();
            
            // Envia resposta
            $response->send();
            
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Trata exceções globais
     */
    private function handleException(\Throwable $e): void
    {
        $this->container->get('logger')->error('Unhandled exception', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
        
        if ($this->config['debug'] ?? false) {
            echo "<pre>" . $e->getTraceAsString() . "</pre>";
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }
    
    /**
     * Obtém instância de serviço do container
     */
    public function get(string $service)
    {
        return $this->container->get($service);
    }
    
    /**
     * Obtém configuração
     */
    public function config(string $key = null)
    {
        if ($key === null) {
            return $this->config;
        }
        
        $keys = explode('.', $key);
        $value = $this->config;
        
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return null;
            }
            $value = $value[$k];
        }
        
        return $value;
    }
    
    /**
     * Obtém módulo registrado
     */
    public function getModule(string $name)
    {
        return $this->modules[$name] ?? null;
    }
    
    /**
     * Lista todos os módulos carregados
     */
    public function getModules(): array
    {
        return $this->modules;
    }
}

/**
 * Container de Injeção de Dependências
 */
class Container 
{
    private $bindings = [];
    private $instances = [];
    
    public function bind(string $key, \Closure $factory): void
    {
        $this->bindings[$key] = $factory;
    }
    
    public function singleton(string $key, \Closure $factory): void
    {
        $this->bind($key, function() use ($key, $factory) {
            if (!isset($this->instances[$key])) {
                $this->instances[$key] = $factory();
            }
            return $this->instances[$key];
        });
    }
    
    public function get(string $key)
    {
        if (!isset($this->bindings[$key])) {
            throw new \Exception("Service {$key} not found in container");
        }
        
        return $this->bindings[$key]();
    }
    
    public function has(string $key): bool
    {
        return isset($this->bindings[$key]);
    }
}
