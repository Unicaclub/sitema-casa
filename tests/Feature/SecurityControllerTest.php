<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use ERP\Api\Controllers\SecurityController;
use ERP\Core\Http\Request;
use ERP\Core\Http\Response;

/**
 * Security Controller Feature Tests
 * 
 * Tests the complete security API endpoints
 * 
 * @package Tests\Feature
 */
final class SecurityControllerTest extends TestCase
{
    private SecurityController $controller;
    
    protected function setUp(): void
    {
        $this->controller = new SecurityController();
    }
    
    public function testSecurityDashboard(): void
    {
        $request = $this->createMockRequest('GET', '/api/security/dashboard');
        
        $response = $this->controller->dashboard($request);
        
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        
        $responseData = json_decode($response->getContent(), true);
        $this->assertTrue($responseData['success']);
        
        $data = $responseData['data'];
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertArrayHasKey('overall_security_score', $data);
        $this->assertArrayHasKey('security_status', $data);
        $this->assertArrayHasKey('encryption', $data);
        $this->assertArrayHasKey('compliance', $data);
        $this->assertArrayHasKey('backup_recovery', $data);
        $this->assertArrayHasKey('recent_security_events', $data);
        $this->assertArrayHasKey('active_threats', $data);
        $this->assertArrayHasKey('recommendations', $data);
        
        // Verify security score is within valid range
        $this->assertIsInt($data['overall_security_score']);
        $this->assertGreaterThanOrEqual(0, $data['overall_security_score']);
        $this->assertLessThanOrEqual(100, $data['overall_security_score']);
    }
    
    public function testThreatsEndpoint(): void
    {
        $request = $this->createMockRequest('GET', '/api/security/threats', [
            'level' => 'high',
            'timeframe' => '24h'
        ]);
        
        $response = $this->controller->threats($request);
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $responseData = json_decode($response->getContent(), true);
        $this->assertTrue($responseData['success']);
        
        $data = $responseData['data'];
        $this->assertArrayHasKey('threat_analysis', $data);
        $this->assertArrayHasKey('timeframe', $data);
        $this->assertArrayHasKey('level_filter', $data);
        $this->assertArrayHasKey('summary', $data);
        
        $this->assertEquals('24h', $data['timeframe']);
        $this->assertEquals('high', $data['level_filter']);
        
        $summary = $data['summary'];
        $this->assertArrayHasKey('total_threats', $summary);
        $this->assertArrayHasKey('critical_count', $summary);
        $this->assertArrayHasKey('blocked_attempts', $summary);
        $this->assertArrayHasKey('threat_trend', $summary);
    }
    
    public function testSecurityScan(): void
    {
        $request = $this->createMockRequest('POST', '/api/security/scan', [
            'type' => 'vulnerability',
            'options' => ['deep_scan' => true]
        ]);
        
        $response = $this->controller->scan($request);
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $responseData = json_decode($response->getContent(), true);
        $this->assertTrue($responseData['success']);
        
        $data = $responseData['data'];
        $this->assertArrayHasKey('scan_id', $data);
        $this->assertArrayHasKey('scan_type', $data);
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('vulnerabilities_found', $data);
        $this->assertArrayHasKey('recommendations', $data);
        $this->assertArrayHasKey('next_scan_recommended', $data);
        
        $this->assertEquals('vulnerability', $data['scan_type']);
        $this->assertIsString($data['scan_id']);
        $this->assertIsInt($data['vulnerabilities_found']);
        $this->assertIsArray($data['recommendations']);
    }
    
    public function testEncryptionStatus(): void
    {
        $request = $this->createMockRequest('GET', '/api/security/encryption/status');
        
        $response = $this->controller->encryptionStatus($request);
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $responseData = json_decode($response->getContent(), true);
        $this->assertTrue($responseData['success']);
        
        $data = $responseData['data'];
        $this->assertArrayHasKey('encryption_status', $data);
        $this->assertArrayHasKey('key_management', $data);
        $this->assertArrayHasKey('recommendations', $data);
        $this->assertArrayHasKey('compliance_status', $data);
        $this->assertArrayHasKey('overall_score', $data);
        
        $keyManagement = $data['key_management'];
        $this->assertArrayHasKey('current_key_id', $keyManagement);
        $this->assertArrayHasKey('algorithm', $keyManagement);
        $this->assertArrayHasKey('key_rotation_due', $keyManagement);
    }
    
    public function testRotateKeys(): void
    {
        $request = $this->createMockRequest('POST', '/api/security/encryption/rotate-keys');
        
        $response = $this->controller->rotateKeys($request);
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $responseData = json_decode($response->getContent(), true);
        $this->assertTrue($responseData['success']);
        
        $data = $responseData['data'];
        $this->assertArrayHasKey('rotation_completed', $data);
        $this->assertArrayHasKey('keys_rotated', $data);
        $this->assertArrayHasKey('rotation_details', $data);
        $this->assertArrayHasKey('next_rotation_due', $data);
        
        $this->assertIsBool($data['rotation_completed']);
        $this->assertIsInt($data['keys_rotated']);
        $this->assertIsArray($data['rotation_details']);
    }
    
    public function testComplianceCheck(): void
    {
        $request = $this->createMockRequest('GET', '/api/security/audit/compliance');
        
        $response = $this->controller->complianceCheck($request);
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $responseData = json_decode($response->getContent(), true);
        $this->assertTrue($responseData['success']);
        
        $data = $responseData['data'];
        $this->assertArrayHasKey('compliance_report', $data);
        $this->assertArrayHasKey('overall_score', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('areas_of_concern', $data);
        $this->assertArrayHasKey('improvement_plan', $data);
        $this->assertArrayHasKey('next_audit_due', $data);
        
        $this->assertIsInt($data['overall_score']);
        $this->assertContains($data['status'], ['compliant', 'needs_review']);
        $this->assertIsArray($data['areas_of_concern']);
        $this->assertIsArray($data['improvement_plan']);
    }
    
    public function testLogAuditEvent(): void
    {
        $request = $this->createMockRequest('POST', '/api/security/audit/log-event', [
            'event_type' => 'user_access',
            'data' => [
                'user_id' => 123,
                'resource' => '/admin/users',
                'action' => 'view',
                'ip_address' => '192.168.1.100'
            ]
        ]);
        
        $response = $this->controller->logAuditEvent($request);
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $responseData = json_decode($response->getContent(), true);
        $this->assertTrue($responseData['success']);
        
        $data = $responseData['data'];
        $this->assertArrayHasKey('audit_id', $data);
        $this->assertArrayHasKey('event_logged', $data);
        $this->assertArrayHasKey('timestamp', $data);
        
        $this->assertTrue($data['event_logged']);
        $this->assertIsString($data['audit_id']);
    }
    
    public function testLogAuditEventMissingEventType(): void
    {
        $request = $this->createMockRequest('POST', '/api/security/audit/log-event', [
            'data' => ['some' => 'data']
        ]);
        
        $response = $this->controller->logAuditEvent($request);
        
        $this->assertEquals(400, $response->getStatusCode());
        
        $responseData = json_decode($response->getContent(), true);
        $this->assertFalse($responseData['success']);
        $this->assertStringContains('obrigatório', $responseData['error']);
    }
    
    public function testExecuteBackup(): void
    {
        $request = $this->createMockRequest('POST', '/api/security/backup/execute', [
            'type' => 'full',
            'options' => ['compress' => true]
        ]);
        
        $response = $this->controller->executeBackup($request);
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $responseData = json_decode($response->getContent(), true);
        $this->assertTrue($responseData['success']);
        
        $data = $responseData['data'];
        $this->assertArrayHasKey('backup_id', $data);
        $this->assertArrayHasKey('backup_type', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('progress', $data);
        
        $this->assertIsString($data['backup_id']);
        $this->assertEquals('full', $data['backup_type']);
    }
    
    public function testExecuteIncrementalBackupMissingBaseId(): void
    {
        $request = $this->createMockRequest('POST', '/api/security/backup/execute', [
            'type' => 'incremental'
            // Missing base_backup_id
        ]);
        
        $response = $this->controller->executeBackup($request);
        
        $this->assertEquals(400, $response->getStatusCode());
        
        $responseData = json_decode($response->getContent(), true);
        $this->assertFalse($responseData['success']);
        $this->assertStringContains('Base backup ID', $responseData['error']);
    }
    
    public function testRestoreBackup(): void
    {
        $request = $this->createMockRequest('POST', '/api/security/backup/restore', [
            'backup_id' => 'backup_20240101_123456',
            'options' => ['verify_integrity' => true]
        ]);
        
        $response = $this->controller->restoreBackup($request);
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $responseData = json_decode($response->getContent(), true);
        $this->assertTrue($responseData['success']);
        
        $data = $responseData['data'];
        $this->assertArrayHasKey('restore_id', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('progress', $data);
        $this->assertArrayHasKey('estimated_completion', $data);
    }
    
    public function testRestoreBackupMissingId(): void
    {
        $request = $this->createMockRequest('POST', '/api/security/backup/restore', [
            'options' => []
        ]);
        
        $response = $this->controller->restoreBackup($request);
        
        $this->assertEquals(400, $response->getStatusCode());
        
        $responseData = json_decode($response->getContent(), true);
        $this->assertFalse($responseData['success']);
        $this->assertStringContains('Backup ID', $responseData['error']);
    }
    
    public function testBackupHealth(): void
    {
        $request = $this->createMockRequest('GET', '/api/security/backup/health');
        
        $response = $this->controller->backupHealth($request);
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $responseData = json_decode($response->getContent(), true);
        $this->assertTrue($responseData['success']);
        
        $data = $responseData['data'];
        $this->assertArrayHasKey('backup_health', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('alerts', $data);
        $this->assertArrayHasKey('recommendations', $data);
        
        $this->assertContains($data['status'], ['healthy', 'needs_attention']);
        $this->assertIsArray($data['alerts']);
        $this->assertIsArray($data['recommendations']);
    }
    
    public function testSOCDashboard(): void
    {
        $request = $this->createMockRequest('GET', '/api/security/soc/dashboard');
        
        $response = $this->controller->socDashboard($request);
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $responseData = json_decode($response->getContent(), true);
        $this->assertTrue($responseData['success']);
        
        $data = $responseData['data'];
        $this->assertArrayHasKey('unified_metrics', $data);
        $this->assertArrayHasKey('incident_management', $data);
        $this->assertArrayHasKey('threat_landscape', $data);
        $this->assertArrayHasKey('automation_status', $data);
        
        $unifiedMetrics = $data['unified_metrics'];
        $this->assertArrayHasKey('waf_metrics', $unifiedMetrics);
        $this->assertArrayHasKey('ids_metrics', $unifiedMetrics);
        $this->assertArrayHasKey('ai_monitoring', $unifiedMetrics);
    }
    
    public function testWAFAnalyze(): void
    {
        $request = $this->createMockRequest('POST', '/api/security/waf/analyze', [
            'request_data' => [
                'method' => 'POST',
                'uri' => '/api/login',
                'headers' => ['Content-Type' => 'application/json'],
                'body' => '{"username": "admin", "password": "test123"}',
                'ip' => '192.168.1.100',
                'user_agent' => 'Mozilla/5.0'
            ]
        ]);
        
        $response = $this->controller->wafAnalyze($request);
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $responseData = json_decode($response->getContent(), true);
        $this->assertTrue($responseData['success']);
        
        $data = $responseData['data'];
        $this->assertArrayHasKey('request_id', $data);
        $this->assertArrayHasKey('threat_score', $data);
        $this->assertArrayHasKey('blocked', $data);
        $this->assertArrayHasKey('rules_triggered', $data);
        $this->assertArrayHasKey('analysis_details', $data);
        
        $this->assertIsString($data['request_id']);
        $this->assertIsInt($data['threat_score']);
        $this->assertIsBool($data['blocked']);
        $this->assertIsArray($data['rules_triggered']);
    }
    
    public function testPredictThreats(): void
    {
        $request = $this->createMockRequest('POST', '/api/security/ai/predict-threats', [
            'time_horizon_hours' => 48
        ]);
        
        $response = $this->controller->predictThreats($request);
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $responseData = json_decode($response->getContent(), true);
        $this->assertTrue($responseData['success']);
        
        $data = $responseData['data'];
        $this->assertArrayHasKey('prediction_id', $data);
        $this->assertArrayHasKey('time_horizon', $data);
        $this->assertArrayHasKey('predicted_threats', $data);
        $this->assertArrayHasKey('confidence_scores', $data);
        $this->assertArrayHasKey('recommendations', $data);
        
        $this->assertEquals(48, $data['time_horizon']);
        $this->assertIsArray($data['predicted_threats']);
        $this->assertIsArray($data['confidence_scores']);
    }
    
    private function createMockRequest(string $method, string $uri, array $data = []): Request
    {
        $mockRequest = $this->createMock(Request::class);
        
        $mockRequest->method('getMethod')->willReturn($method);
        $mockRequest->method('getUri')->willReturn($uri);
        
        // Mock the get method to return specific values
        $mockRequest->method('get')->willReturnCallback(function ($key, $default = null) use ($data) {
            return $data[$key] ?? $default;
        });
        
        // Mock headers
        $mockRequest->method('getHeader')->willReturnCallback(function ($header) {
            return match ($header) {
                'Authorization' => 'Bearer mock_token',
                'Content-Type' => 'application/json',
                'User-Agent' => 'PHPUnit Test Client',
                default => null
            };
        });
        
        return $mockRequest;
    }
}