<?php

declare(strict_types=1);

namespace Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use ERP\Core\Cache\CacheManager;
use ERP\Core\Cache\RedisManager;

/**
 * Cache Manager Unit Tests
 * 
 * @package Tests\Unit\Cache
 */
final class CacheManagerTest extends TestCase
{
    private CacheManager $cacheManager;
    private MockObject|RedisManager $mockRedis;
    
    protected function setUp(): void
    {
        $this->mockRedis = $this->createMock(RedisManager::class);
        $this->cacheManager = new CacheManager($this->mockRedis);
    }
    
    public function testCacheSecurityEvent(): void
    {
        $eventId = 'test_event_123';
        $eventData = [
            'type' => 'login_attempt',
            'user_id' => 1,
            'ip_address' => '192.168.1.100',
            'timestamp' => time()
        ];
        
        $this->mockRedis->expects($this->once())
            ->method('set')
            ->with(
                'sec:event:' . $eventId,
                $eventData,
                300 // default TTL for security layer
            )
            ->willReturn(true);
        
        $result = $this->cacheManager->cacheSecurityEvent($eventId, $eventData);
        $this->assertTrue($result);
    }
    
    public function testGetSecurityEvent(): void
    {
        $eventId = 'test_event_123';
        $expectedData = [
            'type' => 'login_attempt',
            'user_id' => 1,
            'severity' => 'medium'
        ];
        
        $this->mockRedis->expects($this->once())
            ->method('get')
            ->with('sec:event:' . $eventId)
            ->willReturn($expectedData);
        
        $result = $this->cacheManager->getSecurityEvent($eventId);
        $this->assertEquals($expectedData, $result);
    }
    
    public function testCacheThreatData(): void
    {
        $threatId = 'threat_456';
        $threatData = [
            'threat_type' => 'malware',
            'severity' => 'high',
            'indicators' => ['192.168.1.100', 'suspicious.domain.com']
        ];
        
        $this->mockRedis->expects($this->once())
            ->method('set')
            ->with(
                'ti:threat:' . $threatId,
                $threatData,
                1800 // default TTL for threat_intel layer
            )
            ->willReturn(true);
        
        $result = $this->cacheManager->cacheThreatData($threatId, $threatData);
        $this->assertTrue($result);
    }
    
    public function testCacheIOC(): void
    {
        $iocValue = '192.168.1.100';
        $iocType = 'ip';
        $iocData = [
            'malicious' => true,
            'categories' => ['botnet', 'malware'],
            'confidence' => 85,
            'last_seen' => time()
        ];
        
        $this->mockRedis->expects($this->once())
            ->method('set')
            ->with(
                'ti:ioc:' . $iocType . ':' . $iocValue,
                $iocData,
                1800
            )
            ->willReturn(true);
        
        $result = $this->cacheManager->cacheIOC($iocValue, $iocType, $iocData);
        $this->assertTrue($result);
    }
    
    public function testGetIOC(): void
    {
        $iocValue = 'malicious.domain.com';
        $iocType = 'domain';
        $expectedData = [
            'malicious' => true,
            'categories' => ['phishing'],
            'confidence' => 92
        ];
        
        $this->mockRedis->expects($this->once())
            ->method('get')
            ->with('ti:ioc:' . $iocType . ':' . $iocValue)
            ->willReturn($expectedData);
        
        $result = $this->cacheManager->getIOC($iocValue, $iocType);
        $this->assertEquals($expectedData, $result);
    }
    
    public function testCacheUserSession(): void
    {
        $sessionId = 'session_789';
        $sessionData = [
            'user_id' => 1,
            'name' => 'Test User',
            'role' => 'admin',
            'login_time' => time()
        ];
        
        $this->mockRedis->expects($this->once())
            ->method('set')
            ->with(
                'sess:' . $sessionId,
                $sessionData,
                1800 // default TTL for user_sessions layer
            )
            ->willReturn(true);
        
        $result = $this->cacheManager->cacheUserSession($sessionId, $sessionData);
        $this->assertTrue($result);
    }
    
    public function testInvalidateUserSession(): void
    {
        $sessionId = 'session_to_invalidate';
        
        $this->mockRedis->expects($this->once())
            ->method('delete')
            ->with('sess:' . $sessionId)
            ->willReturn(true);
        
        $result = $this->cacheManager->invalidateUserSession($sessionId);
        $this->assertTrue($result);
    }
    
    public function testCachePerformanceMetric(): void
    {
        $metricName = 'response_time_avg';
        $value = 125.5;
        
        $this->mockRedis->expects($this->once())
            ->method('set')
            ->with(
                'perf:' . $metricName,
                $value,
                60 // default TTL for performance layer
            )
            ->willReturn(true);
        
        $result = $this->cacheManager->cachePerformanceMetric($metricName, $value);
        $this->assertTrue($result);
    }
    
    public function testCacheAPIResponse(): void
    {
        $endpoint = '/api/users';
        $params = ['page' => 1, 'limit' => 10];
        $response = ['users' => [], 'total' => 0];
        
        $this->mockRedis->expects($this->once())
            ->method('set')
            ->with(
                $this->stringContains('api:'),
                $response,
                300 // default TTL for api_responses layer
            )
            ->willReturn(true);
        
        $result = $this->cacheManager->cacheAPIResponse($endpoint, $params, $response);
        $this->assertTrue($result);
    }
    
    public function testGetAPIResponse(): void
    {
        $endpoint = '/api/products';
        $params = ['category' => 'electronics'];
        $expectedResponse = ['products' => ['laptop', 'phone']];
        
        $this->mockRedis->expects($this->once())
            ->method('get')
            ->with($this->stringContains('api:'))
            ->willReturn($expectedResponse);
        
        $result = $this->cacheManager->getAPIResponse($endpoint, $params);
        $this->assertEquals($expectedResponse, $result);
    }
    
    public function testCacheQuery(): void
    {
        $sql = 'SELECT * FROM users WHERE active = ?';
        $params = [true];
        $result = [['id' => 1, 'name' => 'User 1']];
        
        $this->mockRedis->expects($this->once())
            ->method('set')
            ->with(
                $this->stringContains('db:'),
                $result,
                600 // default TTL for database_queries layer
            )
            ->willReturn(true);
        
        $cacheResult = $this->cacheManager->cacheQuery($sql, $params, $result);
        $this->assertTrue($cacheResult);
    }
    
    public function testCheckUserRateLimit(): void
    {
        $userId = 123;
        $action = 'api_request';
        $expectedResult = [
            'allowed' => true,
            'remaining' => 99,
            'reset_at' => time() + 3600
        ];
        
        $this->mockRedis->expects($this->once())
            ->method('rateLimit')
            ->with(
                'sec:rate_limit:user:' . $userId . ':' . $action,
                100, // maxRequests
                3600 // windowSeconds
            )
            ->willReturn($expectedResult);
        
        $result = $this->cacheManager->checkUserRateLimit($userId, $action);
        $this->assertEquals($expectedResult, $result);
    }
    
    public function testCheckIPRateLimit(): void
    {
        $ip = '192.168.1.100';
        $action = 'login_attempt';
        $expectedResult = [
            'allowed' => false,
            'remaining' => 0,
            'reset_at' => time() + 60
        ];
        
        $this->mockRedis->expects($this->once())
            ->method('rateLimit')
            ->with(
                'sec:rate_limit:ip:' . $ip . ':' . $action,
                60, // maxRequests
                60 // windowSeconds
            )
            ->willReturn($expectedResult);
        
        $result = $this->cacheManager->checkIPRateLimit($ip, $action);
        $this->assertEquals($expectedResult, $result);
    }
    
    public function testBlacklistToken(): void
    {
        $jti = 'token_jti_123';
        $ttl = 3600;
        
        $this->mockRedis->expects($this->once())
            ->method('set')
            ->with(
                'sec:blacklist:jwt:' . $jti,
                true,
                $ttl
            )
            ->willReturn(true);
        
        $result = $this->cacheManager->blacklistToken($jti, $ttl);
        $this->assertTrue($result);
    }
    
    public function testIsTokenBlacklisted(): void
    {
        $jti = 'blacklisted_token_jti';
        
        $this->mockRedis->expects($this->once())
            ->method('exists')
            ->with('sec:blacklist:jwt:' . $jti)
            ->willReturn(true);
        
        $result = $this->cacheManager->isTokenBlacklisted($jti);
        $this->assertTrue($result);
    }
    
    public function testIncrementCounter(): void
    {
        $counterName = 'api_requests_total';
        $incrementValue = 5;
        $expectedResult = 15;
        
        $this->mockRedis->expects($this->once())
            ->method('increment')
            ->with('perf:counter:' . $counterName, $incrementValue)
            ->willReturn($expectedResult);
        
        $result = $this->cacheManager->incrementCounter($counterName, $incrementValue);
        $this->assertEquals($expectedResult, $result);
    }
    
    public function testCacheWithTags(): void
    {
        $cacheKey = 'tagged_content';
        $value = 'some content';
        $tags = ['tag1', 'tag2'];
        
        $this->mockRedis->expects($this->once())
            ->method('set')
            ->with($cacheKey, $value, 0)
            ->willReturn(true);
        
        $this->mockRedis->expects($this->exactly(2))
            ->method('sAdd')
            ->withConsecutive(
                ['api:tag:tag1', $cacheKey],
                ['api:tag:tag2', $cacheKey]
            )
            ->willReturn(1);
        
        $result = $this->cacheManager->cacheWithTags($cacheKey, $value, $tags);
        $this->assertTrue($result);
    }
    
    public function testInvalidateByTag(): void
    {
        $tag = 'user_data';
        $taggedKeys = ['user:1:profile', 'user:1:settings'];
        
        $this->mockRedis->expects($this->once())
            ->method('sMembers')
            ->with('api:tag:' . $tag)
            ->willReturn($taggedKeys);
        
        $this->mockRedis->expects($this->exactly(2))
            ->method('delete')
            ->withConsecutive(
                [$taggedKeys[0]],
                [$taggedKeys[1]]
            )
            ->willReturn(true);
        
        $this->mockRedis->expects($this->once())
            ->method('delete')
            ->with('api:tag:' . $tag)
            ->willReturn(true);
        
        $result = $this->cacheManager->invalidateByTag($tag);
        $this->assertEquals(2, $result);
    }
    
    public function testClearLayer(): void
    {
        $layer = 'security';
        $pattern = 'sec:*';
        $keysToDelete = ['sec:event:1', 'sec:threat:2'];
        
        $this->mockRedis->expects($this->once())
            ->method('keys')
            ->with($pattern)
            ->willReturn($keysToDelete);
        
        $this->mockRedis->expects($this->exactly(2))
            ->method('delete')
            ->willReturn(true);
        
        $result = $this->cacheManager->clearLayer($layer);
        $this->assertEquals(2, $result);
    }
    
    public function testGetCacheStats(): void
    {
        $redisStats = [
            'connected' => true,
            'version' => '7.0.0',
            'used_memory' => '1MB'
        ];
        
        $this->mockRedis->expects($this->once())
            ->method('getStats')
            ->willReturn($redisStats);
        
        // Mock keys method for each layer
        $this->mockRedis->expects($this->exactly(6))
            ->method('keys')
            ->willReturnCallback(function ($pattern) {
                return match ($pattern) {
                    'sec:*' => ['sec:event:1', 'sec:event:2'],
                    'sess:*' => ['sess:123'],
                    'ti:*' => ['ti:threat:1'],
                    'perf:*' => ['perf:metric:1', 'perf:metric:2', 'perf:metric:3'],
                    'api:*' => ['api:response:1'],
                    'db:*' => []
                };
            });
        
        $stats = $this->cacheManager->getCacheStats();
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('redis', $stats);
        $this->assertArrayHasKey('layers', $stats);
        $this->assertEquals($redisStats, $stats['redis']);
        
        $this->assertEquals(2, $stats['layers']['security']['key_count']);
        $this->assertEquals(1, $stats['layers']['user_sessions']['key_count']);
        $this->assertEquals(3, $stats['layers']['performance']['key_count']);
    }
}