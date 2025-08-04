<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use ERP\Core\Auth\AuthenticationService;
use ERP\Core\Auth\JWTManager;
use ERP\Core\Database\DatabaseManager;
use ERP\Core\Security\AuditManager;
use ERP\Core\Cache\RedisManager;
use ERP\Core\Cache\CacheManager;

/**
 * Authentication Integration Tests
 * 
 * Tests the complete authentication flow with real components
 * 
 * @package Tests\Integration
 */
final class AuthenticationIntegrationTest extends TestCase
{
    private AuthenticationService $authService;
    private JWTManager $jwtManager;
    private CacheManager $cacheManager;
    private string $testSecret = 'integration-test-secret-key-32-chars-minimum-length';
    
    protected function setUp(): void
    {
        // Initialize real components for integration testing
        $this->jwtManager = new JWTManager($this->testSecret, 'Integration-Test');
        
        // Use mock database and audit for integration tests
        $mockDb = $this->createMock(DatabaseManager::class);
        $mockAudit = $this->createMock(AuditManager::class);
        
        $this->authService = new AuthenticationService($mockDb, $this->jwtManager, $mockAudit);
        
        // Initialize cache if Redis is available
        if (extension_loaded('redis')) {
            try {
                $redisManager = new RedisManager(['database' => 2]); // Use test DB
                if ($redisManager->isConnected()) {
                    $this->cacheManager = new CacheManager($redisManager);
                    $redisManager->flush(); // Clear test database
                }
            } catch (\Exception $e) {
                // Redis not available, skip cache tests
            }
        }
    }
    
    protected function tearDown(): void
    {
        if (isset($this->cacheManager)) {
            // Clean up test data
            $this->cacheManager->clearLayer('security');
            $this->cacheManager->clearLayer('user_sessions');
        }
    }
    
    public function testCompleteAuthenticationFlow(): void
    {
        $email = 'admin@erp-sistema.local';
        $password = 'admin123!@#';
        $context = [
            'ip_address' => '192.168.1.100',
            'user_agent' => 'Integration-Test-Client/1.0'
        ];
        
        // Step 1: Authenticate user
        $authResult = $this->authService->authenticate($email, $password, $context);
        
        $this->assertTrue($authResult['success']);
        $this->assertArrayHasKey('user', $authResult);
        $this->assertArrayHasKey('tokens', $authResult);
        
        $user = $authResult['user'];
        $tokens = $authResult['tokens'];
        
        $this->assertEquals($email, $user['email']);
        $this->assertEquals('System Administrator', $user['name']);
        $this->assertArrayHasKey('access_token', $tokens);
        $this->assertArrayHasKey('refresh_token', $tokens);
        
        // Step 2: Validate access token
        $validationResult = $this->authService->validateAccessToken($tokens['access_token']);
        
        $this->assertTrue($validationResult['valid']);
        $this->assertEquals($user['id'], $validationResult['user']['id']);
        $this->assertEquals($user['email'], $validationResult['user']['email']);
        
        // Step 3: Refresh token
        $refreshResult = $this->authService->refreshToken($tokens['refresh_token'], $context);
        
        $this->assertTrue($refreshResult['success']);
        $this->assertArrayHasKey('tokens', $refreshResult);
        
        $newTokens = $refreshResult['tokens'];
        $this->assertNotEquals($tokens['access_token'], $newTokens['access_token']);
        $this->assertNotEquals($tokens['refresh_token'], $newTokens['refresh_token']);
        
        // Step 4: Validate new access token
        $newValidationResult = $this->authService->validateAccessToken($newTokens['access_token']);
        $this->assertTrue($newValidationResult['valid']);
        
        // Step 5: Logout
        $logoutResult = $this->authService->logout($newTokens['access_token'], $newTokens['refresh_token']);
        $this->assertTrue($logoutResult['success']);
        
        // Step 6: Verify token is blacklisted after logout
        $postLogoutValidation = $this->authService->validateAccessToken($newTokens['access_token']);
        $this->assertFalse($postLogoutValidation['valid']);
        $this->assertEquals('Token is blacklisted', $postLogoutValidation['error']);
    }
    
    public function testJWTTokenLifecycle(): void
    {
        $payload = [
            'user_id' => 1,
            'email' => 'test@example.com',
            'role' => 'user'
        ];
        
        // Generate token pair
        $tokens = $this->jwtManager->generateTokenPair($payload);
        
        $this->assertArrayHasKey('access_token', $tokens);
        $this->assertArrayHasKey('refresh_token', $tokens);
        $this->assertEquals('Bearer', $tokens['token_type']);
        $this->assertIsInt($tokens['expires_in']);
        
        // Validate access token
        $accessValidation = $this->jwtManager->validateToken($tokens['access_token']);
        $this->assertTrue($accessValidation['valid']);
        $this->assertEquals('access', $accessValidation['payload']['type']);
        $this->assertEquals(1, $accessValidation['payload']['user_id']);
        
        // Validate refresh token
        $refreshValidation = $this->jwtManager->validateToken($tokens['refresh_token']);
        $this->assertTrue($refreshValidation['valid']);
        $this->assertEquals('refresh', $refreshValidation['payload']['type']);
        $this->assertEquals(1, $refreshValidation['payload']['user_id']);
        
        // Parse tokens
        $accessParsed = $this->jwtManager->parseToken($tokens['access_token']);
        $this->assertArrayHasKey('header', $accessParsed);
        $this->assertArrayHasKey('payload', $accessParsed);
        $this->assertArrayHasKey('signature', $accessParsed);
        
        // Check TTL
        $ttl = $this->jwtManager->getTokenTTL($tokens['access_token']);
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(3600, $ttl);
        
        // Blacklist token
        $this->jwtManager->blacklistToken($tokens['access_token']);
        $this->assertTrue($this->jwtManager->isTokenBlacklisted($tokens['access_token']));
        
        // Verify blacklisted token is invalid
        $blacklistedValidation = $this->jwtManager->validateToken($tokens['access_token']);
        $this->assertFalse($blacklistedValidation['valid']);
    }
    
    public function testCacheIntegrationWithAuth(): void
    {
        if (!isset($this->cacheManager)) {
            $this->markTestSkipped('Redis not available for cache integration test');
        }
        
        $userId = 123;
        $sessionId = 'session_' . uniqid();
        $jti = 'jti_' . uniqid();
        
        // Test user session caching
        $sessionData = [
            'user_id' => $userId,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'role' => 'user',
            'login_time' => time()
        ];
        
        $this->assertTrue($this->cacheManager->cacheUserSession($sessionId, $sessionData));
        $cachedSession = $this->cacheManager->getUserSession($sessionId);
        $this->assertEquals($sessionData, $cachedSession);
        
        // Test rate limiting
        $rateLimitResult = $this->cacheManager->checkUserRateLimit($userId, 'api_request', 5, 60);
        $this->assertTrue($rateLimitResult['allowed']);
        $this->assertEquals(4, $rateLimitResult['remaining']);
        
        // Make more requests to test rate limiting
        for ($i = 0; $i < 4; $i++) {
            $rateLimitResult = $this->cacheManager->checkUserRateLimit($userId, 'api_request', 5, 60);
        }
        
        // Next request should be blocked
        $rateLimitResult = $this->cacheManager->checkUserRateLimit($userId, 'api_request', 5, 60);
        $this->assertFalse($rateLimitResult['allowed']);
        $this->assertEquals(0, $rateLimitResult['remaining']);
        
        // Test JWT blacklisting
        $this->assertTrue($this->cacheManager->blacklistToken($jti, 3600));
        $this->assertTrue($this->cacheManager->isTokenBlacklisted($jti));
        
        // Test session invalidation
        $this->assertTrue($this->cacheManager->invalidateUserSession($sessionId));
        $this->assertNull($this->cacheManager->getUserSession($sessionId));
    }
    
    public function testSecurityEventCaching(): void
    {
        if (!isset($this->cacheManager)) {
            $this->markTestSkipped('Redis not available for security event caching test');
        }
        
        $eventId = 'security_event_' . uniqid();
        $eventData = [
            'type' => 'failed_login',
            'user_id' => 456,
            'ip_address' => '192.168.1.200',
            'user_agent' => 'Malicious-Bot/1.0',
            'timestamp' => time(),
            'severity' => 'high'
        ];
        
        // Cache security event
        $this->assertTrue($this->cacheManager->cacheSecurityEvent($eventId, $eventData));
        
        // Retrieve security event
        $cachedEvent = $this->cacheManager->getSecurityEvent($eventId);
        $this->assertEquals($eventData, $cachedEvent);
        
        // Test threat data caching
        $threatId = 'threat_' . uniqid();
        $threatData = [
            'threat_type' => 'brute_force',
            'source_ip' => '192.168.1.200',
            'target' => '/admin/login',
            'severity' => 'high',
            'detected_at' => time()
        ];
        
        $this->assertTrue($this->cacheManager->cacheThreatData($threatId, $threatData));
        $cachedThreat = $this->cacheManager->getThreatData($threatId);
        $this->assertEquals($threatData, $cachedThreat);
        
        // Test IoC caching
        $iocValue = '192.168.1.200';
        $iocType = 'ip';
        $iocData = [
            'malicious' => true,
            'categories' => ['brute_force', 'scanner'],
            'confidence' => 95,
            'first_seen' => time() - 3600,
            'last_seen' => time()
        ];
        
        $this->assertTrue($this->cacheManager->cacheIOC($iocValue, $iocType, $iocData));
        $cachedIoC = $this->cacheManager->getIOC($iocValue, $iocType);
        $this->assertEquals($iocData, $cachedIoC);
    }
    
    public function testPasswordResetFlow(): void
    {
        $userId = 1;
        $email = 'admin@erp-sistema.local';
        
        // Generate password reset token
        $resetToken = $this->jwtManager->generatePasswordResetToken($userId, $email);
        $this->assertIsString($resetToken);
        $this->assertNotEmpty($resetToken);
        
        // Validate reset token
        $validation = $this->jwtManager->validateToken($resetToken);
        $this->assertTrue($validation['valid']);
        $this->assertEquals('password_reset', $validation['payload']['type']);
        $this->assertEquals($userId, $validation['payload']['user_id']);
        $this->assertEquals($email, $validation['payload']['email']);
        
        // Check token expiration (should be shorter than access tokens)
        $ttl = $this->jwtManager->getTokenTTL($resetToken);
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(3600, $ttl); // Should expire within 1 hour
    }
    
    public function testMultipleUserSessions(): void
    {
        if (!isset($this->cacheManager)) {
            $this->markTestSkipped('Redis not available for multi-session test');
        }
        
        $users = [
            ['id' => 1, 'email' => 'user1@test.com', 'name' => 'User 1'],
            ['id' => 2, 'email' => 'user2@test.com', 'name' => 'User 2'],
            ['id' => 3, 'email' => 'user3@test.com', 'name' => 'User 3']
        ];
        
        $sessions = [];
        
        // Create sessions for multiple users
        foreach ($users as $user) {
            $sessionId = 'session_' . $user['id'] . '_' . uniqid();
            $sessionData = [
                'user_id' => $user['id'],
                'email' => $user['email'],
                'name' => $user['name'],
                'login_time' => time()
            ];
            
            $this->assertTrue($this->cacheManager->cacheUserSession($sessionId, $sessionData));
            $sessions[$user['id']] = $sessionId;
            
            // Generate tokens for each user
            $tokens = $this->jwtManager->generateTokenPair([
                'user_id' => $user['id'],
                'email' => $user['email'],
                'name' => $user['name']
            ]);
            
            $this->assertArrayHasKey('access_token', $tokens);
            $this->assertArrayHasKey('refresh_token', $tokens);
        }
        
        // Verify all sessions exist
        foreach ($sessions as $userId => $sessionId) {
            $cachedSession = $this->cacheManager->getUserSession($sessionId);
            $this->assertNotNull($cachedSession);
            $this->assertEquals($userId, $cachedSession['user_id']);
        }
        
        // Invalidate one session
        $this->assertTrue($this->cacheManager->invalidateUserSession($sessions[2]));
        $this->assertNull($this->cacheManager->getUserSession($sessions[2]));
        
        // Verify other sessions still exist
        $this->assertNotNull($this->cacheManager->getUserSession($sessions[1]));
        $this->assertNotNull($this->cacheManager->getUserSession($sessions[3]));
    }
}