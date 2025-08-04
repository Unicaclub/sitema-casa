<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use ERP\Core\Auth\AuthenticationService;
use ERP\Core\Auth\JWTManager;
use ERP\Core\Database\DatabaseManager;
use ERP\Core\Security\AuditManager;

/**
 * Authentication Service Unit Tests
 * 
 * @package Tests\Unit\Auth
 */
final class AuthenticationServiceTest extends TestCase
{
    private AuthenticationService $authService;
    private MockObject|DatabaseManager $mockDb;
    private MockObject|JWTManager $mockJwt;
    private MockObject|AuditManager $mockAudit;
    
    protected function setUp(): void
    {
        $this->mockDb = $this->createMock(DatabaseManager::class);
        $this->mockJwt = $this->createMock(JWTManager::class);
        $this->mockAudit = $this->createMock(AuditManager::class);
        
        $this->authService = new AuthenticationService(
            $this->mockDb,
            $this->mockJwt,
            $this->mockAudit
        );
    }
    
    public function testSuccessfulAuthentication(): void
    {
        $email = 'admin@erp-sistema.local';
        $password = 'admin123!@#';
        $context = ['ip_address' => '127.0.0.1', 'user_agent' => 'PHPUnit Test'];
        
        // Mock JWT token generation
        $expectedTokens = [
            'access_token' => 'mock_access_token',
            'refresh_token' => 'mock_refresh_token',
            'token_type' => 'Bearer',
            'expires_in' => 3600
        ];
        
        $this->mockJwt->expects($this->once())
            ->method('generateTokenPair')
            ->willReturn($expectedTokens);
            
        $this->mockAudit->expects($this->atLeastOnce())
            ->method('logEvent');
        
        $result = $this->authService->authenticate($email, $password, $context);
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('user', $result);
        $this->assertArrayHasKey('tokens', $result);
        $this->assertEquals($expectedTokens, $result['tokens']);
        $this->assertEquals('System Administrator', $result['user']['name']);
        $this->assertEquals($email, $result['user']['email']);
    }
    
    public function testAuthenticationWithInvalidCredentials(): void
    {
        $email = 'nonexistent@example.com';
        $password = 'wrongpassword';
        $context = ['ip_address' => '127.0.0.1'];
        
        $this->mockAudit->expects($this->atLeastOnce())
            ->method('logEvent');
        
        $result = $this->authService->authenticate($email, $password, $context);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('INVALID_CREDENTIALS', $result['error_code']);
        $this->assertEquals('Invalid credentials', $result['error']);
    }
    
    public function testAuthenticationWithWrongPassword(): void
    {
        $email = 'admin@erp-sistema.local';
        $password = 'wrongpassword';
        $context = ['ip_address' => '127.0.0.1'];
        
        $this->mockAudit->expects($this->atLeastOnce())
            ->method('logEvent');
        
        $result = $this->authService->authenticate($email, $password, $context);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('INVALID_CREDENTIALS', $result['error_code']);
    }
    
    public function testSuccessfulTokenRefresh(): void
    {
        $refreshToken = 'valid_refresh_token';
        $context = ['ip_address' => '127.0.0.1'];
        
        // Mock JWT validation
        $this->mockJwt->expects($this->once())
            ->method('validateToken')
            ->with($refreshToken)
            ->willReturn([
                'valid' => true,
                'payload' => [
                    'type' => 'refresh',
                    'user_id' => 1,
                    'jti' => 'test_jti'
                ]
            ]);
        
        // Mock new token generation
        $newTokens = [
            'access_token' => 'new_access_token',
            'refresh_token' => 'new_refresh_token',
            'token_type' => 'Bearer',
            'expires_in' => 3600
        ];
        
        $this->mockJwt->expects($this->once())
            ->method('generateTokenPair')
            ->willReturn($newTokens);
        
        $this->mockAudit->expects($this->atLeastOnce())
            ->method('logEvent');
        
        $result = $this->authService->refreshToken($refreshToken, $context);
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('tokens', $result);
        $this->assertEquals($newTokens, $result['tokens']);
    }
    
    public function testTokenRefreshWithInvalidToken(): void
    {
        $invalidToken = 'invalid_refresh_token';
        $context = ['ip_address' => '127.0.0.1'];
        
        $this->mockJwt->expects($this->once())
            ->method('validateToken')
            ->with($invalidToken)
            ->willReturn([
                'valid' => false,
                'error' => 'Invalid token'
            ]);
        
        $result = $this->authService->refreshToken($invalidToken, $context);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('INVALID_REFRESH_TOKEN', $result['error_code']);
    }
    
    public function testTokenRefreshWithWrongTokenType(): void
    {
        $accessToken = 'access_token_not_refresh';
        $context = ['ip_address' => '127.0.0.1'];
        
        $this->mockJwt->expects($this->once())
            ->method('validateToken')
            ->with($accessToken)
            ->willReturn([
                'valid' => true,
                'payload' => [
                    'type' => 'access', // Wrong type
                    'user_id' => 1
                ]
            ]);
        
        $result = $this->authService->refreshToken($accessToken, $context);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('INVALID_TOKEN_TYPE', $result['error_code']);
    }
    
    public function testSuccessfulLogout(): void
    {
        $accessToken = 'valid_access_token';
        $refreshToken = 'valid_refresh_token';
        
        $this->mockJwt->expects($this->once())
            ->method('blacklistToken')
            ->with($accessToken);
        
        $this->mockJwt->expects($this->once())
            ->method('parseToken')
            ->with($accessToken)
            ->willReturn([
                'payload' => ['user_id' => 1]
            ]);
        
        $this->mockAudit->expects($this->once())
            ->method('logEvent')
            ->with('logout', $this->callback(function ($data) {
                return $data['user_id'] === 1;
            }));
        
        $result = $this->authService->logout($accessToken, $refreshToken);
        
        $this->assertTrue($result['success']);
        $this->assertEquals('Logout successful', $result['message']);
    }
    
    public function testValidateValidAccessToken(): void
    {
        $token = 'valid_access_token';
        
        $this->mockJwt->expects($this->once())
            ->method('isTokenBlacklisted')
            ->with($token)
            ->willReturn(false);
        
        $this->mockJwt->expects($this->once())
            ->method('validateToken')
            ->with($token)
            ->willReturn([
                'valid' => true,
                'payload' => [
                    'type' => 'access',
                    'user_id' => 1,
                    'iat' => time(),
                    'exp' => time() + 3600
                ]
            ]);
        
        $result = $this->authService->validateAccessToken($token);
        
        $this->assertTrue($result['valid']);
        $this->assertArrayHasKey('user', $result);
        $this->assertArrayHasKey('payload', $result);
        $this->assertEquals(1, $result['user']['id']);
    }
    
    public function testValidateBlacklistedToken(): void
    {
        $token = 'blacklisted_token';
        
        $this->mockJwt->expects($this->once())
            ->method('isTokenBlacklisted')
            ->with($token)
            ->willReturn(true);
        
        $result = $this->authService->validateAccessToken($token);
        
        $this->assertFalse($result['valid']);
        $this->assertEquals('Token is blacklisted', $result['error']);
    }
    
    public function testValidateInvalidAccessToken(): void
    {
        $token = 'invalid_access_token';
        
        $this->mockJwt->expects($this->once())
            ->method('isTokenBlacklisted')
            ->with($token)
            ->willReturn(false);
        
        $this->mockJwt->expects($this->once())
            ->method('validateToken')
            ->with($token)
            ->willReturn([
                'valid' => false,
                'error' => 'Invalid token signature'
            ]);
        
        $result = $this->authService->validateAccessToken($token);
        
        $this->assertFalse($result['valid']);
        $this->assertEquals('Invalid token signature', $result['error']);
    }
    
    public function testValidateRefreshTokenAsAccessToken(): void
    {
        $token = 'refresh_token_not_access';
        
        $this->mockJwt->expects($this->once())
            ->method('isTokenBlacklisted')
            ->with($token)
            ->willReturn(false);
        
        $this->mockJwt->expects($this->once())
            ->method('validateToken')
            ->with($token)
            ->willReturn([
                'valid' => true,
                'payload' => [
                    'type' => 'refresh', // Wrong type for access token validation
                    'user_id' => 1
                ]
            ]);
        
        $result = $this->authService->validateAccessToken($token);
        
        $this->assertFalse($result['valid']);
        $this->assertEquals('Invalid token type', $result['error']);
    }
    
    public function testAuthenticationLogsEvents(): void
    {
        $email = 'admin@erp-sistema.local';
        $password = 'admin123!@#';
        $context = ['ip_address' => '192.168.1.100', 'user_agent' => 'Test Agent'];
        
        $this->mockJwt->method('generateTokenPair')->willReturn([
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_type' => 'Bearer',
            'expires_in' => 3600
        ]);
        
        // Expect multiple audit log calls
        $this->mockAudit->expects($this->exactly(3))
            ->method('logEvent')
            ->withConsecutive(
                ['login_attempt', $this->callback(function ($data) use ($email, $context) {
                    return $data['email'] === $email && 
                           $data['ip_address'] === $context['ip_address'];
                })],
                ['login_info_updated', $this->anything()],
                ['login_success', $this->callback(function ($data) use ($email) {
                    return $data['email'] === $email && 
                           isset($data['user_id']);
                })]
            );
        
        $result = $this->authService->authenticate($email, $password, $context);
        $this->assertTrue($result['success']);
    }
}