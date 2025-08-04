<?php

declare(strict_types=1);

namespace ERP\Core\Queue;

/**
 * Job Interface - Contrato para todos os jobs processáveis
 * 
 * @package ERP\Core\Queue
 */
interface JobInterface
{
    public function handle(): mixed;
    public function getData(): array;
    public function getTenantId(): string;
    public function getTags(): array;
    public function getMetadata(): array;
    public function getScheduledAt(): ?float;
    public function getTimeout(): int;
    public function getMaxRetries(): int;
}

/**
 * Abstract Job - Classe base para todos os jobs
 * 
 * @package ERP\Core\Queue
 */
abstract class Job implements JobInterface
{
    protected array $data;
    protected string $tenantId;
    protected array $tags = [];
    protected array $metadata = [];
    protected ?float $scheduledAt = null;
    protected int $timeout = 60;
    protected int $maxRetries = 3;
    
    public function __construct(array $data, string $tenantId = 'default')
    {
        $this->data = $data;
        $this->tenantId = $tenantId;
    }
    
    public function getData(): array
    {
        return $this->data;
    }
    
    public function getTenantId(): string
    {
        return $this->tenantId;
    }
    
    public function getTags(): array
    {
        return $this->tags;
    }
    
    public function getMetadata(): array
    {
        return $this->metadata;
    }
    
    public function getScheduledAt(): ?float
    {
        return $this->scheduledAt;
    }
    
    public function getTimeout(): int
    {
        return $this->timeout;
    }
    
    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }
    
    public function delay(int $seconds): self
    {
        $this->scheduledAt = microtime(true) + $seconds;
        return $this;
    }
    
    public function addTag(string $tag): self
    {
        $this->tags[] = $tag;
        return $this;
    }
    
    public function addMetadata(string $key, mixed $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }
    
    public function setTimeout(int $timeout): self
    {
        $this->timeout = $timeout;
        return $this;
    }
    
    public function setMaxRetries(int $maxRetries): self
    {
        $this->maxRetries = $maxRetries;
        return $this;
    }
    
    abstract public function handle(): mixed;
}

/**
 * Exemplo de Job concreto - Processamento de Email
 */
class SendEmailJob extends Job
{
    public function __construct(array $emailData, string $tenantId = 'default')
    {
        parent::__construct($emailData, $tenantId);
        $this->tags = ['email', 'notification'];
        $this->timeout = 30;
        $this->maxRetries = 2;
    }
    
    public function handle(): mixed
    {
        $to = $this->data['to'];
        $subject = $this->data['subject'];
        $body = $this->data['body'];
        
        // Simular envio de email
        sleep(1);
        
        return [
            'status' => 'sent',
            'message_id' => uniqid('msg_'),
            'sent_at' => time()
        ];
    }
}

/**
 * Job de Processamento de Relatório
 */
class GenerateReportJob extends Job
{
    public function __construct(array $reportData, string $tenantId = 'default')
    {
        parent::__construct($reportData, $tenantId);
        $this->tags = ['report', 'export'];
        $this->timeout = 300; // 5 minutos
        $this->maxRetries = 1;
    }
    
    public function handle(): mixed
    {
        $reportType = $this->data['type'];
        $dateRange = $this->data['date_range'];
        
        // Simular geração de relatório
        sleep(3);
        
        return [
            'status' => 'generated',
            'file_path' => '/storage/reports/' . uniqid('report_') . '.pdf',
            'generated_at' => time()
        ];
    }
}

/**
 * Job de Sincronização de Dados
 */
class SyncDataJob extends Job
{
    public function __construct(array $syncData, string $tenantId = 'default')
    {
        parent::__construct($syncData, $tenantId);
        $this->tags = ['sync', 'integration'];
        $this->timeout = 600; // 10 minutos
        $this->maxRetries = 5;
    }
    
    public function handle(): mixed
    {
        $source = $this->data['source'];
        $target = $this->data['target'];
        $recordCount = $this->data['record_count'] ?? 0;
        
        // Simular sincronização
        $processedRecords = 0;
        for ($i = 0; $i < $recordCount; $i++) {
            // Simular processamento
            usleep(10000); // 10ms por registro
            $processedRecords++;
        }
        
        return [
            'status' => 'synced',
            'records_processed' => $processedRecords,
            'synced_at' => time()
        ];
    }
}