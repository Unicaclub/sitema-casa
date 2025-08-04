<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use ERP\Core\Security\WAFManager;
use ERP\Core\Security\AuditManager;

/**
 * WAF Manager Unit Tests
 * 
 * @package Tests\Unit\Security
 */
final class WAFManagerTest extends TestCase
{
    private WAFManager $wafManager;
    private MockObject|AuditManager $mockAudit;
    
    protected function setUp(): void
    {
        $this->mockAudit = $this->createMock(AuditManager::class);
        $this->wafManager = new WAFManager($this->mockAudit);
    }
    
    public function testAnalyzeCleanRequest(): void
    {
        $cleanRequest = [
            'method' => 'GET',
            'uri' => '/api/users',
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'Mozilla/5.0 (compatible browser)'
            ],
            'body' => '',
            'ip' => '192.168.1.100',
            'user_agent' => 'Mozilla/5.0 (compatible browser)'
        ];
        
        $result = $this->wafManager->analyzeRequest($cleanRequest);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('request_id', $result);
        $this->assertArrayHasKey('threat_score', $result);
        $this->assertArrayHasKey('blocked', $result);
        $this->assertArrayHasKey('rules_triggered', $result);
        
        $this->assertFalse($result['blocked']);
        $this->assertLessThan(50, $result['threat_score']); // Low threat score for clean request
    }
    
    public function testAnalyzeSQLInjectionAttempt(): void
    {
        $maliciousRequest = [
            'method' => 'POST',
            'uri' => '/api/login',
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'body' => '{"username": "admin\' OR 1=1--", "password": "test"}',
            'ip' => '10.0.0.1',
            'user_agent' => 'sqlmap/1.0'
        ];
        
        $this->mockAudit->expects($this->atLeastOnce())
            ->method('logEvent')
            ->with('waf_threat_detected');
        
        $result = $this->wafManager->analyzeRequest($maliciousRequest);
        
        $this->assertTrue($result['blocked']);
        $this->assertGreaterThan(70, $result['threat_score']); // High threat score
        $this->assertNotEmpty($result['rules_triggered']);
        
        // Should trigger SQL injection detection
        $triggeredRules = array_column($result['rules_triggered'], 'rule_name');
        $this->assertContains('sql_injection_detection', $triggeredRules);
    }
    
    public function testAnalyzeXSSAttempt(): void
    {
        $xssRequest = [
            'method' => 'POST',
            'uri' => '/api/comments',
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'body' => '{"comment": "<script>alert(\'XSS\')</script>"}',
            'ip' => '172.16.0.1',
            'user_agent' => 'Mozilla/5.0'
        ];
        
        $this->mockAudit->expects($this->atLeastOnce())
            ->method('logEvent');
        
        $result = $this->wafManager->analyzeRequest($xssRequest);
        
        $this->assertTrue($result['blocked']);
        $this->assertGreaterThan(70, $result['threat_score']);
        
        $triggeredRules = array_column($result['rules_triggered'], 'rule_name');
        $this->assertContains('xss_detection', $triggeredRules);
    }
    
    public function testAnalyzePathTraversalAttempt(): void
    {
        $traversalRequest = [
            'method' => 'GET',
            'uri' => '/api/files?path=../../../etc/passwd',
            'headers' => [],
            'body' => '',
            'ip' => '203.0.113.1',
            'user_agent' => 'curl/7.0'
        ];
        
        $result = $this->wafManager->analyzeRequest($traversalRequest);
        
        $this->assertTrue($result['blocked']);
        $this->assertGreaterThan(60, $result['threat_score']);
        
        $triggeredRules = array_column($result['rules_triggered'], 'rule_name');
        $this->assertContains('path_traversal_detection', $triggeredRules);
    }
    
    public function testAnalyzeRateLimitExceeded(): void
    {
        $request = [
            'method' => 'POST',
            'uri' => '/api/login',
            'headers' => [],
            'body' => '{"username": "test", "password": "test"}',
            'ip' => '198.51.100.1',
            'user_agent' => 'test-client'
        ];
        
        // Simulate multiple requests from same IP to trigger rate limiting
        for ($i = 0; $i < 15; $i++) {
            $result = $this->wafManager->analyzeRequest($request);
        }
        
        // The last request should be blocked due to rate limiting
        $this->assertTrue($result['blocked']);
        $triggeredRules = array_column($result['rules_triggered'], 'rule_name');
        $this->assertContains('rate_limiting', $triggeredRules);
    }
    
    public function testAnalyzeSuspiciousUserAgent(): void
    {
        $suspiciousRequest = [
            'method' => 'GET',
            'uri' => '/api/admin',
            'headers' => [],
            'body' => '',
            'ip' => '192.0.2.1',
            'user_agent' => 'Nikto/2.1.6'
        ];
        
        $result = $this->wafManager->analyzeRequest($suspiciousRequest);
        
        $this->assertGreaterThan(30, $result['threat_score']); // Moderate threat score
        $triggeredRules = array_column($result['rules_triggered'], 'rule_name');
        $this->assertContains('suspicious_user_agent', $triggeredRules);
    }
    
    public function testAnalyzeFileUploadThreat(): void
    {
        $uploadRequest = [
            'method' => 'POST',
            'uri' => '/api/upload',
            'headers' => [
                'Content-Type' => 'multipart/form-data'
            ],
            'body' => 'filename="test.php.jpg"',
            'ip' => '203.0.113.10',
            'user_agent' => 'Mozilla/5.0'
        ];
        
        $result = $this->wafManager->analyzeRequest($uploadRequest);
        
        $this->assertGreaterThan(40, $result['threat_score']);
        $triggeredRules = array_column($result['rules_triggered'], 'rule_name');
        $this->assertContains('malicious_file_upload', $triggeredRules);
    }
    
    public function testGetWAFStats(): void
    {
        // Analyze some requests to generate stats
        $requests = [
            ['method' => 'GET', 'uri' => '/api/users', 'headers' => [], 'body' => '', 'ip' => '192.168.1.1', 'user_agent' => 'browser'],
            ['method' => 'POST', 'uri' => '/api/login', 'headers' => [], 'body' => '{"username": "admin\' OR 1=1--"}', 'ip' => '10.0.0.1', 'user_agent' => 'sqlmap'],
            ['method' => 'GET', 'uri' => '/api/data', 'headers' => [], 'body' => '', 'ip' => '192.168.1.2', 'user_agent' => 'browser']
        ];
        
        foreach ($requests as $request) {
            $this->wafManager->analyzeRequest($request);
        }
        
        $stats = $this->wafManager->getWAFStats();
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_requests', $stats);
        $this->assertArrayHasKey('blocked_requests', $stats);
        $this->assertArrayHasKey('threat_score_avg', $stats);
        $this->assertArrayHasKey('top_triggered_rules', $stats);
        $this->assertArrayHasKey('geographic_distribution', $stats);
        $this->assertArrayHasKey('attack_patterns', $stats);
        
        $this->assertEquals(3, $stats['total_requests']);
        $this->assertGreaterThanOrEqual(1, $stats['blocked_requests']);
    }
    
    public function testGetTopThreats(): void
    {
        // Generate some threat data
        $threats = [
            ['method' => 'POST', 'uri' => '/login', 'body' => 'sql injection attempt', 'ip' => '1.1.1.1', 'user_agent' => 'sqlmap'],
            ['method' => 'GET', 'uri' => '/search', 'body' => '<script>xss</script>', 'ip' => '2.2.2.2', 'user_agent' => 'browser'],
            ['method' => 'GET', 'uri' => '/files', 'body' => '../../../etc/passwd', 'ip' => '3.3.3.3', 'user_agent' => 'curl']
        ];
        
        foreach ($threats as $threat) {
            $this->wafManager->analyzeRequest($threat);
        }
        
        $topThreats = $this->wafManager->getTopThreats(10);
        
        $this->assertIsArray($topThreats);
        $this->assertNotEmpty($topThreats);
        
        foreach ($topThreats as $threat) {
            $this->assertArrayHasKey('threat_type', $threat);
            $this->assertArrayHasKey('severity', $threat);
            $this->assertArrayHasKey('count', $threat);
            $this->assertArrayHasKey('last_seen', $threat);
        }
    }
    
    public function testUpdateWAFRules(): void
    {
        $newRules = [
            [
                'rule_id' => 'custom_001',
                'rule_name' => 'custom_threat_detection',
                'pattern' => '/malicious_pattern/i',
                'severity' => 'high',
                'action' => 'block'
            ]
        ];
        
        $result = $this->wafManager->updateWAFRules($newRules);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('rules_updated', $result);
        $this->assertArrayHasKey('rules_count', $result);
        $this->assertTrue($result['rules_updated']);
        $this->assertGreaterThan(0, $result['rules_count']);
    }
    
    public function testGetBlockedIPs(): void
    {
        // Generate requests from IPs that should be blocked
        $maliciousRequests = [
            ['method' => 'POST', 'uri' => '/login', 'body' => 'attack', 'ip' => '198.51.100.10', 'user_agent' => 'attacker'],
            ['method' => 'POST', 'uri' => '/admin', 'body' => 'attack', 'ip' => '198.51.100.11', 'user_agent' => 'scanner']
        ];
        
        foreach ($maliciousRequests as $request) {
            $this->wafManager->analyzeRequest($request);
        }
        
        $blockedIPs = $this->wafManager->getBlockedIPs();
        
        $this->assertIsArray($blockedIPs);
        
        foreach ($blockedIPs as $blockedIP) {
            $this->assertArrayHasKey('ip_address', $blockedIP);
            $this->assertArrayHasKey('block_reason', $blockedIP);
            $this->assertArrayHasKey('blocked_at', $blockedIP);
            $this->assertArrayHasKey('expires_at', $blockedIP);
        }
    }
    
    public function testGetSecurityReport(): void
    {
        // Generate some activity
        $requests = [
            ['method' => 'GET', 'uri' => '/api/users', 'headers' => [], 'body' => '', 'ip' => '192.168.1.50', 'user_agent' => 'browser'],
            ['method' => 'POST', 'uri' => '/api/login', 'headers' => [], 'body' => 'malicious content', 'ip' => '10.0.0.50', 'user_agent' => 'attacker']
        ];
        
        foreach ($requests as $request) {
            $this->wafManager->analyzeRequest($request);
        }
        
        $report = $this->wafManager->getSecurityReport('1h');
        
        $this->assertIsArray($report);
        $this->assertArrayHasKey('timeframe', $report);
        $this->assertArrayHasKey('summary', $report);
        $this->assertArrayHasKey('threat_analysis', $report);
        $this->assertArrayHasKey('recommendations', $report);
        $this->assertArrayHasKey('generated_at', $report);
        
        $this->assertEquals('1h', $report['timeframe']);
        $this->assertIsArray($report['summary']);
        $this->assertIsArray($report['threat_analysis']);
    }
}