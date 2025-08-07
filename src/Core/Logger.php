<?php

namespace ERP\Core;

/**
 * Sistema de Logging Estruturado
 * Suporta múltiplos drivers e contexto empresarial
 */
class Logger 
{
    private $config;
    private $handlers = [];
    private $context = [];
    
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'default' => 'file',
            'level' => 'info',
            'channels' => [
                'file' => [
                    'driver' => 'file',
                    'path' => __DIR__ . '/../../storage/logs/app.log',
                    'level' => 'debug'
                ],
                'database' => [
                    'driver' => 'database',
                    'table' => 'logs',
                    'level' => 'info'
                ]
            ]
        ], $config);
        
        $this->initHandlers();
    }
    
    /**
     * Inicializa handlers de log
     */
    private function initHandlers(): void
    {
        foreach ($this->config['channels'] as $name => $config) {
            switch ($config['driver']) {
                case 'file':
                    $this->handlers[$name] = new FileLogHandler($config);
                    break;
                case 'database':
                    $this->handlers[$name] = new DatabaseLogHandler($config);
                    break;
            }
        }
    }
    
    /**
     * Log de emergência
     */
    public function emergency(string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }
    
    /**
     * Log de alerta
     */
    public function alert(string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }
    
    /**
     * Log crítico
     */
    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }
    
    /**
     * Log de erro
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }
    
    /**
     * Log de warning
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }
    
    /**
     * Log de notice
     */
    public function notice(string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }
    
    /**
     * Log informativo
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }
    
    /**
     * Log de debug
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }
    
    /**
     * Log genérico
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $record = $this->createRecord($level, $message, $context);
        
        foreach ($this->handlers as $handler) {
            if ($handler->shouldHandle($level)) {
                $handler->handle($record);
            }
        }
    }
    
    /**
     * Cria registro de log
     */
    private function createRecord(string $level, string $message, array $context): array
    {
        $auth = App::getInstance()->get('auth');
        
        return [
            'level' => $level,
            'message' => $message,
            'context' => array_merge($this->context, $context),
            'datetime' => date('Y-m-d H:i:s'),
            'user_id' => $auth->id(),
            'company_id' => $auth->companyId(),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'request_id' => $this->getRequestId(),
            'memory_usage' => memory_get_peak_usage(true),
            'execution_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']
        ];
    }
    
    /**
     * Adiciona contexto global
     */
    public function withContext(array $context): self
    {
        $this->context = array_merge($this->context, $context);
        return $this;
    }
    
    /**
     * Obtém ID único da requisição
     */
    private function getRequestId(): string
    {
        static $requestId = null;
        
        if ($requestId === null) {
            $requestId = uniqid('req_', true);
        }
        
        return $requestId;
    }
    
    /**
     * Log de ação do usuário (auditoria)
     */
    public function audit(string $action, array $data = []): void
    {
        $this->info("User action: {$action}", array_merge([
            'action' => $action,
            'module' => $data['module'] ?? 'unknown',
            'entity_type' => $data['entity_type'] ?? null,
            'entity_id' => $data['entity_id'] ?? null,
            'old_values' => $data['old_values'] ?? null,
            'new_values' => $data['new_values'] ?? null
        ], $data));
    }
    
    /**
     * Log de performance
     */
    public function performance(string $operation, float $duration, array $context = []): void
    {
        $this->info("Performance: {$operation}", array_merge([
            'operation' => $operation,
            'duration_ms' => round($duration * 1000, 2),
            'memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
        ], $context));
    }
    
    /**
     * Log de segurança
     */
    public function security(string $event, array $context = []): void
    {
        $this->warning("Security event: {$event}", array_merge([
            'event' => $event,
            'severity' => $context['severity'] ?? 'medium'
        ], $context));
    }
}

/**
 * Handler para arquivo
 */
class FileLogHandler 
{
    private $config;
    private $levels = [
        'emergency' => 0,
        'alert' => 1,
        'critical' => 2,
        'error' => 3,
        'warning' => 4,
        'notice' => 5,
        'info' => 6,
        'debug' => 7
    ];
    
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->ensureDirectoryExists();
    }
    
    /**
     * Verifica se deve tratar o log
     */
    public function shouldHandle(string $level): bool
    {
        $configLevel = $this->levels[$this->config['level']] ?? 7;
        $recordLevel = $this->levels[$level] ?? 7;
        
        return $recordLevel <= $configLevel;
    }
    
    /**
     * Trata o registro de log
     */
    public function handle(array $record): void
    {
        $line = $this->formatRecord($record);
        
        // Rotação de logs por data
        $filename = $this->getFilename();
        
        file_put_contents($filename, $line, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Formata registro para arquivo
     */
    private function formatRecord(array $record): string
    {
        $contextJson = !empty($record['context']) ? json_encode($record['context'], JSON_UNESCAPED_UNICODE) : '{}';
        
        return sprintf(
            "[%s] %s.%s: %s %s %s\n",
            $record['datetime'],
            strtoupper($record['level']),
            $record['request_id'],
            $record['message'],
            $contextJson,
            $this->formatExtraInfo($record)
        );
    }
    
    /**
     * Formata informações extras
     */
    private function formatExtraInfo(array $record): string
    {
        $parts = [];
        
        if ($record['user_id']) {
            $parts[] = "user:{$record['user_id']}";
        }
        
        if ($record['company_id']) {
            $parts[] = "company:{$record['company_id']}";
        }
        
        if ($record['ip_address']) {
            $parts[] = "ip:{$record['ip_address']}";
        }
        
        $parts[] = "mem:" . round($record['memory_usage'] / 1024 / 1024, 1) . "MB";
        $parts[] = "time:" . round($record['execution_time'] * 1000, 1) . "ms";
        
        return '[' . implode(' ', $parts) . ']';
    }
    
    /**
     * Obtém nome do arquivo com rotação
     */
    private function getFilename(): string
    {
        $path = dirname($this->config['path']);
        $basename = pathinfo($this->config['path'], PATHINFO_FILENAME);
        $extension = pathinfo($this->config['path'], PATHINFO_EXTENSION);
        
        return $path . '/' . $basename . '-' . date('Y-m-d') . '.' . $extension;
    }
    
    /**
     * Garante que diretório existe
     */
    private function ensureDirectoryExists(): void
    {
        $dir = dirname($this->config['path']);
        
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}

/**
 * Handler para banco de dados
 */
class DatabaseLogHandler 
{
    private $config;
    private $levels = [
        'emergency' => 0,
        'alert' => 1,
        'critical' => 2,
        'error' => 3,
        'warning' => 4,
        'notice' => 5,
        'info' => 6,
        'debug' => 7
    ];
    
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    
    /**
     * Verifica se deve tratar o log
     */
    public function shouldHandle(string $level): bool
    {
        $configLevel = $this->levels[$this->config['level']] ?? 7;
        $recordLevel = $this->levels[$level] ?? 7;
        
        return $recordLevel <= $configLevel;
    }
    
    /**
     * Trata o registro de log
     */
    public function handle(array $record): void
    {
        try {
            $database = App::getInstance()->get('database');
            
            $database->insert($this->config['table'], [
                'level' => $record['level'],
                'message' => $record['message'],
                'context' => json_encode($record['context']),
                'user_id' => $record['user_id'],
                'company_id' => $record['company_id'],
                'ip_address' => $record['ip_address'],
                'user_agent' => $record['user_agent'],
                'request_id' => $record['request_id'],
                'memory_usage' => $record['memory_usage'],
                'execution_time' => $record['execution_time'],
                'created_at' => $record['datetime']
            ]);
        } catch (\Throwable $e) {
            // Falha silenciosa para evitar loops de erro
            error_log("Failed to log to database: " . $e->getMessage());
        }
    }
}

/**
 * Middleware de Logging
 */
class LoggingMiddleware 
{
    public function handle(Request $request, \Closure $next): Response
    {
        $startTime = microtime(true);
        $logger = App::getInstance()->get('logger');
        
        // Log da requisição
        $logger->info('Request started', [
            'method' => $request->method(),
            'path' => $request->path(),
            'query' => $request->query(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);
        
        try {
            $response = $next($request);
            
            // Log da resposta
            $duration = microtime(true) - $startTime;
            $logger->info('Request completed', [
                'method' => $request->method(),
                'path' => $request->path(),
                'status' => $response->getStatusCode(),
                'duration_ms' => round($duration * 1000, 2)
            ]);
            
            return $response;
            
        } catch (\Throwable $e) {
            // Log do erro
            $duration = microtime(true) - $startTime;
            $logger->error('Request failed', [
                'method' => $request->method(),
                'path' => $request->path(),
                'error' => $e->getMessage(),
                'duration_ms' => round($duration * 1000, 2),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        }
    }
}
