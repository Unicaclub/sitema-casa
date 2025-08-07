<?php

declare(strict_types=1);

namespace ERP\Core\Auth;

use Exception;

/**
 * JWT Manager - Sistema de Autenticação JWT Real
 * 
 * Gerencia tokens JWT com segurança enterprise
 * 
 * @package ERP\Core\Auth
 */
final class JWTManager
{
    private string $secretKey;
    private string $algorithm = 'HS256';
    private int $accessTokenTTL = 3600; // 1 hour
    private int $refreshTokenTTL = 604800; // 7 days
    private string $issuer;
    private array $allowedAlgorithms = ['HS256', 'HS384', 'HS512'];
    
    public function __construct(string $secretKey, string $issuer = 'ERP-Sistema')
    {
        if (strlen($secretKey) < 32) {
            throw new Exception('JWT secret key must be at least 32 characters long');
        }
        
        $this->secretKey = $secretKey;
        $this->issuer = $issuer;
    }
    
    /**
     * Gerar token de acesso
     */
    public function generateAccessToken(array $payload): string
    {
        $header = [
            'typ' => 'JWT',
            'alg' => $this->algorithm
        ];
        
        $currentTime = time();
        $tokenPayload = array_merge($payload, [
            'iss' => $this->issuer,
            'iat' => $currentTime,
            'exp' => $currentTime + $this->accessTokenTTL,
            'nbf' => $currentTime,
            'jti' => $this->generateJTI(),
            'type' => 'access'
        ]);
        
        return $this->createToken($header, $tokenPayload);
    }
    
    /**
     * Gerar token de refresh
     */
    public function generateRefreshToken(int $userId, int $tenantId): string
    {
        $header = [
            'typ' => 'JWT',
            'alg' => $this->algorithm
        ];
        
        $currentTime = time();
        $payload = [
            'iss' => $this->issuer,
            'iat' => $currentTime,
            'exp' => $currentTime + $this->refreshTokenTTL,
            'nbf' => $currentTime,
            'jti' => $this->generateJTI(),
            'type' => 'refresh',
            'user_id' => $userId,
            'tenant_id' => $tenantId
        ];
        
        return $this->createToken($header, $payload);
    }
    
    /**
     * Validar e decodificar token
     */
    public function validateToken(string $token): array
    {
        try {
            $parts = explode('.', $token);
            
            if (count($parts) !== 3) {
                throw new Exception('Invalid token format');
            }
            
            [$headerB64, $payloadB64, $signatureB64] = $parts;
            
            // Decode header
            $header = json_decode($this->base64UrlDecode($headerB64), true);
            if (! $header) {
                throw new Exception('Invalid token header');
            }
            
            // Validate algorithm
            if (! isset($header['alg']) || ! in_array($header['alg'], $this->allowedAlgorithms)) {
                throw new Exception('Invalid or unsupported algorithm');
            }
            
            // Decode payload
            $payload = json_decode($this->base64UrlDecode($payloadB64), true);
            if (! $payload) {
                throw new Exception('Invalid token payload');
            }
            
            // Verify signature
            $expectedSignature = $this->createSignature($headerB64 . '.' . $payloadB64, $header['alg']);
            if (! hash_equals($expectedSignature, $signatureB64)) {
                throw new Exception('Invalid token signature');
            }
            
            // Validate timing
            $currentTime = time();
            
            if (isset($payload['nbf']) && $currentTime < $payload['nbf']) {
                throw new Exception('Token not yet valid');
            }
            
            if (isset($payload['exp']) && $currentTime >= $payload['exp']) {
                throw new Exception('Token has expired');
            }
            
            // Validate issuer
            if (isset($payload['iss']) && $payload['iss'] !== $this->issuer) {
                throw new Exception('Invalid token issuer');
            }
            
            return [
                'valid' => true,
                'payload' => $payload,
                'header' => $header
            ];
            
        } catch (Exception $e) {
            return [
                'valid' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Extrair informações do token sem validar
     */
    public function parseToken(string $token): array
    {
        try {
            $parts = explode('.', $token);
            
            if (count($parts) !== 3) {
                throw new Exception('Invalid token format');
            }
            
            $header = json_decode($this->base64UrlDecode($parts[0]), true);
            $payload = json_decode($this->base64UrlDecode($parts[1]), true);
            
            return [
                'success' => true,
                'header' => $header,
                'payload' => $payload
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Verificar se token está expirado
     */
    public function isTokenExpired(string $token): bool
    {
        $parsed = $this->parseToken($token);
        
        if (! $parsed['success']) {
            return true;
        }
        
        $payload = $parsed['payload'];
        
        if (! isset($payload['exp'])) {
            return true;
        }
        
        return time() >= $payload['exp'];
    }
    
    /**
     * Obter tempo restante do token
     */
    public function getTokenTTL(string $token): int
    {
        $parsed = $this->parseToken($token);
        
        if (! $parsed['success']) {
            return 0;
        }
        
        $payload = $parsed['payload'];
        
        if (! isset($payload['exp'])) {
            return 0;
        }
        
        $remaining = $payload['exp'] - time();
        return max(0, $remaining);
    }
    
    /**
     * Gerar token de reset de senha
     */
    public function generatePasswordResetToken(int $userId, string $email): string
    {
        $header = [
            'typ' => 'JWT',
            'alg' => $this->algorithm
        ];
        
        $currentTime = time();
        $payload = [
            'iss' => $this->issuer,
            'iat' => $currentTime,
            'exp' => $currentTime + 3600, // 1 hour
            'nbf' => $currentTime,
            'jti' => $this->generateJTI(),
            'type' => 'password_reset',
            'user_id' => $userId,
            'email' => $email,
            'purpose' => 'password_reset'
        ];
        
        return $this->createToken($header, $payload);
    }
    
    /**
     * Gerar token de verificação de email
     */
    public function generateEmailVerificationToken(int $userId, string $email): string
    {
        $header = [
            'typ' => 'JWT',
            'alg' => $this->algorithm
        ];
        
        $currentTime = time();
        $payload = [
            'iss' => $this->issuer,
            'iat' => $currentTime,
            'exp' => $currentTime + 86400, // 24 hours
            'nbf' => $currentTime,
            'jti' => $this->generateJTI(),
            'type' => 'email_verification',
            'user_id' => $userId,
            'email' => $email,
            'purpose' => 'email_verification'
        ];
        
        return $this->createToken($header, $payload);
    }
    
    /**
     * Criar token completo
     */
    private function createToken(array $header, array $payload): string
    {
        $headerB64 = $this->base64UrlEncode(json_encode($header));
        $payloadB64 = $this->base64UrlEncode(json_encode($payload));
        
        $signature = $this->createSignature($headerB64 . '.' . $payloadB64, $header['alg']);
        
        return $headerB64 . '.' . $payloadB64 . '.' . $signature;
    }
    
    /**
     * Criar assinatura do token
     */
    private function createSignature(string $data, string $algorithm): string
    {
        $hashAlgorithm = match ($algorithm) {
            'HS256' => 'sha256',
            'HS384' => 'sha384',
            'HS512' => 'sha512',
            default => throw new Exception("Unsupported algorithm: {$algorithm}")
        };
        
        $signature = hash_hmac($hashAlgorithm, $data, $this->secretKey, true);
        return $this->base64UrlEncode($signature);
    }
    
    /**
     * Gerar JWT ID único
     */
    private function generateJTI(): string
    {
        return bin2hex(random_bytes(16));
    }
    
    /**
     * Base64 URL encode
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    /**
     * Base64 URL decode
     */
    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        
        return base64_decode(strtr($data, '-_', '+/'));
    }
    
    /**
     * Configurar TTL dos tokens
     */
    public function setTokenTTL(int $accessTTL, int $refreshTTL): void
    {
        $this->accessTokenTTL = $accessTTL;
        $this->refreshTokenTTL = $refreshTTL;
    }
    
    /**
     * Configurar algoritmo
     */
    public function setAlgorithm(string $algorithm): void
    {
        if (! in_array($algorithm, $this->allowedAlgorithms)) {
            throw new Exception("Unsupported algorithm: {$algorithm}");
        }
        
        $this->algorithm = $algorithm;
    }
    
    /**
     * Blacklist token (para logout)
     */
    public function blacklistToken(string $token): bool
    {
        $parsed = $this->parseToken($token);
        
        if (! $parsed['success']) {
            return false;
        }
        
        $jti = $parsed['payload']['jti'] ?? null;
        $exp = $parsed['payload']['exp'] ?? null;
        
        if (! $jti || ! $exp) {
            return false;
        }
        
        // Store in cache/database with expiration
        $cacheKey = "jwt_blacklist:{$jti}";
        $ttl = $exp - time();
        
        if ($ttl > 0) {
            // In production, use Redis or database
            // For now, we'll simulate it
            return true;
        }
        
        return false;
    }
    
    /**
     * Verificar se token está na blacklist
     */
    public function isTokenBlacklisted(string $token): bool
    {
        $parsed = $this->parseToken($token);
        
        if (! $parsed['success']) {
            return true;
        }
        
        $jti = $parsed['payload']['jti'] ?? null;
        
        if (! $jti) {
            return true;
        }
        
        // Check cache/database for blacklisted token
        $cacheKey = "jwt_blacklist:{$jti}";
        
        // In production, check Redis or database
        // For now, we'll return false
        return false;
    }
    
    /**
     * Gerar par de tokens (access + refresh)
     */
    public function generateTokenPair(array $userPayload): array
    {
        $accessToken = $this->generateAccessToken($userPayload);
        $refreshToken = $this->generateRefreshToken(
            $userPayload['user_id'], 
            $userPayload['tenant_id']
        );
        
        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $this->accessTokenTTL,
            'refresh_expires_in' => $this->refreshTokenTTL
        ];
    }
}
