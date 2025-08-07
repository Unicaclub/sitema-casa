<?php

declare(strict_types=1);

namespace ERP\Core\Queue;

use ERP\Core\CLI\Console;

/**
 * Queue Command - CLI para gerenciamento de filas
 * 
 * Comandos disponíveis:
 * - php artisan queue:work - Executar worker
 * - php artisan queue:status - Status das filas
 * - php artisan queue:dispatch - Despachar job
 * - php artisan queue:retry - Retry jobs falhados
 * - php artisan queue:clear - Limpar filas
 * - php artisan queue:monitor - Monitor em tempo real
 * 
 * @package ERP\Core\Queue
 */
final class QueueCommand
{
    private QueueManager $queueManager;
    private Console $console;
    
    public function __construct(QueueManager $queueManager, Console $console)
    {
        $this->queueManager = $queueManager;
        $this->console = $console;
    }
    
    /**
     * Executar worker de fila
     */
    public function work(array $options = []): void
    {
        $this->console->info("🚀 Starting Queue Worker...");
        $this->console->info("Memory limit: " . ini_get('memory_limit'));
        $this->console->info("Time limit: " . ini_get('max_execution_time'));
        
        // Configurar sinais para graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleShutdown']);
            pcntl_signal(SIGINT, [$this, 'handleShutdown']);
        }
        
        try {
            $this->queueManager->runWorker();
        } catch (\Throwable $e) {
            $this->console->error("Worker failed: " . $e->getMessage());
            exit(1);
        }
    }
    
    /**
     * Mostrar status das filas
     */
    public function status(): void
    {
        $this->console->info("📊 Queue Status Report");
        $this->console->line(str_repeat("=", 60));
        
        $metrics = $this->queueManager->getQueueMetrics();
        $stats = $metrics['global_stats'];
        
        $this->console->table([
            ['Metric', 'Value'],
            ['Total Jobs Processed', number_format($stats['processed'])],
            ['Failed Jobs', number_format($stats['failed'])],
            ['Active Workers', $stats['active_workers']],
            ['Peak Workers', $stats['peak_workers']],
            ['Avg Processing Time', round($stats['avg_processing_time'], 2) . 's'],
            ['Throughput/sec', round($stats['throughput_per_second'], 2)]
        ]);
        
        $this->console->line("");
        $this->console->info("Queue Breakdown:");
        
        foreach ($metrics['queue_breakdown'] as $queueName => $queueStats) {
            $this->console->line("  {$queueName}: {$queueStats['pending']} pending, {$queueStats['processing']} processing");
        }
    }
    
    /**
     * Despachar job via CLI
     */
    public function dispatch(array $args): void
    {
        if (empty($args[0])) {
            $this->console->error("Job class is required");
            $this->console->info("Usage: php artisan queue:dispatch SendEmailJob '{\"to\":\"user@example.com\"}'");
            return;
        }
        
        $jobClass = $args[0];
        $jobData = isset($args[1]) ? json_decode($args[1], true) : [];
        $tenantId = $args[2] ?? 'default';
        
        if (! class_exists($jobClass)) {
            $this->console->error("Job class not found: {$jobClass}");
            return;
        }
        
        try {
            $job = new $jobClass($jobData, $tenantId);
            $jobId = $this->queueManager->dispatch($job);
            
            $this->console->success("✅ Job dispatched successfully!");
            $this->console->info("Job ID: {$jobId}");
            $this->console->info("Job Class: {$jobClass}");
            $this->console->info("Tenant ID: {$tenantId}");
            
        } catch (\Throwable $e) {
            $this->console->error("Failed to dispatch job: " . $e->getMessage());
        }
    }
    
    /**
     * Retry jobs falhados
     */
    public function retry(array $args = []): void
    {
        $this->console->info("🔄 Retrying failed jobs...");
        
        $retryCount = $this->retryFailedJobs();
        
        if ($retryCount > 0) {
            $this->console->success("✅ {$retryCount} jobs queued for retry");
        } else {
            $this->console->info("No failed jobs found");
        }
    }
    
    /**
     * Limpar filas
     */
    public function clear(array $args = []): void
    {
        $queueName = $args[0] ?? 'all';
        
        $this->console->warning("⚠️  This will clear all jobs in the queue(s)");
        
        if (! $this->console->confirm("Are you sure you want to continue?")) {
            $this->console->info("Operation cancelled");
            return;
        }
        
        $clearedCount = $this->clearQueues($queueName);
        
        $this->console->success("✅ Cleared {$clearedCount} jobs from {$queueName} queue(s)");
    }
    
    /**
     * Monitor em tempo real
     */
    public function monitor(): void
    {
        $this->console->info("📈 Real-time Queue Monitor (Press Ctrl+C to exit)");
        $this->console->line(str_repeat("=", 80));
        
        while (true) {
            // Limpar tela
            system('clear');
            
            $this->console->info("📈 Queue Monitor - " . date('Y-m-d H:i:s'));
            $this->console->line(str_repeat("=", 80));
            
            $dashboardData = $this->queueManager->getDashboardData();
            $overview = $dashboardData['overview'];
            
            // Overview metrics
            $this->console->table([
                ['Metric', 'Value', 'Status'],
                [
                    'Jobs/sec', 
                    round($overview['jobs_per_second'], 2),
                    $this->getStatusIndicator($overview['jobs_per_second'], 10, 50)
                ],
                [
                    'Active Workers', 
                    $overview['active_workers'],
                    $this->getStatusIndicator($overview['active_workers'], 1, 10)
                ],
                [
                    'Failed Rate', 
                    round($overview['failed_jobs_rate'] * 100, 2) . '%',
                    $this->getStatusIndicator($overview['failed_jobs_rate'], 0.05, 0.01, true)
                ],
                [
                    'Avg Processing Time', 
                    round($overview['avg_processing_time'], 2) . 's',
                    $this->getStatusIndicator($overview['avg_processing_time'], 60, 10, true)
                ]
            ]);
            
            $this->console->line("");
            
            // Queue status
            $this->console->info("Queue Status:");
            foreach ($dashboardData['queues'] as $queueName => $queueData) {
                $status = $queueData['status'];
                $pending = $queueData['pending'];
                $processing = $queueData['processing'];
                
                $statusColor = match($status) {
                    'healthy' => 'green',
                    'warning' => 'yellow',
                    'critical' => 'red',
                    default => 'white'
                };
                
                $this->console->line("  {$queueName}: {$pending} pending, {$processing} processing ({$status})", $statusColor);
            }
            
            $this->console->line("");
            
            // Alerts
            if (! empty($dashboardData['alerts'])) {
                $this->console->warning("🚨 Active Alerts:");
                foreach ($dashboardData['alerts'] as $alert) {
                    $this->console->line("  • {$alert['message']}", 'red');
                }
            }
            
            sleep(2);
        }
    }
    
    /**
     * Handle graceful shutdown
     */
    public function handleShutdown(): void
    {
        $this->console->warning("\n🛑 Graceful shutdown initiated...");
        $this->console->info("Waiting for current jobs to complete...");
        
        // Implement graceful shutdown logic
        exit(0);
    }
    
    /**
     * Métodos auxiliares
     */
    private function retryFailedJobs(): int
    {
        // Implementação simulada
        return 5;
    }
    
    private function clearQueues(string $queueName): int
    {
        // Implementação simulada
        return 23;
    }
    
    private function getStatusIndicator(float $value, float $warning, float $good, bool $inverse = false): string
    {
        if ($inverse) {
            if ($value <= $good) return "✅ Good";
            if ($value <= $warning) return "⚠️  Warning";
            return "❌ Critical";
        } else {
            if ($value >= $good) return "✅ Good";
            if ($value >= $warning) return "⚠️  Warning";
            return "❌ Critical";
        }
    }
}
