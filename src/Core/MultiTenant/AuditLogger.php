<?php

namespace Core\MultiTenant;

use Core\Database\Database;
use Core\Logger;
use Core\Auth\Auth;

/**
 * AuditLogger - Sistema de auditoria multi-tenant
 * 
 * Responsável por registrar todas as ações realizadas no sistema
 * com contexto de tenant, garantindo rastreabilidade completa
 */
class AuditLogger
{
    private Database $database;
    private Logger $logger;
    private TenantManager $tenantManager;
    private Auth $auth;
    
    // Níveis de severidade
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';
    const LEVEL_CRITICAL = 'critical';
    
    // Categorias de eventos
    const CATEGORY_AUTH = 'authentication';
    const CATEGORY_DATA = 'data_operation';
    const CATEGORY_TENANT = 'tenant_operation';
    const CATEGORY_SECURITY = 'security';
    const CATEGORY_API = 'api_access';
    const CATEGORY_SYSTEM = 'system';
    
    public function __construct(
        Database $database,
        Logger $logger,
        TenantManager $tenantManager,
        Auth $auth
    ) {
        $this->database = $database;
        $this->logger = $logger;
        $this->tenantManager = $tenantManager;
        $this->auth = $auth;
    }
    
    /**
     * Registra evento de auditoria
     */
    public function log(
        string $action,
        string $category = self::CATEGORY_SYSTEM,
        string $level = self::LEVEL_INFO,
        array $details = [],
        ?int $tenantId = null,
        ?int $userId = null
    ): void {
        $tenantId = $tenantId ?? $this->tenantManager->getCurrentTenantId();
        $userId = $userId ?? $this->auth->id();
        
        $auditEntry = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'action' => $action,
            'category' => $category,
            'level' => $level,
            'details' => json_encode($details),
            'ip_address' => $this->getClientIp(),
            'user_agent' => $this->getUserAgent(),
            'session_id' => session_id() ?: null,
            'request_id' => $this->getRequestId(),
            'created_at' => now()
        ];
        
        try {
            // Salvar no banco de dados
            $this->database->table('audit_logs')->insert($auditEntry);
            
            // Log também no sistema de logs
            $this->logger->log($level, "Audit: {$action}", [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'category' => $category,
                'details' => $details
            ]);
            
        } catch (\Exception $e) {
            // Se falhar ao registrar auditoria, pelo menos loga o erro
            $this->logger->error('Failed to write audit log', [
                'error' => $e->getMessage(),
                'audit_action' => $action,
                'tenant_id' => $tenantId
            ]);
        }
    }
    
    /**
     * Registra evento de autenticação
     */
    public function logAuthentication(
        string $action,
        string $level = self::LEVEL_INFO,
        array $details = []
    ): void {
        $this->log($action, self::CATEGORY_AUTH, $level, $details);
    }
    
    /**
     * Registra operação de dados
     */
    public function logDataOperation(
        string $table,
        string $operation,
        int $recordId = null,
        array $oldData = [],
        array $newData = []
    ): void {
        $details = [
            'table' => $table,
            'operation' => $operation,
            'record_id' => $recordId,
            'old_data' => $oldData,
            'new_data' => $newData
        ];
        
        $this->log("data_{$operation}_on_{$table}", self::CATEGORY_DATA, self::LEVEL_INFO, $details);
    }
    
    /**
     * Registra evento de segurança
     */
    public function logSecurityEvent(
        string $event,
        string $level = self::LEVEL_WARNING,
        array $details = []
    ): void {
        $this->log($event, self::CATEGORY_SECURITY, $level, $details);
    }
    
    /**
     * Registra acesso cruzado entre tenants
     */
    public function logCrossTenantAccess(
        int $userId,
        ?int $currentTenantId,
        int $requestedTenantId,
        string $resource,
        bool $accessGranted = false
    ): void {
        $details = [
            'current_tenant_id' => $currentTenantId,
            'requested_tenant_id' => $requestedTenantId,
            'resource' => $resource,
            'access_granted' => $accessGranted
        ];
        
        $level = $accessGranted ? self::LEVEL_INFO : self::LEVEL_CRITICAL;
        $action = $accessGranted ? 'cross_tenant_access_granted' : 'cross_tenant_access_denied';
        
        $this->log($action, self::CATEGORY_SECURITY, $level, $details, $currentTenantId, $userId);
        
        // Registrar também na tabela específica de acesso cruzado
        $this->database->table('tenant_access_logs')->insert([
            'user_id' => $userId,
            'current_tenant_id' => $currentTenantId,
            'requested_tenant_id' => $requestedTenantId,
            'resource' => $resource,
            'ip_address' => $this->getClientIp(),
            'user_agent' => $this->getUserAgent(),
            'access_granted' => $accessGranted,
            'created_at' => now()
        ]);
    }
    
    /**
     * Registra operação de tenant
     */
    public function logTenantOperation(
        string $operation,
        int $targetTenantId,
        array $details = []
    ): void {
        $details['target_tenant_id'] = $targetTenantId;
        $this->log("tenant_{$operation}", self::CATEGORY_TENANT, self::LEVEL_INFO, $details);
    }
    
    /**
     * Registra acesso à API
     */
    public function logApiAccess(
        string $endpoint,
        string $method,
        int $statusCode,
        float $responseTime = null,
        array $details = []
    ): void {
        $details = array_merge($details, [
            'endpoint' => $endpoint,
            'method' => $method,
            'status_code' => $statusCode,
            'response_time_ms' => $responseTime ? round($responseTime * 1000, 2) : null
        ]);
        
        $level = $statusCode >= 400 ? self::LEVEL_WARNING : self::LEVEL_INFO;
        $this->log("api_access_{$method}_{$endpoint}", self::CATEGORY_API, $level, $details);
    }
    
    /**
     * Busca logs de auditoria com filtros
     */
    public function getLogs(
        ?int $tenantId = null,
        ?int $userId = null,
        ?string $category = null,
        ?string $level = null,
        ?\DateTime $startDate = null,
        ?\DateTime $endDate = null,
        int $limit = 100,
        int $offset = 0
    ): array {
        $query = $this->database->table('audit_logs')
            ->select([
                'id',
                'tenant_id',
                'user_id',
                'action',
                'category',
                'level',
                'details',
                'ip_address',
                'user_agent',
                'created_at'
            ]);
        
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }
        
        if ($userId) {
            $query->where('user_id', $userId);
        }
        
        if ($category) {
            $query->where('category', $category);
        }
        
        if ($level) {
            $query->where('level', $level);
        }
        
        if ($startDate) {
            $query->where('created_at', '>=', $startDate->format('Y-m-d H:i:s'));
        }
        
        if ($endDate) {
            $query->where('created_at', '<=', $endDate->format('Y-m-d H:i:s'));
        }
        
        return $query->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();
    }
    
    /**
     * Obtém estatísticas de auditoria por tenant
     */
    public function getTenantAuditStats(int $tenantId, int $days = 30): array
    {
        $startDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        // Total de eventos por categoria
        $categoryStats = $this->database->table('audit_logs')
            ->select(['category', $this->database->raw('COUNT(*) as count')])
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $startDate)
            ->groupBy('category')
            ->get();
        
        // Eventos por nível de severidade
        $levelStats = $this->database->table('audit_logs')
            ->select(['level', $this->database->raw('COUNT(*) as count')])
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $startDate)
            ->groupBy('level')
            ->get();
        
        // Usuários mais ativos
        $activeUsers = $this->database->table('audit_logs')
            ->select([
                'user_id',
                'users.name',
                $this->database->raw('COUNT(*) as activity_count')
            ])
            ->leftJoin('users', 'audit_logs.user_id', '=', 'users.id')
            ->where('audit_logs.tenant_id', $tenantId)
            ->where('audit_logs.created_at', '>=', $startDate)
            ->groupBy('user_id', 'users.name')
            ->orderBy('activity_count', 'DESC')
            ->limit(10)
            ->get();
        
        // Eventos de segurança
        $securityEvents = $this->database->table('audit_logs')
            ->where('tenant_id', $tenantId)
            ->where('category', self::CATEGORY_SECURITY)
            ->where('created_at', '>=', $startDate)
            ->count();
        
        // Tentativas de acesso cruzado
        $crossTenantAttempts = $this->database->table('tenant_access_logs')
            ->where('current_tenant_id', $tenantId)
            ->where('access_granted', false)
            ->where('created_at', '>=', $startDate)
            ->count();
        
        return [
            'tenant_id' => $tenantId,
            'period_days' => $days,
            'category_stats' => $categoryStats,
            'level_stats' => $levelStats,
            'active_users' => $activeUsers,
            'security_events' => $securityEvents,
            'cross_tenant_attempts' => $crossTenantAttempts,
            'generated_at' => now()
        ];
    }
    
    /**
     * Detecta atividades suspeitas
     */
    public function detectSuspiciousActivity(int $tenantId, int $hours = 24): array
    {
        $startTime = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));
        $suspicious = [];
        
        // Múltiplas tentativas de acesso cruzado
        $crossTenantAttempts = $this->database->table('tenant_access_logs')
            ->select([
                'user_id',
                'ip_address',
                $this->database->raw('COUNT(*) as attempts')
            ])
            ->where('current_tenant_id', $tenantId)
            ->where('access_granted', false)
            ->where('created_at', '>=', $startTime)
            ->groupBy('user_id', 'ip_address')
            ->having('attempts', '>=', 5)
            ->get();
        
        if (count($crossTenantAttempts) > 0) {
            $suspicious[] = [
                'type' => 'multiple_cross_tenant_attempts',
                'description' => 'Múltiplas tentativas de acesso cruzado entre tenants',
                'data' => $crossTenantAttempts
            ];
        }
        
        // Logins fora do horário normal
        $offHoursLogins = $this->database->table('audit_logs')
            ->where('tenant_id', $tenantId)
            ->where('action', 'user_login')
            ->where('created_at', '>=', $startTime)
            ->where($this->database->raw('HOUR(created_at)'), '<', 6)
            ->orWhere($this->database->raw('HOUR(created_at)'), '>', 22)
            ->count();
        
        if ($offHoursLogins > 0) {
            $suspicious[] = [
                'type' => 'off_hours_access',
                'description' => "Logins fora do horário comercial: {$offHoursLogins}",
                'count' => $offHoursLogins
            ];
        }
        
        // Muitas operações de dados em pouco tempo
        $bulkDataOps = $this->database->table('audit_logs')
            ->where('tenant_id', $tenantId)
            ->where('category', self::CATEGORY_DATA)
            ->where('created_at', '>=', $startTime)
            ->count();
        
        if ($bulkDataOps > 1000) {
            $suspicious[] = [
                'type' => 'bulk_data_operations',
                'description' => "Alto volume de operações de dados: {$bulkDataOps}",
                'count' => $bulkDataOps
            ];
        }
        
        return $suspicious;
    }
    
    /**
     * Exporta logs de auditoria para CSV
     */
    public function exportLogs(
        int $tenantId,
        ?\DateTime $startDate = null,
        ?\DateTime $endDate = null
    ): string {
        $logs = $this->getLogs($tenantId, null, null, null, $startDate, $endDate, 10000);
        
        $csv = "Date,User ID,Action,Category,Level,IP Address,Details\n";
        
        foreach ($logs as $log) {
            $details = json_decode($log['details'], true);
            $detailsStr = is_array($details) ? json_encode($details) : $log['details'];
            
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,\"%s\"\n",
                $log['created_at'],
                $log['user_id'] ?? 'N/A',
                $log['action'],
                $log['category'],
                $log['level'],
                $log['ip_address'] ?? 'N/A',
                str_replace('"', '""', $detailsStr)
            );
        }
        
        return $csv;
    }
    
    /**
     * Obtém IP do cliente
     */
    private function getClientIp(): string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 
               $_SERVER['HTTP_X_REAL_IP'] ?? 
               $_SERVER['REMOTE_ADDR'] ?? 
               'unknown';
    }
    
    /**
     * Obtém User Agent
     */
    private function getUserAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    }
    
    /**
     * Obtém ID único da requisição
     */
    private function getRequestId(): string
    {
        return $_SERVER['HTTP_X_REQUEST_ID'] ?? uniqid('req_', true);
    }
    
    /**
     * Obtém timestamp atual
     */
    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}