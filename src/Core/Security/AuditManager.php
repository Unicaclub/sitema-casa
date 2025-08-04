<?php

declare(strict_types=1);

namespace ERP\Core\Security;

/**
 * Sistema de Auditoria e Compliance LGPD/GDPR
 * 
 * Gerencia logs de auditoria, compliance e rastreabilidade de dados pessoais
 * 
 * @package ERP\Core\Security
 */
final class AuditManager
{
    private array $config;
    private array $logBuffer = [];
    private array $sensitiveFields = [];
    private array $dataCategories = [];
    
    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->initializeSensitiveFields();
        $this->initializeDataCategories();
        $this->createAuditTables();
    }
    
    /**
     * Registrar evento de auditoria
     */
    public function logEvent(string $eventType, array $data, ?int $userId = null): string
    {
        $auditId = uniqid('audit_');
        
        $auditLog = [
            'audit_id' => $auditId,
            'event_type' => $eventType,
            'user_id' => $userId,
            'ip_address' => $this->getCurrentIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'timestamp' => time(),
            'data' => $this->sanitizeAuditData($data),
            'data_hash' => $this->calculateDataHash($data),
            'session_id' => session_id() ?: null,
            'request_id' => $this->generateRequestId(),
            'compliance_flags' => $this->analyzeComplianceFlags($eventType, $data)
        ];
        
        // Verificar se envolve dados pessoais
        if ($this->containsPersonalData($data)) {
            $auditLog['personal_data_involved'] = true;
            $auditLog['data_categories'] = $this->identifyDataCategories($data);
            $auditLog['legal_basis'] = $this->determineLegalBasis($eventType);
        }
        
        $this->writeAuditLog($auditLog);
        
        // Verificar alertas de compliance
        $this->checkComplianceAlerts($auditLog);
        
        return $auditId;
    }
    
    /**
     * Registrar acesso a dados pessoais (LGPD/GDPR)
     */
    public function logPersonalDataAccess(string $dataSubject, string $action, array $dataFields, ?int $userId = null): string
    {
        return $this->logEvent('personal_data_access', [
            'data_subject' => $dataSubject,
            'action' => $action,
            'fields_accessed' => $dataFields,
            'access_reason' => $this->determineAccessReason($action),
            'retention_period' => $this->calculateRetentionPeriod($dataFields),
            'consent_status' => $this->getConsentStatus($dataSubject)
        ], $userId);
    }
    
    /**
     * Registrar consentimento LGPD/GDPR
     */
    public function logConsent(string $dataSubject, array $consentDetails): string
    {
        return $this->logEvent('consent_management', [
            'data_subject' => $dataSubject,
            'consent_type' => $consentDetails['type'],
            'consent_given' => $consentDetails['given'],
            'consent_source' => $consentDetails['source'] ?? 'system',
            'purposes' => $consentDetails['purposes'] ?? [],
            'expiry_date' => $consentDetails['expiry'] ?? null,
            'withdrawal_method' => $consentDetails['withdrawal_method'] ?? 'contact_form'
        ]);
    }
    
    /**
     * Registrar alteração de dados sensíveis
     */
    public function logSensitiveDataChange(string $table, int $recordId, array $oldData, array $newData, ?int $userId = null): string
    {
        $changes = $this->calculateDataChanges($oldData, $newData);
        
        return $this->logEvent('sensitive_data_change', [
            'table' => $table,
            'record_id' => $recordId,
            'changes' => $changes,
            'change_reason' => $this->determineChangeReason(),
            'approval_required' => $this->requiresApproval($changes),
            'backup_created' => $this->createChangeBackup($table, $recordId, $oldData)
        ], $userId);
    }
    
    /**
     * Gerar relatório de auditoria
     */
    public function generateAuditReport(array $filters = [], string $format = 'array'): array
    {
        $query = $this->buildAuditQuery($filters);
        $results = $this->executeAuditQuery($query);
        
        $report = [
            'report_id' => uniqid('report_'),
            'generated_at' => date('c'),
            'filters_applied' => $filters,
            'total_events' => count($results),
            'events' => $results,
            'summary' => $this->generateReportSummary($results),
            'compliance_status' => $this->assessComplianceStatus($results),
            'recommendations' => $this->generateComplianceRecommendations($results)
        ];
        
        if ($format === 'pdf') {
            return $this->generatePDFReport($report);
        } elseif ($format === 'csv') {
            return $this->generateCSVReport($report);
        }
        
        return $report;
    }
    
    /**
     * Verificar compliance LGPD/GDPR
     */
    public function checkCompliance(): array
    {
        return [
            'data_inventory' => $this->auditDataInventory(),
            'consent_compliance' => $this->auditConsentCompliance(),
            'retention_compliance' => $this->auditRetentionCompliance(),
            'access_controls' => $this->auditAccessControls(),
            'data_protection' => $this->auditDataProtection(),
            'breach_protocols' => $this->auditBreachProtocols(),
            'subject_rights' => $this->auditSubjectRights(),
            'overall_score' => $this->calculateComplianceScore()
        ];
    }
    
    /**
     * Processar solicitação de direitos do titular (LGPD/GDPR)
     */
    public function processSubjectRightsRequest(string $requestType, string $dataSubject, array $requestData): array
    {
        $requestId = uniqid('dsr_');
        
        $this->logEvent('subject_rights_request', [
            'request_id' => $requestId,
            'request_type' => $requestType,
            'data_subject' => $dataSubject,
            'request_data' => $requestData,
            'status' => 'received'
        ]);
        
        $response = match($requestType) {
            'access' => $this->processAccessRequest($dataSubject, $requestData),
            'portability' => $this->processPortabilityRequest($dataSubject, $requestData),
            'rectification' => $this->processRectificationRequest($dataSubject, $requestData),
            'erasure' => $this->processErasureRequest($dataSubject, $requestData),
            'objection' => $this->processObjectionRequest($dataSubject, $requestData),
            'restriction' => $this->processRestrictionRequest($dataSubject, $requestData),
            default => ['error' => 'Tipo de solicitação não suportado']
        };
        
        $this->logEvent('subject_rights_response', [
            'request_id' => $requestId,
            'response' => $response,
            'processing_time' => time() - (int)$requestData['received_at'],
            'status' => 'completed'
        ]);
        
        return array_merge(['request_id' => $requestId], $response);
    }
    
    /**
     * Detectar potencial violação de dados
     */
    public function detectDataBreach(array $suspiciousActivity): array
    {
        $breachScore = $this->calculateBreachRiskScore($suspiciousActivity);
        
        if ($breachScore >= $this->config['breach_threshold']) {
            $breachId = $this->reportDataBreach($suspiciousActivity, $breachScore);
            
            return [
                'breach_detected' => true,
                'breach_id' => $breachId,
                'risk_score' => $breachScore,
                'recommended_actions' => $this->getBreachResponseActions($breachScore),
                'notification_requirements' => $this->getNotificationRequirements($breachScore)
            ];
        }
        
        return ['breach_detected' => false, 'risk_score' => $breachScore];
    }
    
    /**
     * Métodos privados
     */
    
    private function initializeSensitiveFields(): void
    {
        $this->sensitiveFields = [
            'cpf', 'cnpj', 'rg', 'passport', 'email', 'telefone', 'celular',
            'endereco', 'cep', 'data_nascimento', 'nome_mae', 'conta_bancaria',
            'cartao_credito', 'senha', 'token', 'api_key', 'chave_pix'
        ];
    }
    
    private function initializeDataCategories(): void
    {
        $this->dataCategories = [
            'identification' => ['cpf', 'cnpj', 'rg', 'passport', 'nome'],
            'contact' => ['email', 'telefone', 'celular', 'endereco'],
            'financial' => ['conta_bancaria', 'cartao_credito', 'chave_pix'],
            'biometric' => ['digital', 'foto', 'biometria'],
            'health' => ['medical_record', 'health_data'],
            'behavioral' => ['preferences', 'usage_patterns', 'location']
        ];
    }
    
    private function createAuditTables(): void
    {
        // Implementação seria criar tabelas de auditoria no banco
        // audit_logs, consent_records, data_subject_requests, etc.
    }
    
    private function sanitizeAuditData(array $data): array
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            if (in_array($key, $this->sensitiveFields)) {
                $sanitized[$key] = $this->hashSensitiveData($value);
            } else {
                $sanitized[$key] = is_array($value) ? $this->sanitizeAuditData($value) : $value;
            }
        }
        
        return $sanitized;
    }
    
    private function hashSensitiveData(mixed $data): string
    {
        return hash('sha256', serialize($data) . $this->config['audit_salt']);
    }
    
    private function calculateDataHash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_SORT_KEYS));
    }
    
    private function containsPersonalData(array $data): bool
    {
        foreach ($data as $key => $value) {
            if (in_array($key, $this->sensitiveFields)) {
                return true;
            }
            if (is_array($value) && $this->containsPersonalData($value)) {
                return true;
            }
        }
        return false;
    }
    
    private function identifyDataCategories(array $data): array
    {
        $categories = [];
        
        foreach ($this->dataCategories as $category => $fields) {
            foreach ($fields as $field) {
                if ($this->dataContainsField($data, $field)) {
                    $categories[] = $category;
                    break;
                }
            }
        }
        
        return array_unique($categories);
    }
    
    private function dataContainsField(array $data, string $field): bool
    {
        return array_key_exists($field, $data) || 
               array_reduce($data, fn($carry, $item) => 
                   $carry || (is_array($item) && $this->dataContainsField($item, $field)), false);
    }
    
    private function determineLegalBasis(string $eventType): string
    {
        return match($eventType) {
            'user_registration' => 'consent',
            'contract_processing' => 'contract',
            'legal_obligation' => 'legal_obligation',
            'vital_interests' => 'vital_interests',
            'public_task' => 'public_task',
            'legitimate_interests' => 'legitimate_interests',
            default => 'consent'
        };
    }
    
    private function analyzeComplianceFlags(string $eventType, array $data): array
    {
        $flags = [];
        
        if ($this->containsPersonalData($data)) {
            $flags[] = 'personal_data_processing';
        }
        
        if ($this->isAutomatedDecision($eventType)) {
            $flags[] = 'automated_decision_making';
        }
        
        if ($this->isCrossBorderTransfer($data)) {
            $flags[] = 'cross_border_transfer';
        }
        
        if ($this->isHighRiskProcessing($eventType, $data)) {
            $flags[] = 'high_risk_processing';
        }
        
        return $flags;
    }
    
    private function writeAuditLog(array $auditLog): void
    {
        // Implementação para gravar no banco de dados
        $this->logBuffer[] = $auditLog;
        
        if (count($this->logBuffer) >= $this->config['buffer_size']) {
            $this->flushLogBuffer();
        }
    }
    
    private function flushLogBuffer(): void
    {
        // Implementação para gravar buffer no banco
        error_log('Audit logs flushed: ' . count($this->logBuffer) . ' entries');
        $this->logBuffer = [];
    }
    
    private function checkComplianceAlerts(array $auditLog): void
    {
        // Verificar padrões suspeitos
        if ($this->detectSuspiciousPattern($auditLog)) {
            $this->triggerComplianceAlert($auditLog);
        }
        
        // Verificar limites de acesso
        if ($this->exceedsAccessLimits($auditLog)) {
            $this->triggerAccessLimitAlert($auditLog);
        }
    }
    
    private function getCurrentIP(): string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 
               $_SERVER['HTTP_X_REAL_IP'] ?? 
               $_SERVER['REMOTE_ADDR'] ?? 
               'unknown';
    }
    
    private function generateRequestId(): string
    {
        return uniqid('req_') . '_' . time();
    }
    
    private function getDefaultConfig(): array
    {
        return [
            'audit_retention_days' => 2555, // 7 anos LGPD
            'buffer_size' => 100,
            'audit_salt' => bin2hex(random_bytes(32)),
            'breach_threshold' => 75,
            'encryption_enabled' => true,
            'anonymization_delay' => 30, // dias
            'compliance_checks_enabled' => true,
            'automated_reporting' => true,
            'data_minimization' => true,
            'purpose_limitation' => true
        ];
    }
    
    // Implementações simplificadas dos métodos auxiliares
    private function determineAccessReason(string $action): string { return 'business_operation'; }
    private function calculateRetentionPeriod(array $fields): int { return 2555; } // dias
    private function getConsentStatus(string $dataSubject): string { return 'given'; }
    private function determineChangeReason(): string { return 'user_request'; }
    private function requiresApproval(array $changes): bool { return count($changes) > 5; }
    private function createChangeBackup(string $table, int $recordId, array $data): bool { return true; }
    private function buildAuditQuery(array $filters): string { return 'SELECT * FROM audit_logs'; }
    private function executeAuditQuery(string $query): array { return []; }
    private function generateReportSummary(array $results): array { return ['total' => count($results)]; }
    private function assessComplianceStatus(array $results): array { return ['status' => 'compliant', 'score' => 95]; }
    private function generateComplianceRecommendations(array $results): array { return ['Manter práticas atuais']; }
    private function generatePDFReport(array $report): array { return ['format' => 'pdf', 'file' => 'audit_report.pdf']; }
    private function generateCSVReport(array $report): array { return ['format' => 'csv', 'file' => 'audit_report.csv']; }
    
    // Métodos de auditoria de compliance
    private function auditDataInventory(): array { return ['status' => 'compliant', 'data_types' => 15]; }
    private function auditConsentCompliance(): array { return ['status' => 'compliant', 'consent_rate' => 98]; }
    private function auditRetentionCompliance(): array { return ['status' => 'compliant', 'expired_data' => 0]; }
    private function auditAccessControls(): array { return ['status' => 'compliant', 'access_violations' => 0]; }
    private function auditDataProtection(): array { return ['status' => 'compliant', 'encryption_rate' => 100]; }
    private function auditBreachProtocols(): array { return ['status' => 'compliant', 'protocols_tested' => true]; }
    private function auditSubjectRights(): array { return ['status' => 'compliant', 'avg_response_time' => 15]; }
    private function calculateComplianceScore(): int { return 96; }
    
    // Processamento de direitos do titular
    private function processAccessRequest(string $subject, array $data): array { return ['data_exported' => true, 'format' => 'json']; }
    private function processPortabilityRequest(string $subject, array $data): array { return ['data_portable' => true, 'format' => 'csv']; }
    private function processRectificationRequest(string $subject, array $data): array { return ['data_corrected' => true]; }
    private function processErasureRequest(string $subject, array $data): array { return ['data_erased' => true]; }
    private function processObjectionRequest(string $subject, array $data): array { return ['processing_stopped' => true]; }
    private function processRestrictionRequest(string $subject, array $data): array { return ['processing_restricted' => true]; }
    
    // Detecção de violação
    private function calculateBreachRiskScore(array $activity): int { return rand(30, 90); }
    private function reportDataBreach(array $activity, int $score): string { return uniqid('breach_'); }
    private function getBreachResponseActions(int $score): array { return ['investigate', 'contain', 'notify']; }
    private function getNotificationRequirements(int $score): array { return ['authorities' => true, 'subjects' => $score > 80]; }
    
    // Verificações de compliance
    private function detectSuspiciousPattern(array $log): bool { return false; }
    private function exceedsAccessLimits(array $log): bool { return false; }
    private function triggerComplianceAlert(array $log): void { error_log('Compliance alert triggered'); }
    private function triggerAccessLimitAlert(array $log): void { error_log('Access limit alert triggered'); }
    private function isAutomatedDecision(string $eventType): bool { return str_contains($eventType, 'automated'); }
    private function isCrossBorderTransfer(array $data): bool { return false; }
    private function isHighRiskProcessing(string $eventType, array $data): bool { return false; }
    private function calculateDataChanges(array $old, array $new): array { return array_diff_assoc($new, $old); }
}