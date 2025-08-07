<?php

namespace ERP\Core;

/**
 * Sistema de Eventos para Comunicação entre Módulos
 * Suporta listeners síncronos e assíncronos
 */
class EventBus 
{
    private $listeners = [];
    private $logger;
    
    public function __construct()
    {
        $this->logger = App::getInstance()->get('logger');
    }
    
    /**
     * Registra listener para evento
     */
    public function listen(string $event, $listener, int $priority = 0): void
    {
        if (! isset($this->listeners[$event])) {
            $this->listeners[$event] = [];
        }
        
        $this->listeners[$event][] = [
            'listener' => $listener,
            'priority' => $priority
        ];
        
        // Ordena por prioridade (maior primeiro)
        usort($this->listeners[$event], function($a, $b) {
            return $b['priority'] - $a['priority'];
        });
    }
    
    /**
     * Dispara evento síncrono
     */
    public function emit(string $event, $data = null): array
    {
        $results = [];
        
        if (! isset($this->listeners[$event])) {
            return $results;
        }
        
        $this->logger->debug("Event emitted: {$event}", [
            'event' => $event,
            'listeners_count' => count($this->listeners[$event]),
            'data_type' => gettype($data)
        ]);
        
        foreach ($this->listeners[$event] as $listenerData) {
            try {
                $startTime = microtime(true);
                $result = $this->executeListener($listenerData['listener'], $event, $data);
                $duration = microtime(true) - $startTime;
                
                $results[] = $result;
                
                $this->logger->debug("Event listener executed", [
                    'event' => $event,
                    'listener' => $this->getListenerName($listenerData['listener']),
                    'duration_ms' => round($duration * 1000, 2)
                ]);
                
            } catch (\Throwable $e) {
                $this->logger->error("Event listener failed", [
                    'event' => $event,
                    'listener' => $this->getListenerName($listenerData['listener']),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                // Continua executando outros listeners mesmo se um falhar
                continue;
            }
        }
        
        return $results;
    }
    
    /**
     * Dispara evento assíncrono (via queue)
     */
    public function emitAsync(string $event, $data = null): void
    {
        // Por simplicidade, vamos simular queue salvando no banco
        // Em produção, usar Redis Queue ou RabbitMQ
        
        try {
            $database = App::getInstance()->get('database');
            
            $database->insert('event_queue', [
                'event' => $event,
                'data' => json_encode($data),
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s'),
                'scheduled_at' => date('Y-m-d H:i:s')
            ]);
            
            $this->logger->info("Event queued for async processing", [
                'event' => $event,
                'data_size' => strlen(json_encode($data))
            ]);
            
        } catch (\Throwable $e) {
            $this->logger->error("Failed to queue event", [
                'event' => $event,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Processa eventos em queue
     */
    public function processQueue(int $batchSize = 10): int
    {
        $database = App::getInstance()->get('database');
        
        $events = $database->table('event_queue')
            ->where('status', 'pending')
            ->where('scheduled_at', '<=', date('Y-m-d H:i:s'))
            ->orderBy('created_at')
            ->limit($batchSize)
            ->get();
        
        $processed = 0;
        
        foreach ($events as $event) {
            try {
                // Marca como processando
                $database->update('event_queue', [
                    'status' => 'processing',
                    'processed_at' => date('Y-m-d H:i:s')
                ], ['id' => $event['id']]);
                
                // Processa evento
                $data = json_decode($event['data'], true);
                $this->emit($event['event'], $data);
                
                // Marca como concluído
                $database->update('event_queue', [
                    'status' => 'completed',
                    'completed_at' => date('Y-m-d H:i:s')
                ], ['id' => $event['id']]);
                
                $processed++;
                
            } catch (\Throwable $e) {
                // Marca como falha
                $database->update('event_queue', [
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                    'failed_at' => date('Y-m-d H:i:s')
                ], ['id' => $event['id']]);
                
                $this->logger->error("Failed to process queued event", [
                    'event_id' => $event['id'],
                    'event' => $event['event'],
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $processed;
    }
    
    /**
     * Executa listener
     */
    private function executeListener($listener, string $event, $data)
    {
        if (is_callable($listener)) {
            return $listener($event, $data);
        }
        
        if (is_string($listener)) {
            if (class_exists($listener)) {
                $instance = new $listener();
                return $instance->handle($event, $data);
            }
            
            if (function_exists($listener)) {
                return $listener($event, $data);
            }
        }
        
        if (is_array($listener) && count($listener) === 2) {
            [$class, $method] = $listener;
            
            if (is_string($class)) {
                $class = new $class();
            }
            
            return $class->$method($event, $data);
        }
        
        throw new \InvalidArgumentException('Invalid listener format');
    }
    
    /**
     * Obtém nome do listener para log
     */
    private function getListenerName($listener): string
    {
        if (is_callable($listener)) {
            if (is_array($listener)) {
                $class = is_object($listener[0]) ? get_class($listener[0]) : $listener[0];
                return $class . '::' . $listener[1];
            }
            
            if (is_object($listener)) {
                return get_class($listener);
            }
            
            return $listener;
        }
        
        if (is_string($listener)) {
            return $listener;
        }
        
        return 'unknown';
    }
    
    /**
     * Remove listener
     */
    public function unlisten(string $event, $listener): void
    {
        if (! isset($this->listeners[$event])) {
            return;
        }
        
        $this->listeners[$event] = array_filter(
            $this->listeners[$event],
            function($listenerData) use ($listener) {
                return $listenerData['listener'] !== $listener;
            }
        );
    }
    
    /**
     * Remove todos os listeners de um evento
     */
    public function unlistenAll(string $event): void
    {
        unset($this->listeners[$event]);
    }
    
    /**
     * Lista eventos registrados
     */
    public function getEvents(): array
    {
        return array_keys($this->listeners);
    }
    
    /**
     * Lista listeners de um evento
     */
    public function getListeners(string $event): array
    {
        return $this->listeners[$event] ?? [];
    }
    
    /**
     * Verifica se evento tem listeners
     */
    public function hasListeners(string $event): bool
    {
        return isset($this->listeners[$event]) && ! empty($this->listeners[$event]);
    }
}

/**
 * Classe base para Event Listeners
 */
abstract class EventListener 
{
    protected $logger;
    
    public function __construct()
    {
        $this->logger = App::getInstance()->get('logger');
    }
    
    /**
     * Método que deve ser implementado pelos listeners
     */
    abstract public function handle(string $event, $data);
    
    /**
     * Verifica se listener deve processar o evento
     */
    protected function shouldHandle(string $event, $data): bool
    {
        return true;
    }
}

/**
 * Eventos padrão do sistema
 */
class SystemEvents 
{
    // Eventos de usuário
    const USER_LOGGED_IN = 'user.logged_in';
    const USER_LOGGED_OUT = 'user.logged_out';
    const USER_CREATED = 'user.created';
    const USER_UPDATED = 'user.updated';
    const USER_DELETED = 'user.deleted';
    
    // Eventos de cliente
    const CLIENT_CREATED = 'client.created';
    const CLIENT_UPDATED = 'client.updated';
    const CLIENT_DELETED = 'client.deleted';
    
    // Eventos de venda
    const SALE_CREATED = 'sale.created';
    const SALE_UPDATED = 'sale.updated';
    const SALE_CANCELLED = 'sale.cancelled';
    const SALE_COMPLETED = 'sale.completed';
    
    // Eventos de estoque
    const PRODUCT_CREATED = 'product.created';
    const PRODUCT_UPDATED = 'product.updated';
    const PRODUCT_DELETED = 'product.deleted';
    const STOCK_LOW = 'stock.low';
    const STOCK_OUT = 'stock.out';
    const STOCK_MOVEMENT = 'stock.movement';
    
    // Eventos financeiros
    const PAYMENT_RECEIVED = 'payment.received';
    const PAYMENT_FAILED = 'payment.failed';
    const INVOICE_CREATED = 'invoice.created';
    const INVOICE_OVERDUE = 'invoice.overdue';
    
    // Eventos de sistema
    const BACKUP_COMPLETED = 'system.backup_completed';
    const BACKUP_FAILED = 'system.backup_failed';
    const MAINTENANCE_MODE = 'system.maintenance_mode';
    const INTEGRATION_FAILED = 'system.integration_failed';
}

/**
 * Listener exemplo para notificações
 */
class NotificationListener extends EventListener 
{
    public function handle(string $event, $data)
    {
        if (! $this->shouldHandle($event, $data)) {
            return;
        }
        
        switch ($event) {
            case SystemEvents::STOCK_LOW:
                $this->sendStockAlert($data);
                break;
                
            case SystemEvents::SALE_CREATED:
                $this->sendSaleNotification($data);
                break;
                
            case SystemEvents::PAYMENT_RECEIVED:
                $this->sendPaymentConfirmation($data);
                break;
        }
    }
    
    private function sendStockAlert($data): void
    {
        // Implementar notificação de estoque baixo
        $this->logger->info('Stock alert sent', ['product_id' => $data['product_id']]);
    }
    
    private function sendSaleNotification($data): void
    {
        // Implementar notificação de venda
        $this->logger->info('Sale notification sent', ['sale_id' => $data['sale_id']]);
    }
    
    private function sendPaymentConfirmation($data): void
    {
        // Implementar confirmação de pagamento
        $this->logger->info('Payment confirmation sent', ['payment_id' => $data['payment_id']]);
    }
}
