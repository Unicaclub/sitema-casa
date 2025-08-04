<?php

declare(strict_types=1);

namespace Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use ERP\Core\Cache\RedisManager;
use Redis;

/**
 * Redis Manager Unit Tests
 * 
 * @package Tests\Unit\Cache
 */
final class RedisManagerTest extends TestCase
{
    private RedisManager $redisManager;
    private array $testConfig = [
        'host' => '127.0.0.1',
        'port' => 6379,
        'database' => 1, // Use different DB for tests
        'key_prefix' => 'test:'
    ];
    
    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('Redis extension not available');
        }
        
        // Test if Redis is available
        try {
            $redis = new Redis();
            $connected = $redis->connect($this->testConfig['host'], $this->testConfig['port'], 1);
            if (!$connected) {
                $this->markTestSkipped('Redis server not available');
            }
            $redis->close();
        } catch (\Exception $e) {
            $this->markTestSkipped('Redis server not available: ' . $e->getMessage());
        }
        
        $this->redisManager = new RedisManager($this->testConfig);
        
        // Clear test database
        if ($this->redisManager->isConnected()) {
            $this->redisManager->flush();
        }
    }
    
    protected function tearDown(): void
    {
        if (isset($this->redisManager) && $this->redisManager->isConnected()) {
            $this->redisManager->flush();
            $this->redisManager->close();
        }
    }
    
    public function testConnection(): void
    {
        $this->assertTrue($this->redisManager->isConnected());
    }
    
    public function testSetAndGet(): void
    {
        $key = 'test_key';
        $value = 'test_value';
        
        $this->assertTrue($this->redisManager->set($key, $value));
        $this->assertEquals($value, $this->redisManager->get($key));
    }
    
    public function testSetWithTTL(): void
    {
        $key = 'test_ttl_key';
        $value = 'test_value';
        $ttl = 2;
        
        $this->assertTrue($this->redisManager->set($key, $value, $ttl));
        $this->assertEquals($value, $this->redisManager->get($key));
        
        // Check TTL
        $actualTtl = $this->redisManager->ttl($key);
        $this->assertGreaterThan(0, $actualTtl);
        $this->assertLessThanOrEqual($ttl, $actualTtl);
    }
    
    public function testGetNonexistentKey(): void
    {
        $nonexistentKey = 'nonexistent_key';
        $this->assertNull($this->redisManager->get($nonexistentKey));
    }
    
    public function testExists(): void
    {
        $key = 'exists_test_key';
        $value = 'test_value';
        
        $this->assertFalse($this->redisManager->exists($key));
        
        $this->redisManager->set($key, $value);
        $this->assertTrue($this->redisManager->exists($key));
    }
    
    public function testDelete(): void
    {
        $key = 'delete_test_key';
        $value = 'test_value';
        
        $this->redisManager->set($key, $value);
        $this->assertTrue($this->redisManager->exists($key));
        
        $this->assertTrue($this->redisManager->delete($key));
        $this->assertFalse($this->redisManager->exists($key));
    }
    
    public function testIncrement(): void
    {
        $key = 'increment_test_key';
        
        // First increment should set to 1
        $this->assertEquals(1, $this->redisManager->increment($key));
        
        // Second increment should set to 2
        $this->assertEquals(2, $this->redisManager->increment($key));
        
        // Increment by 5
        $this->assertEquals(7, $this->redisManager->increment($key, 5));
    }
    
    public function testDecrement(): void
    {
        $key = 'decrement_test_key';
        
        // Set initial value
        $this->redisManager->set($key, 10);
        
        // Decrement by 1
        $this->assertEquals(9, $this->redisManager->decrement($key));
        
        // Decrement by 3
        $this->assertEquals(6, $this->redisManager->decrement($key, 3));
    }
    
    public function testExpire(): void
    {
        $key = 'expire_test_key';
        $value = 'test_value';
        
        $this->redisManager->set($key, $value);
        $this->assertTrue($this->redisManager->expire($key, 1));
        
        $ttl = $this->redisManager->ttl($key);
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(1, $ttl);
    }
    
    public function testKeys(): void
    {
        $keys = ['test:pattern:1', 'test:pattern:2', 'test:other:1'];
        
        foreach ($keys as $key) {
            $this->redisManager->set($key, 'value');
        }
        
        $patternKeys = $this->redisManager->keys('test:pattern:*');
        $this->assertCount(2, $patternKeys);
        
        $allKeys = $this->redisManager->keys('test:*');
        $this->assertCount(3, $allKeys);
    }
    
    public function testHashOperations(): void
    {
        $key = 'test_hash';
        $field1 = 'field1';
        $value1 = 'value1';
        $field2 = 'field2';
        $value2 = 'value2';
        
        // Set hash fields
        $this->assertTrue($this->redisManager->hSet($key, $field1, $value1));
        $this->assertTrue($this->redisManager->hSet($key, $field2, $value2));
        
        // Get hash field
        $this->assertEquals($value1, $this->redisManager->hGet($key, $field1));
        
        // Get all hash fields
        $allFields = $this->redisManager->hGetAll($key);
        $this->assertCount(2, $allFields);
        $this->assertEquals($value1, $allFields[$field1]);
        $this->assertEquals($value2, $allFields[$field2]);
        
        // Delete hash field
        $this->assertTrue($this->redisManager->hDel($key, $field1));
        $this->assertNull($this->redisManager->hGet($key, $field1));
    }
    
    public function testListOperations(): void
    {
        $key = 'test_list';
        $values = ['value1', 'value2', 'value3'];
        
        // Push values to left
        $this->assertEquals(1, $this->redisManager->lPush($key, $values[0]));
        $this->assertEquals(2, $this->redisManager->lPush($key, $values[1]));
        
        // Push values to right
        $this->assertEquals(3, $this->redisManager->rPush($key, $values[2]));
        
        // Get range
        $range = $this->redisManager->lRange($key, 0, -1);
        $this->assertCount(3, $range);
        
        // Pop from left and right
        $leftValue = $this->redisManager->lPop($key);
        $rightValue = $this->redisManager->rPop($key);
        
        $this->assertNotNull($leftValue);
        $this->assertNotNull($rightValue);
    }
    
    public function testSetOperations(): void
    {
        $key = 'test_set';
        $values = ['member1', 'member2', 'member3'];
        
        // Add members
        $this->assertEquals(3, $this->redisManager->sAdd($key, ...$values));
        
        // Check membership
        $this->assertTrue($this->redisManager->sIsMember($key, 'member1'));
        $this->assertFalse($this->redisManager->sIsMember($key, 'nonexistent'));
        
        // Get all members
        $members = $this->redisManager->sMembers($key);
        $this->assertCount(3, $members);
        
        // Remove member
        $this->assertEquals(1, $this->redisManager->sRem($key, 'member1'));
        $this->assertFalse($this->redisManager->sIsMember($key, 'member1'));
    }
    
    public function testRateLimit(): void
    {
        $key = 'rate_limit_test';
        $maxRequests = 5;
        $windowSeconds = 10;
        
        // First request should be allowed
        $result = $this->redisManager->rateLimit($key, $maxRequests, $windowSeconds);
        $this->assertTrue($result['allowed']);
        $this->assertEquals($maxRequests - 1, $result['remaining']);
        $this->assertEquals(1, $result['current']);
        
        // Make more requests
        for ($i = 2; $i <= $maxRequests; $i++) {
            $result = $this->redisManager->rateLimit($key, $maxRequests, $windowSeconds);
            $this->assertTrue($result['allowed']);
            $this->assertEquals($maxRequests - $i, $result['remaining']);
            $this->assertEquals($i, $result['current']);
        }
        
        // Next request should be blocked
        $result = $this->redisManager->rateLimit($key, $maxRequests, $windowSeconds);
        $this->assertFalse($result['allowed']);
        $this->assertEquals(0, $result['remaining']);
        $this->assertEquals($maxRequests + 1, $result['current']);
    }
    
    public function testRememberPattern(): void
    {
        $key = 'remember_test';
        $expectedValue = 'computed_value';
        $callCount = 0;
        
        $callback = function () use ($expectedValue, &$callCount) {
            $callCount++;
            return $expectedValue;
        };
        
        // First call should execute callback
        $result1 = $this->redisManager->remember($key, 60, $callback);
        $this->assertEquals($expectedValue, $result1);
        $this->assertEquals(1, $callCount);
        
        // Second call should return cached value
        $result2 = $this->redisManager->remember($key, 60, $callback);
        $this->assertEquals($expectedValue, $result2);
        $this->assertEquals(1, $callCount); // Callback not called again
    }
    
    public function testPipeline(): void
    {
        $keys = ['pipe1', 'pipe2', 'pipe3'];
        $values = ['value1', 'value2', 'value3'];
        
        $results = $this->redisManager->pipeline(function ($pipe) use ($keys, $values) {
            for ($i = 0; $i < count($keys); $i++) {
                $pipe->set($keys[$i], $values[$i]);
            }
        });
        
        // Pipeline should return array of results
        $this->assertIsArray($results);
        $this->assertCount(3, $results);
        
        // Verify values were set
        for ($i = 0; $i < count($keys); $i++) {
            $this->assertEquals($values[$i], $this->redisManager->get($keys[$i]));
        }
    }
    
    public function testTransaction(): void
    {
        $key1 = 'trans1';
        $key2 = 'trans2';
        $value1 = 'value1';
        $value2 = 'value2';
        
        $results = $this->redisManager->transaction(function ($redis) use ($key1, $key2, $value1, $value2) {
            $redis->set($key1, $value1);
            $redis->set($key2, $value2);
        });
        
        $this->assertIsArray($results);
        $this->assertEquals($value1, $this->redisManager->get($key1));
        $this->assertEquals($value2, $this->redisManager->get($key2));
    }
    
    public function testGetStats(): void
    {
        $stats = $this->redisManager->getStats();
        
        $this->assertIsArray($stats);
        $this->assertTrue($stats['connected']);
        $this->assertEquals('connected', $stats['status']);
        $this->assertArrayHasKey('version', $stats);
        $this->assertArrayHasKey('used_memory', $stats);
        $this->assertArrayHasKey('connected_clients', $stats);
        $this->assertArrayHasKey('hit_rate', $stats);
    }
}