<?php

declare(strict_types=1);

namespace ERP\Core\RateLimit;

use ERP\Core\Http\Request;
use ERP\Core\Http\Response;
use ERP\Core\Http\MiddlewareInterface;
use ERP\Core\Cache\RedisManager;
use ERP\Core\Security\AuditManager;

/**
 * Rate Limit Middleware - Middleware Inteligente de Rate Limiting
 * 
 * Middleware para aplicar rate limiting automático em todas as requisições
 * 
 * @package ERP\Core\RateLimit
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    private RateLimitManager $rateLimitManager;
    private array $config;
    
    public function __construct(RateLimitManager $rateLimitManager, array $config = [])
    {
        $this->rateLimitManager = $rateLimitManager;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }
    
    /**
     * Processar middleware de rate limiting
     */
    public function handle(Request $request, callable $next): Response
    {
        // Extrair informações da requisição
        $context = $this->buildRequestContext($request);
        $endpoint = $this->normalizeEndpoint($request->getUri());
        
        // Executar verificações de rate limiting
        $rateLimitResults = $this->executeRateLimitChecks($request, $endpoint, $context);
        
        // Verificar se alguma verificação falhou
        foreach ($rateLimitResults as $checkType => $result) {
            if (! $result['allowed']) {
                return $this->createRateLimitResponse($result, $checkType);
            }
        }
        
        // Adicionar headers de rate limiting
        $response = $next($request);
        return $this->addRateLimitHeaders($response, $rateLimitResults);
    }
    
    /**
     * Executar todas as verificações de rate limiting
     */
    private function executeRateLimitChecks(Request $request, string $endpoint, array $context): array
    {
        $results = [];
        
        // 1. Rate limiting por IP
        if ($this->config['enable_ip_limiting']) {
            $ip = $this->getClientIP($request);
            $results['ip'] = $this->rateLimitManager->checkIPRateLimit($ip, $endpoint, $context);
        }
        
        // 2. Rate limiting por usuário (se autenticado)
        if ($this->config['enable_user_limiting'] && $context['user_id']) {
            $results['user'] = $this->rateLimitManager->checkUserRateLimit(
                $context['user_id'], 
                $endpoint, 
                $context
            );
        }
        
        // 3. Rate limiting por API key (se presente)
        if ($this->config['enable_api_key_limiting'] && $context['api_key']) {
            $results['api_key'] = $this->rateLimitManager->checkAPIKeyRateLimit(
                $context['api_key'], 
                $endpoint, 
                $context
            );
        }
        
        // 4. Rate limiting geográfico
        if ($this->config['enable_geo_limiting'] && $context['country']) {
            $results['geo'] = $this->rateLimitManager->checkGeoRateLimit(
                $context['ip'], 
                $context['country'], 
                $endpoint
            );
        }
        
        // 5. Rate limiting por dispositivo
        if ($this->config['enable_device_limiting'] && $context['device_fingerprint']) {
            $results['device'] = $this->rateLimitManager->checkDeviceRateLimit(
                $context['device_fingerprint'], 
                $endpoint, 
                $context
            );
        }
        
        // 6. Proteção anti-burst
        if ($this->config['enable_burst_protection']) {
            $identifier = $this->getBurstIdentifier($context);
            $requests = $this->getRecentRequests($identifier);
            $results['burst'] = $this->rateLimitManager->checkBurstProtection($identifier, $requests);
        }
        
        return array_filter($results); // Remove resultados vazios
    }
    
    /**
     * Construir contexto da requisição
     */
    private function buildRequestContext(Request $request): array
    {
        return [
            'ip' => $this->getClientIP($request),
            'user_agent' => $request->getHeader('User-Agent') ?? '',
            'referer' => $request->getHeader('Referer') ?? '',
            'method' => $request->getMethod(),
            'path' => $request->getUri(),
            'timestamp' => time(),
            'user_id' => $this->getUserId($request),
            'api_key' => $this->getAPIKey($request),
            'country' => $this->getCountryFromIP($request),
            'device_fingerprint' => $this->generateDeviceFingerprint($request),
            'is_mobile' => $this->isMobileDevice($request),
            'is_bot' => $this->isBotRequest($request),
            'request_size' => $this->getRequestSize($request),
            'content_type' => $request->getHeader('Content-Type') ?? '',
            'accept_language' => $request->getHeader('Accept-Language') ?? '',
            'forwarded_for' => $request->getHeader('X-Forwarded-For') ?? '',
            'real_ip' => $request->getHeader('X-Real-IP') ?? ''
        ];
    }
    
    /**
     * Normalizar endpoint para categorização
     */
    private function normalizeEndpoint(string $uri): string
    {
        // Remover query parameters
        $path = parse_url($uri, PHP_URL_PATH) ?? $uri;
        
        // Normalizar IDs numéricos
        $path = preg_replace('/\/\d+/', '/{id}', $path);
        
        // Normalizar UUIDs
        $path = preg_replace('/\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', '/{uuid}', $path);
        
        return $path;
    }
    
    /**
     * Criar resposta de rate limit excedido
     */
    private function createRateLimitResponse(array $result, string $checkType): Response
    {
        $statusCode = $this->getStatusCodeForReason($result['reason'] ?? 'RATE_LIMIT_EXCEEDED');
        
        $responseData = [
            'error' => true,
            'message' => $result['message'] ?? 'Rate limit exceeded',
            'type' => $checkType,
            'reason' => $result['reason'] ?? 'RATE_LIMIT_EXCEEDED',
            'retry_after' => $result['retry_after'] ?? 60,
            'limit' => $result['limit'] ?? null,
            'remaining' => $result['remaining'] ?? 0,
            'reset_at' => $result['reset_at'] ?? null,
            'timestamp' => time()
        ];
        
        $headers = [
            'Content-Type' => 'application/json',
            'X-RateLimit-Type' => $checkType,
            'X-RateLimit-Reason' => $result['reason'] ?? 'RATE_LIMIT_EXCEEDED'
        ];
        
        // Adicionar headers específicos
        if (isset($result['retry_after'])) {
            $headers['Retry-After'] = (string)$result['retry_after'];
        }
        
        if (isset($result['limit'])) {
            $headers['X-RateLimit-Limit'] = (string)$result['limit'];
        }
        
        if (isset($result['remaining'])) {
            $headers['X-RateLimit-Remaining'] = (string)$result['remaining'];
        }
        
        if (isset($result['reset_at'])) {
            $headers['X-RateLimit-Reset'] = (string)$result['reset_at'];
        }
        
        return new Response(
            json_encode($responseData, JSON_THROW_ON_ERROR),
            $statusCode,
            $headers
        );
    }
    
    /**
     * Adicionar headers de rate limiting à resposta
     */
    private function addRateLimitHeaders(Response $response, array $rateLimitResults): Response
    {
        foreach ($rateLimitResults as $type => $result) {
            if (! $result['allowed']) continue;
            
            $prefix = 'X-RateLimit-' . ucfirst($type);
            
            if (isset($result['limit'])) {
                $response = $response->withHeader($prefix . '-Limit', (string)$result['limit']);
            }
            
            if (isset($result['remaining'])) {
                $response = $response->withHeader($prefix . '-Remaining', (string)$result['remaining']);
            }
            
            if (isset($result['reset_at'])) {
                $response = $response->withHeader($prefix . '-Reset', (string)$result['reset_at']);
            }
            
            if (isset($result['window'])) {
                $response = $response->withHeader($prefix . '-Window', (string)$result['window']);
            }
        }
        
        // Header geral indicando que rate limiting está ativo
        $response = $response->withHeader('X-RateLimit-Policy', 'adaptive');
        
        return $response;
    }
    
    /**
     * Obter IP do cliente considerando proxies
     */
    private function getClientIP(Request $request): string
    {
        $headers = [
            'X-Forwarded-For',
            'X-Real-IP',
            'X-Client-IP',
            'CF-Connecting-IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP'
        ];
        
        foreach ($headers as $header) {
            $ip = $request->getHeader($header);
            if ($ip && $this->isValidIP($ip)) {
                // Se há múltiplos IPs (proxies), pegar o primeiro
                $ips = explode(',', $ip);
                $firstIP = trim($ips[0]);
                if ($this->isValidIP($firstIP)) {
                    return $firstIP;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
    
    /**
     * Obter ID do usuário da requisição
     */
    private function getUserId(Request $request): ?int
    {
        // Tentar obter do JWT token
        $authHeader = $request->getHeader('Authorization');
        if ($authHeader && preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $token = $matches[1];
            // Decodificar JWT para obter user_id (implementação simplificada)
            $payload = $this->decodeJWTPayload($token);
            return $payload['user_id'] ?? null;
        }
        
        return null;
    }
    
    /**
     * Obter API key da requisição
     */
    private function getAPIKey(Request $request): ?string
    {
        // Verificar header X-API-Key
        $apiKey = $request->getHeader('X-API-Key');
        if ($apiKey) {
            return $apiKey;
        }
        
        // Verificar query parameter
        return $request->get('api_key');
    }
    
    /**
     * Gerar fingerprint do dispositivo
     */
    private function generateDeviceFingerprint(Request $request): string
    {
        $components = [
            $request->getHeader('User-Agent') ?? '',
            $request->getHeader('Accept') ?? '',
            $request->getHeader('Accept-Language') ?? '',
            $request->getHeader('Accept-Encoding') ?? '',
            $this->getClientIP($request)
        ];
        
        return hash('sha256', implode('|', $components));
    }
    
    /**
     * Verificar se é dispositivo móvel
     */
    private function isMobileDevice(Request $request): bool
    {
        $userAgent = $request->getHeader('User-Agent') ?? '';
        return preg_match('/Mobile|Android|iPhone|iPad/', $userAgent) === 1;
    }
    
    /**
     * Verificar se é requisição de bot
     */
    private function isBotRequest(Request $request): bool
    {
        $userAgent = $request->getHeader('User-Agent') ?? '';
        $botPatterns = [
            'bot', 'crawler', 'spider', 'scraper', 'curl', 'wget',
            'postman', 'insomnia', 'httpie', 'python-requests'
        ];
        
        foreach ($botPatterns as $pattern) {
            if (stripos($userAgent, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Obter tamanho da requisição
     */
    private function getRequestSize(Request $request): int
    {
        $contentLength = $request->getHeader('Content-Length');
        return $contentLength ? (int)$contentLength : 0;
    }
    
    /**
     * Obter país do IP (implementação básica)
     */
    private function getCountryFromIP(Request $request): ?string
    {
        // Implementação básica - em produção usar serviço de geolocalização
        $ip = $this->getClientIP($request);
        
        // IPs locais
        if (in_array($ip, ['127.0.0.1', '::1', 'localhost'])) {
            return 'BR';
        }
        
        // Usar serviço de geolocalização ou cache
        return $this->lookupCountryByIP($ip);
    }
    
    /**
     * Métodos auxiliares
     */
    
    private function isValidIP(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
    
    private function decodeJWTPayload(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return [];
        }
        
        $payload = base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1]));
        return json_decode($payload, true) ?? [];
    }
    
    private function lookupCountryByIP(string $ip): ?string
    {
        // Cache de países por faixas de IP (implementação simplificada)
        $countryRanges = [
            '200.0.0.0/8' => 'BR',
            '186.0.0.0/8' => 'BR',
            '191.0.0.0/8' => 'BR'
        ];
        
        foreach ($countryRanges as $range => $country) {
            if ($this->ipInRange($ip, $range)) {
                return $country;
            }
        }
        
        return null;
    }
    
    private function ipInRange(string $ip, string $range): bool
    {
        [$rangeIP, $netmask] = explode('/', $range);
        $rangeDecimal = ip2long($rangeIP);
        $ipDecimal = ip2long($ip);
        $wildcardDecimal = pow(2, (32 - (int)$netmask)) - 1;
        $netmaskDecimal = ~$wildcardDecimal;
        
        return ($ipDecimal & $netmaskDecimal) === ($rangeDecimal & $netmaskDecimal);
    }
    
    private function getBurstIdentifier(array $context): string
    {
        // Usar combinação de IP + User Agent para identificar burst
        return hash('sha256', $context['ip'] . '|' . $context['user_agent']);
    }
    
    private function getRecentRequests(string $identifier): array
    {
        // Implementação simplificada - retornar array de timestamps
        return [];
    }
    
    private function getStatusCodeForReason(string $reason): int
    {
        return match ($reason) {
            'RATE_LIMIT_EXCEEDED' => 429,
            'IP_BLOCKED' => 403,
            'DDOS_DETECTED' => 503,
            'SUSPICIOUS_DEVICE' => 403,
            'QUOTA_EXCEEDED' => 402,
            'BURST_PROTECTION' => 429,
            default => 429
        };
    }
    
    private function getDefaultConfig(): array
    {
        return [
            'enable_ip_limiting' => true,
            'enable_user_limiting' => true,
            'enable_api_key_limiting' => true,
            'enable_geo_limiting' => true,
            'enable_device_limiting' => true,
            'enable_burst_protection' => true,
            'bypass_for_whitelisted' => true,
            'log_violations' => true,
            'add_headers' => true
        ];
    }
}
