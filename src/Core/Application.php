<?php

declare(strict_types=1);

namespace ERP\Core;

use ERP\Core\Container\Container;
use ERP\Core\Database\DatabaseManager;
use ERP\Core\Events\EventDispatcher;
use ERP\Core\Http\Router;
use ERP\Core\Http\Request;
use ERP\Core\Http\Response;
use ERP\Core\Auth\AuthManager;
use ERP\Core\Cache\CacheManager;
use ERP\Core\Logging\Logger;
use ERP\Core\Security\SecurityManager;
use ERP\Core\Exceptions\ApplicationException;
use ERP\Core\Config\ConfigManager;
use ERP\Core\Providers\ServiceProvider;
use ERP\Modules\ModuleRegistry;
use Throwable;

/**
 * Core Application Class - Enterprise ERP System
 * 
 * Arquitetura modular PHP 8.2+ com recursos avançados:
 * - Multi-tenant architecture
 * - Service Container with auto-wiring
 * - Event-driven architecture
 * - Middleware pipeline
 * - Module system
 * - Advanced security
 * 
 * @package ERP\Core
 * @author ERP Enterprise Team
 * @version 2.0.0
 */
final readonly class Application
{
    private static ?Application $instance = null;
    
    public function __construct(
        private Container $container,
        private ConfigManager $config,
        private Router $router,
        private DatabaseManager $database,
        private CacheManager $cache,
        private Logger $logger,
        private EventDispatcher $events,
        private SecurityManager $security,
        private AuthManager $auth,
        private ModuleRegistry $modules
    ) {
        $this->registerCoreServices();
        $this->registerServiceProviders();
        $this->bootModules();
    }
    
    /**
     * Create application instance with dependency injection
     */
    public static function create(string $basePath = null): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }
        
        $basePath ??= dirname(__DIR__, 2);
        
        // Initialize core components
        $config = new ConfigManager($basePath);
        $container = new Container();
        $logger = new Logger($config->get('logging', []));
        $cache = new CacheManager($config->get('cache', []));
        $database = new DatabaseManager($config->get('database', []));
        $events = new EventDispatcher($container);
        $security = new SecurityManager($config->get('security', []), $logger, $cache);
        $auth = new AuthManager($database, $cache, $security, $config->get('auth', []));
        $router = new Router($container, $events, $security);
        $modules = new ModuleRegistry($container, $config, $logger);
        
        self::$instance = new self(
            $container,
            $config,
            $router,
            $database,
            $cache,
            $logger,
            $events,
            $security,
            $auth,
            $modules
        );
        
        return self::$instance;
    }
    
    /**
     * Run the application
     */
    public function run(): void
    {
        try {
            $this->logger->info('Application starting', [
                'php_version' => PHP_VERSION,
                'memory_limit' => ini_get('memory_limit'),
                'time_limit' => ini_get('max_execution_time')
            ]);
            
            // Create request from globals
            $request = Request::createFromGlobals();
            
            // Log request
            $this->logRequest($request);
            
            // Process request through router
            $response = $this->router->dispatch($request);
            
            // Send response
            $response->send();
            
            $this->logger->info('Request completed successfully', [
                'status_code' => $response->getStatusCode(),
                'memory_usage' => memory_get_peak_usage(true),
                'execution_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']
            ]);
            
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * Register core services in container
     */
    private function registerCoreServices(): void
    {
        // Register singletons
        $this->container->singleton('config', fn() => $this->config);
        $this->container->singleton('database', fn() => $this->database);
        $this->container->singleton('cache', fn() => $this->cache);
        $this->container->singleton('logger', fn() => $this->logger);
        $this->container->singleton('events', fn() => $this->events);
        $this->container->singleton('security', fn() => $this->security);
        $this->container->singleton('auth', fn() => $this->auth);
        $this->container->singleton('router', fn() => $this->router);
        $this->container->singleton('modules', fn() => $this->modules);
        
        // Register application instance
        $this->container->instance('app', $this);
    }
    
    /**
     * Register service providers
     */
    private function registerServiceProviders(): void
    {
        $providers = $this->config->get('app.providers', [
            \ERP\Core\Providers\DatabaseServiceProvider::class,
            \ERP\Core\Providers\CacheServiceProvider::class,
            \ERP\Core\Providers\SecurityServiceProvider::class,
            \ERP\Core\Providers\AuthServiceProvider::class,
            \ERP\Core\Providers\ValidationServiceProvider::class,
            \ERP\Core\Providers\MailServiceProvider::class,
            \ERP\Core\Providers\QueueServiceProvider::class,
            \ERP\Core\Providers\FileStorageServiceProvider::class,
        ]);
        
        foreach ($providers as $providerClass) {
            if (class_exists($providerClass)) {
                /** @var ServiceProvider $provider */
                $provider = new $providerClass($this->container, $this->config);
                $provider->register();
                $provider->boot();
                
                $this->logger->debug("Service provider registered: {$providerClass}");
            }
        }
    }
    
    /**
     * Boot all modules
     */
    private function bootModules(): void
    {
        $enabledModules = $this->config->get('modules.enabled', [
            'Dashboard',
            'CRM',
            'Inventory',
            'Sales',
            'Financial',
            'Reports',
            'System'
        ]);
        
        foreach ($enabledModules as $moduleName) {
            try {
                $this->modules->register($moduleName);
                $this->logger->info("Module loaded: {$moduleName}");
            } catch (Throwable $e) {
                $this->logger->error("Failed to load module: {$moduleName}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
    }
    
    /**
     * Log incoming request
     */
    private function logRequest(Request $request): void
    {
        $this->logger->info('Incoming request', [
            'method' => $request->getMethod(),
            'uri' => $request->getUri(),
            'ip' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent'),
            'content_type' => $request->headers->get('Content-Type'),
            'content_length' => $request->headers->get('Content-Length'),
            'accepts' => $request->headers->get('Accept'),
            'tenant_id' => $request->headers->get('X-Tenant-ID'),
        ]);
        
        // Log request body for POST/PUT/PATCH (excluding sensitive data)
        if (in_array($request->getMethod(), ['POST', 'PUT', 'PATCH']) && 
            $request->headers->get('Content-Type', '') === 'application/json') {
            
            $body = $request->getContent();
            if ($body && strlen($body) < 10000) { // Limit body size for logging
                $data = json_decode($body, true);
                if ($data) {
                    // Remove sensitive fields
                    $sanitized = $this->sanitizeLogData($data);
                    $this->logger->debug('Request body', $sanitized);
                }
            }
        }
    }
    
    /**
     * Sanitize log data by removing sensitive information
     */
    private function sanitizeLogData(array $data): array
    {
        $sensitiveFields = ['password', 'token', 'secret', 'key', 'credential'];
        
        array_walk_recursive($data, function (&$value, $key) use ($sensitiveFields) {
            if (is_string($key) && in_array(strtolower($key), $sensitiveFields)) {
                $value = '***REDACTED***';
            }
        });
        
        return $data;
    }
    
    /**
     * Handle uncaught exceptions
     */
    private function handleException(Throwable $e): void
    {
        $this->logger->error('Uncaught exception', [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ]);
        
        // Determine response based on environment and exception type
        $isDevelopment = $this->config->get('app.debug', false);
        $statusCode = $e instanceof ApplicationException ? $e->getHttpStatusCode() : 500;
        
        if ($isDevelopment) {
            $response = $this->createDevelopmentErrorResponse($e, $statusCode);
        } else {
            $response = $this->createProductionErrorResponse($e, $statusCode);
        }
        
        $response->send();
    }
    
    /**
     * Create development error response with full details
     */
    private function createDevelopmentErrorResponse(Throwable $e, int $statusCode): Response
    {
        $data = [
            'error' => true,
            'message' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => explode("\n", $e->getTraceAsString()),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ];
        
        return new Response(json_encode($data, JSON_PRETTY_PRINT), $statusCode, [
            'Content-Type' => 'application/json',
            'X-Debug-Mode' => 'true'
        ]);
    }
    
    /**
     * Create production error response without sensitive details
     */
    private function createProductionErrorResponse(Throwable $e, int $statusCode): Response
    {
        $messages = [
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable'
        ];
        
        $message = $messages[$statusCode] ?? 'Unknown Error';
        
        if ($e instanceof ApplicationException && $e->isUserFriendly()) {
            $message = $e->getMessage();
        }
        
        $data = [
            'error' => true,
            'message' => $message,
            'code' => $statusCode,
            'timestamp' => date('c'),
            'request_id' => uniqid('req_', true)
        ];
        
        return new Response(json_encode($data), $statusCode, [
            'Content-Type' => 'application/json',
            'X-Request-ID' => $data['request_id']
        ]);
    }
    
    /**
     * Get container instance
     */
    public function getContainer(): Container
    {
        return $this->container;
    }
    
    /**
     * Get service from container
     */
    public function get(string $id): mixed
    {
        return $this->container->get($id);
    }
    
    /**
     * Get configuration value
     */
    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config->get($key, $default);
    }
    
    /**
     * Get application version
     */
    public function version(): string
    {
        return '2.0.0-enterprise';
    }
    
    /**
     * Check if application is in debug mode
     */
    public function isDebug(): bool
    {
        return $this->config->get('app.debug', false);
    }
    
    /**
     * Get application environment
     */
    public function environment(): string
    {
        return $this->config->get('app.env', 'production');
    }
    
    /**
     * Terminate the application
     */
    public function terminate(): void
    {
        $this->events->dispatch('app.terminating', ['app' => $this]);
        
        // Close database connections
        $this->database->disconnect();
        
        // Close cache connections
        $this->cache->disconnect();
        
        $this->logger->info('Application terminated');
    }
}