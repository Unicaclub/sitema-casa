<?php

declare(strict_types=1);

namespace ERP\Core\Queue;

use ERP\Core\Http\Request;
use ERP\Core\Http\Response;

/**
 * Queue Controller - API REST para gerenciamento de filas
 * 
 * Endpoints disponíveis:
 * - GET /api/queue/dashboard - Dashboard de métricas
 * - GET /api/queue/metrics - Métricas detalhadas
 * - POST /api/queue/dispatch - Despachar novo job
 * - GET /api/queue/jobs/{id} - Status de job específico
 * - DELETE /api/queue/jobs/{id} - Cancelar job
 * - POST /api/queue/retry/{id} - Retry job falhado
 * - GET /api/queue/workers - Status dos workers
 * - POST /api/queue/workers/scale - Scale workers
 * 
 * @package ERP\Core\Queue
 */
final class QueueController
{
    private QueueManager $queueManager;
    
    public function __construct(QueueManager $queueManager)
    {
        $this->queueManager = $queueManager;
    }
    
    /**
     * Dashboard de métricas em tempo real
     */
    public function dashboard(Request $request): Response
    {
        try {
            $dashboardData = $this->queueManager->getDashboardData();
            
            return new Response(json_encode([
                'status' => 'success',
                'data' => $dashboardData,
                'timestamp' => time()
            ], JSON_THROW_ON_ERROR), 200, [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-cache, no-store, must-revalidate'
            ]);
            
        } catch (\Throwable $e) {
            return new Response(json_encode([
                'status' => 'error',
                'message' => 'Failed to fetch dashboard data',
                'error' => $e->getMessage()
            ], JSON_THROW_ON_ERROR), 500, [
                'Content-Type' => 'application/json'
            ]);
        }
    }
    
    /**
     * Métricas detalhadas das filas
     */
    public function metrics(Request $request): Response
    {
        try {
            $metrics = $this->queueManager->getQueueMetrics();
            
            return new Response(json_encode([
                'status' => 'success',
                'data' => $metrics,
                'timestamp' => time()
            ], JSON_THROW_ON_ERROR), 200, [
                'Content-Type' => 'application/json'
            ]);
            
        } catch (\Throwable $e) {
            return new Response(json_encode([
                'status' => 'error',
                'message' => 'Failed to fetch metrics',
                'error' => $e->getMessage()
            ], JSON_THROW_ON_ERROR), 500, [
                'Content-Type' => 'application/json'
            ]);
        }
    }
    
    /**
     * Despachar novo job
     */
    public function dispatch(Request $request): Response
    {
        try {
            $payload = json_decode($request->getBody(), true);
            
            // Validar payload
            if (!isset($payload['job_class']) || !isset($payload['data'])) {
                return new Response(json_encode([
                    'status' => 'error',
                    'message' => 'Missing required fields: job_class, data'
                ], JSON_THROW_ON_ERROR), 400, [
                    'Content-Type' => 'application/json'
                ]);
            }
            
            // Criar job
            $jobClass = $payload['job_class'];
            $jobData = $payload['data'];
            $tenantId = $payload['tenant_id'] ?? 'default';
            
            if (!class_exists($jobClass)) {
                return new Response(json_encode([
                    'status' => 'error',
                    'message' => "Job class not found: {$jobClass}"
                ], JSON_THROW_ON_ERROR), 400, [
                    'Content-Type' => 'application/json'
                ]);
            }
            
            $job = new $jobClass($jobData, $tenantId);
            
            // Configurações opcionais
            if (isset($payload['delay'])) {
                $job->delay($payload['delay']);
            }
            
            if (isset($payload['timeout'])) {
                $job->setTimeout($payload['timeout']);
            }
            
            if (isset($payload['max_retries'])) {
                $job->setMaxRetries($payload['max_retries']);
            }
            
            if (isset($payload['tags'])) {
                foreach ($payload['tags'] as $tag) {
                    $job->addTag($tag);
                }
            }
            
            // Despachar job
            $jobId = $this->queueManager->dispatch($job);
            
            return new Response(json_encode([
                'status' => 'success',
                'message' => 'Job dispatched successfully',
                'data' => [
                    'job_id' => $jobId,
                    'job_class' => $jobClass,
                    'tenant_id' => $tenantId,
                    'dispatched_at' => time()
                ]
            ], JSON_THROW_ON_ERROR), 201, [
                'Content-Type' => 'application/json'
            ]);
            
        } catch (\Throwable $e) {
            return new Response(json_encode([
                'status' => 'error',
                'message' => 'Failed to dispatch job',
                'error' => $e->getMessage()
            ], JSON_THROW_ON_ERROR), 500, [
                'Content-Type' => 'application/json'
            ]);
        }
    }
    
    /**
     * Obter status de job específico
     */
    public function getJobStatus(Request $request): Response
    {
        try {
            $jobId = $request->getParameter('id');
            
            if (!$jobId) {
                return new Response(json_encode([
                    'status' => 'error',
                    'message' => 'Job ID is required'
                ], JSON_THROW_ON_ERROR), 400, [
                    'Content-Type' => 'application/json'
                ]);
            }
            
            // Buscar status do job (implementação simulada)
            $jobStatus = $this->getJobStatusFromRedis($jobId);
            
            if (!$jobStatus) {
                return new Response(json_encode([
                    'status' => 'error',
                    'message' => 'Job not found'
                ], JSON_THROW_ON_ERROR), 404, [
                    'Content-Type' => 'application/json'
                ]);
            }
            
            return new Response(json_encode([
                'status' => 'success',
                'data' => $jobStatus
            ], JSON_THROW_ON_ERROR), 200, [
                'Content-Type' => 'application/json'
            ]);
            
        } catch (\Throwable $e) {
            return new Response(json_encode([
                'status' => 'error',
                'message' => 'Failed to fetch job status',
                'error' => $e->getMessage()
            ], JSON_THROW_ON_ERROR), 500, [
                'Content-Type' => 'application/json'
            ]);
        }
    }
    
    /**
     * Cancelar job
     */
    public function cancelJob(Request $request): Response
    {
        try {
            $jobId = $request->getParameter('id');
            
            if (!$jobId) {
                return new Response(json_encode([
                    'status' => 'error',
                    'message' => 'Job ID is required'
                ], JSON_THROW_ON_ERROR), 400, [
                    'Content-Type' => 'application/json'
                ]);
            }
            
            // Cancelar job (implementação simulada)
            $cancelled = $this->cancelJobInRedis($jobId);
            
            if (!$cancelled) {
                return new Response(json_encode([
                    'status' => 'error',
                    'message' => 'Job not found or already processed'
                ], JSON_THROW_ON_ERROR), 404, [
                    'Content-Type' => 'application/json'
                ]);
            }
            
            return new Response(json_encode([
                'status' => 'success',
                'message' => 'Job cancelled successfully',
                'data' => [
                    'job_id' => $jobId,
                    'cancelled_at' => time()
                ]
            ], JSON_THROW_ON_ERROR), 200, [
                'Content-Type' => 'application/json'
            ]);
            
        } catch (\Throwable $e) {
            return new Response(json_encode([
                'status' => 'error',
                'message' => 'Failed to cancel job',
                'error' => $e->getMessage()
            ], JSON_THROW_ON_ERROR), 500, [
                'Content-Type' => 'application/json'
            ]);
        }
    }
    
    /**
     * Retry job falhado
     */
    public function retryJob(Request $request): Response
    {
        try {
            $jobId = $request->getParameter('id');
            
            if (!$jobId) {
                return new Response(json_encode([
                    'status' => 'error',
                    'message' => 'Job ID is required'
                ], JSON_THROW_ON_ERROR), 400, [
                    'Content-Type' => 'application/json'
                ]);
            }
            
            // Retry job (implementação simulada)
            $retried = $this->retryJobInRedis($jobId);
            
            if (!$retried) {
                return new Response(json_encode([
                    'status' => 'error',
                    'message' => 'Job not found or not in failed state'
                ], JSON_THROW_ON_ERROR), 404, [
                    'Content-Type' => 'application/json'
                ]);
            }
            
            return new Response(json_encode([
                'status' => 'success',
                'message' => 'Job queued for retry',
                'data' => [
                    'job_id' => $jobId,
                    'retried_at' => time()
                ]
            ], JSON_THROW_ON_ERROR), 200, [
                'Content-Type' => 'application/json'
            ]);
            
        } catch (\Throwable $e) {
            return new Response(json_encode([
                'status' => 'error',
                'message' => 'Failed to retry job',
                'error' => $e->getMessage()
            ], JSON_THROW_ON_ERROR), 500, [
                'Content-Type' => 'application/json'
            ]);
        }
    }
    
    /**
     * Status dos workers
     */
    public function getWorkersStatus(Request $request): Response
    {
        try {
            $workersStatus = $this->getWorkersStatusFromRedis();
            
            return new Response(json_encode([
                'status' => 'success',
                'data' => $workersStatus,
                'timestamp' => time()
            ], JSON_THROW_ON_ERROR), 200, [
                'Content-Type' => 'application/json'
            ]);
            
        } catch (\Throwable $e) {
            return new Response(json_encode([
                'status' => 'error',
                'message' => 'Failed to fetch workers status',
                'error' => $e->getMessage()
            ], JSON_THROW_ON_ERROR), 500, [
                'Content-Type' => 'application/json'
            ]);
        }
    }
    
    /**
     * Scale workers
     */
    public function scaleWorkers(Request $request): Response
    {
        try {
            $payload = json_decode($request->getBody(), true);
            
            if (!isset($payload['action']) || !in_array($payload['action'], ['up', 'down'])) {
                return new Response(json_encode([
                    'status' => 'error',
                    'message' => 'Invalid action. Use "up" or "down"'
                ], JSON_THROW_ON_ERROR), 400, [
                    'Content-Type' => 'application/json'
                ]);
            }
            
            $action = $payload['action'];
            $queue = $payload['queue'] ?? 'default';
            $count = $payload['count'] ?? 1;
            
            // Executar scaling (implementação simulada)
            $result = $this->executeScaling($action, $queue, $count);
            
            return new Response(json_encode([
                'status' => 'success',
                'message' => "Workers scaled {$action} successfully",
                'data' => $result
            ], JSON_THROW_ON_ERROR), 200, [
                'Content-Type' => 'application/json'
            ]);
            
        } catch (\Throwable $e) {
            return new Response(json_encode([
                'status' => 'error',
                'message' => 'Failed to scale workers',
                'error' => $e->getMessage()
            ], JSON_THROW_ON_ERROR), 500, [
                'Content-Type' => 'application/json'
            ]);
        }
    }
    
    // Métodos auxiliares (implementação simulada)
    private function getJobStatusFromRedis(string $jobId): ?array
    {
        return [
            'job_id' => $jobId,
            'status' => 'completed',
            'progress' => 100,
            'created_at' => time() - 300,
            'started_at' => time() - 250,
            'completed_at' => time() - 200,
            'attempts' => 1,
            'result' => ['status' => 'success']
        ];
    }
    
    private function cancelJobInRedis(string $jobId): bool
    {
        return true; // Simulado
    }
    
    private function retryJobInRedis(string $jobId): bool
    {
        return true; // Simulado
    }
    
    private function getWorkersStatusFromRedis(): array
    {
        return [
            'total_workers' => 15,
            'active_workers' => 12,
            'idle_workers' => 3,
            'workers' => [
                [
                    'id' => 'worker_1',
                    'status' => 'active',
                    'current_job' => 'SendEmailJob',
                    'processed_jobs' => 234,
                    'started_at' => time() - 3600,
                    'memory_usage' => '45MB',
                    'cpu_usage' => '12%'
                ],
                [
                    'id' => 'worker_2', 
                    'status' => 'idle',
                    'current_job' => null,
                    'processed_jobs' => 189,
                    'started_at' => time() - 2400,
                    'memory_usage' => '32MB',
                    'cpu_usage' => '2%'
                ]
            ]
        ];
    }
    
    private function executeScaling(string $action, string $queue, int $count): array
    {
        return [
            'action' => $action,
            'queue' => $queue,
            'count' => $count,
            'previous_worker_count' => 15,
            'new_worker_count' => $action === 'up' ? 15 + $count : 15 - $count,
            'scaled_at' => time()
        ];
    }
}