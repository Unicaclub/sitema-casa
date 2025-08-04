<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use ERP\Core\Auth\JWTManager;

/**
 * JWT Manager Unit Tests
 * 
 * @package Tests\Unit\Auth
 */
final class JWTManagerTest extends TestCase
{
    private JWTManager $jwtManager;
    private string $testSecret = 'test-secret-key-that-is-at-least-32-characters-long-for-testing';
    
    protected function setUp(): void
    {
        $this->jwtManager = new JWTManager($this->testSecret, 'Test-Issuer');
    }
    
    public function testGenerateAccessToken(): void
    {
        $payload = [
            'user_id' => 1,
            'email' => 'test@example.com',
            'role' => 'user'
        ];
        
        $token = $this->jwtManager->generateAccessToken($payload);
        $this->assertIsString($token);
        $this->assertNotEmpty($token);
        
        // Verify token structure (header.payload.signature)
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
    }
    
    public function testGenerateRefreshToken(): void
    {
        $payload = [
            'user_id' => 1,
            'email' => 'test@example.com'
        ];
        
        $token = $this->jwtManager->generateRefreshToken($payload);
        $this->assertIsString($token);
        $this->assertNotEmpty($token);
        
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
    }
    
    public function testGenerateTokenPair(): void
    {
        $payload = [
            'user_id' => 1,
            'email' => 'test@example.com',
            'role' => 'user'
        ];
        
        $tokens = $this->jwtManager->generateTokenPair($payload);
        
        $this->assertIsArray($tokens);
        $this->assertArrayHasKey('access_token', $tokens);
        $this->assertArrayHasKey('refresh_token', $tokens);
        $this->assertArrayHasKey('token_type', $tokens);
        $this->assertArrayHasKey('expires_in', $tokens);
        
        $this->assertEquals('Bearer', $tokens['token_type']);
        $this->assertIsInt($tokens['expires_in']);
        $this->assertGreaterThan(0, $tokens['expires_in']);
    }
    
    public function testValidateValidToken(): void
    {
        $payload = [
            'user_id' => 1,
            'email' => 'test@example.com',
            'role' => 'user'
        ];
        
        $token = $this->jwtManager->generateAccessToken($payload);
        $validation = $this->jwtManager->validateToken($token);
        
        $this->assertTrue($validation['valid']);
        $this->assertArrayHasKey('payload', $validation);
        $this->assertEquals(1, $validation['payload']['user_id']);
        $this->assertEquals('test@example.com', $validation['payload']['email']);
        $this->assertEquals('access', $validation['payload']['type']);
    }
    
    public function testValidateInvalidToken(): void
    {
        $invalidToken = 'invalid.token.here';
        $validation = $this->jwtManager->validateToken($invalidToken);
        
        $this->assertFalse($validation['valid']);
        $this->assertArrayHasKey('error', $validation);
    }
    
    public function testValidateExpiredToken(): void
    {
        // Create manager with very short TTL
        $shortTtlManager = new JWTManager($this->testSecret, 'Test-Issuer', [
            'access_token_ttl' => 1 // 1 second
        ]);
        
        $payload = ['user_id' => 1];
        $token = $shortTtlManager->generateAccessToken($payload);
        
        // Wait for token to expire
        sleep(2);
        
        $validation = $shortTtlManager->validateToken($token);
        $this->assertFalse($validation['valid']);
        $this->assertStringContains('expired', strtolower($validation['error']));
    }
    
    public function testParseToken(): void
    {
        $payload = [
            'user_id' => 1,
            'email' => 'test@example.com',
            'role' => 'admin'
        ];
        
        $token = $this->jwtManager->generateAccessToken($payload);
        $parsed = $this->jwtManager->parseToken($token);
        
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('header', $parsed);
        $this->assertArrayHasKey('payload', $parsed);
        $this->assertArrayHasKey('signature', $parsed);
        
        $this->assertEquals('JWT', $parsed['header']['typ']);
        $this->assertEquals('HS256', $parsed['header']['alg']);
        $this->assertEquals(1, $parsed['payload']['user_id']);
        $this->assertEquals('test@example.com', $parsed['payload']['email']);
    }
    
    public function testGetTokenTTL(): void
    {
        $payload = ['user_id' => 1];
        $token = $this->jwtManager->generateAccessToken($payload);
        
        $ttl = $this->jwtManager->getTokenTTL($token);
        $this->assertIsInt($ttl);
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(3600, $ttl); // Should be less than or equal to 1 hour
    }
    
    public function testBlacklistToken(): void
    {
        $payload = ['user_id' => 1];
        $token = $this->jwtManager->generateAccessToken($payload);
        
        // Token should be valid initially
        $validation = $this->jwtManager->validateToken($token);
        $this->assertTrue($validation['valid']);
        
        // Blacklist the token
        $this->jwtManager->blacklistToken($token);
        
        // Check if token is blacklisted
        $this->assertTrue($this->jwtManager->isTokenBlacklisted($token));
    }
    
    public function testGeneratePasswordResetToken(): void
    {
        $userId = 1;
        $email = 'test@example.com';
        
        $token = $this->jwtManager->generatePasswordResetToken($userId, $email);
        $this->assertIsString($token);
        $this->assertNotEmpty($token);
        
        // Validate the reset token
        $validation = $this->jwtManager->validateToken($token);
        $this->assertTrue($validation['valid']);
        $this->assertEquals('password_reset', $validation['payload']['type']);
        $this->assertEquals($userId, $validation['payload']['user_id']);
        $this->assertEquals($email, $validation['payload']['email']);
    }
    
    public function testTokenWithDifferentSecrets(): void
    {
        $payload = ['user_id' => 1];
        
        // Create token with first manager
        $token = $this->jwtManager->generateAccessToken($payload);
        
        // Try to validate with different secret
        $differentSecretManager = new JWTManager('different-secret-key-32-chars-min', 'Test-Issuer');
        $validation = $differentSecretManager->validateToken($token);
        
        $this->assertFalse($validation['valid']);
        $this->assertStringContains('signature verification failed', strtolower($validation['error']));
    }
    
    public function testTokenWithTamperedPayload(): void
    {
        $payload = ['user_id' => 1];
        $token = $this->jwtManager->generateAccessToken($payload);
        
        // Tamper with the token by changing middle part
        $parts = explode('.', $token);
        $parts[1] = base64_encode('{"user_id":999,"tampered":true}');
        $tamperedToken = implode('.', $parts);
        
        $validation = $this->jwtManager->validateToken($tamperedToken);
        $this->assertFalse($validation['valid']);
    }
    
    public function testCustomConfiguration(): void
    {
        $customConfig = [
            'access_token_ttl' => 7200, // 2 hours
            'refresh_token_ttl' => 1209600, // 2 weeks
            'algorithm' => 'HS256'
        ];
        
        $customManager = new JWTManager($this->testSecret, 'Custom-Issuer', $customConfig);
        
        $payload = ['user_id' => 1];
        $tokens = $customManager->generateTokenPair($payload);
        
        $this->assertEquals(7200, $tokens['expires_in']);
        
        // Validate access token
        $validation = $customManager->validateToken($tokens['access_token']);
        $this->assertTrue($validation['valid']);
        $this->assertEquals('Custom-Issuer', $validation['payload']['iss']);
    }
}