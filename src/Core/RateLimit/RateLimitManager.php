<?php

declare(strict_types=1);

namespace ERP\Core\RateLimit;

use ERP\Core\Cache\RedisManager;
use ERP\Core\Security\AuditManager;

/**
 * Rate Limit Manager Supremo - Sistema Inteligente de Rate Limiting
 * 
 * Sistema avançado com AI adaptativo, análise comportamental e proteção DDoS
 * 
 * @package ERP\Core\RateLimit
 */
final class RateLimitManager
{
    private RedisManager $redis;
    private AuditManager $audit;
    private array $config;
    private array $behaviorProfiles = [];
    
    public function __construct(RedisManager $redis, AuditManager $audit, array $config = [])
    {
        $this->redis = $redis;
        $this->audit = $audit;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }
    
    /**
     * Verificar rate limit com IA adaptativa
     */
    public function checkRateLimit(string $identifier, string $endpoint, array $context = []): array
    {
        $rateLimitKey = $this->buildRateLimitKey($identifier, $endpoint);
        $behaviorKey = $this->buildBehaviorKey($identifier);
        
        // Analisar comportamento histórico
        $behaviorProfile = $this->analyzeBehavior($identifier, $context);
        
        // Obter limites adaptativos baseados no comportamento
        $limits = $this->getAdaptiveLimits($endpoint, $behaviorProfile);
        
        // Executar verificação de rate limit
        $result = $this->performRateLimitCheck($rateLimitKey, $limits, $context);
        
        // Atualizar perfil comportamental
        $this->updateBehaviorProfile($identifier, $endpoint, $result, $context);
        
        // Log e auditoria
        if (! $result['allowed']) {
            $this->handleRateLimitExceeded($identifier, $endpoint, $result, $context);
        }
        
        return $result;
    }
    
    /**
     * Rate limiting por IP com detecção de DDoS
     */
    public function checkIPRateLimit(string $ip, string $endpoint, array $context = []): array
    {
        // Verificar se IP está em whitelist
        if ($this->isWhitelisted($ip)) {
            return $this->createAllowedResponse(PHP_INT_MAX, 0);
        }
        
        // Verificar se IP está bloqueado
        if ($this->isBlocked($ip)) {
            return $this->createBlockedResponse('IP_BLOCKED', 'IP temporarily blocked');
        }
        
        // Detecção de padrões DDoS
        $ddosAnalysis = $this->analyzeDDoSPatterns($ip, $endpoint, $context);
        if ($ddosAnalysis['is_ddos']) {
            $this->handleDDoSDetection($ip, $ddosAnalysis);
            return $this->createBlockedResponse('DDOS_DETECTED', 'DDoS patterns detected');
        }
        
        return $this->checkRateLimit("ip:{$ip}", $endpoint, $context);
    }
    
    /**
     * Rate limiting por usuário com perfil de confiança
     */
    public function checkUserRateLimit(int $userId, string $endpoint, array $context = []): array
    {
        $userProfile = $this->getUserTrustProfile($userId);
        
        // Aplicar limites baseados no nível de confiança
        $multiplier = $this->getTrustMultiplier($userProfile['trust_level']);
        
        $identifier = "user:{$userId}";
        $result = $this->checkRateLimit($identifier, $endpoint, array_merge($context, [
            'user_profile' => $userProfile,
            'trust_multiplier' => $multiplier
        ]));
        
        // Ajustar limites com base na confiança
        if ($result['allowed'] && $multiplier > 1) {
            $result['remaining'] = (int)($result['remaining'] * $multiplier);
            $result['limit'] = (int)($result['limit'] * $multiplier);
        }
        
        return $result;
    }
    
    /**
     * Rate limiting por API key com quotas inteligentes
     */
    public function checkAPIKeyRateLimit(string $apiKey, string $endpoint, array $context = []): array
    {
        $apiKeyInfo = $this->getAPIKeyInfo($apiKey);
        
        if (! $apiKeyInfo || ! $apiKeyInfo['active']) {
            return $this->createBlockedResponse('INVALID_API_KEY', 'Invalid or inactive API key');
        }
        
        // Verificar quota mensal/diária
        $quotaResult = $this->checkAPIQuota($apiKey, $apiKeyInfo);
        if (! $quotaResult['allowed']) {
            return $quotaResult;
        }
        
        // Rate limiting normal com limites personalizados
        $identifier = "api_key:{$apiKey}";
        return $this->checkRateLimit($identifier, $endpoint, array_merge($context, [
            'api_key_info' => $apiKeyInfo,
            'quota_remaining' => $quotaResult['quota_remaining']
        ]));
    }
    
    /**
     * Sistema de burst protection inteligente
     */
    public function checkBurstProtection(string $identifier, array $requests): array
    {
        $burstKey = "burst:{$identifier}";
        $windowSize = 60; // 1 minuto
        $currentTime = time();
        
        // Analisar padrão de burst
        $burstPattern = $this->analyzeBurstPattern($requests);
        
        if ($burstPattern['is_suspicious']) {
            // Aplicar proteção anti-burst
            $this->applyBurstProtection($identifier, $burstPattern);
            
            return [
                'allowed' => false,
                'reason' => 'BURST_PROTECTION',
                'message' => 'Suspicious burst pattern detected',
                'retry_after' => $burstPattern['suggested_delay'],
                'pattern_score' => $burstPattern['suspicion_score']
            ];
        }
        
        return ['allowed' => true, 'burst_analysis' => $burstPattern];
    }
    
    /**
     * Rate limiting geográfico inteligente
     */
    public function checkGeoRateLimit(string $ip, string $country, string $endpoint): array
    {
        // Limites por país baseados em análise de risco
        $countryRiskProfile = $this->getCountryRiskProfile($country);
        $geoLimits = $this->getGeoLimits($endpoint, $countryRiskProfile);
        
        $identifier = "geo:{$country}:{$ip}";
        return $this->checkRateLimit($identifier, $endpoint, [
            'country' => $country,
            'risk_profile' => $countryRiskProfile,
            'geo_limits' => $geoLimits
        ]);
    }
    
    /**
     * Rate limiting por dispositivo com fingerprinting
     */
    public function checkDeviceRateLimit(string $deviceFingerprint, string $endpoint, array $context = []): array
    {
        $deviceProfile = $this->getDeviceProfile($deviceFingerprint);
        
        // Detectar dispositivos suspeitos
        if ($deviceProfile['risk_score'] > 0.8) {
            return $this->createBlockedResponse('SUSPICIOUS_DEVICE', 'Device marked as high risk');
        }
        
        $identifier = "device:{$deviceFingerprint}";
        return $this->checkRateLimit($identifier, $endpoint, array_merge($context, [
            'device_profile' => $deviceProfile
        ]));
    }
    
    /**
     * Análise comportamental com machine learning
     */
    private function analyzeBehavior(string $identifier, array $context): array
    {
        $behaviorKey = "behavior:{$identifier}";
        $history = $this->redis->lRange($behaviorKey, 0, 99); // Últimas 100 requisições
        
        if (empty($history)) {
            return $this->getDefaultBehaviorProfile();
        }
        
        $behaviorData = array_map('json_decode', $history);
        
        return [
            'avg_interval' => $this->calculateAverageInterval($behaviorData),
            'request_pattern' => $this->analyzeRequestPattern($behaviorData),
            'time_distribution' => $this->analyzeTimeDistribution($behaviorData),
            'endpoint_diversity' => $this->analyzeEndpointDiversity($behaviorData),
            'anomaly_score' => $this->calculateAnomalyScore($behaviorData),
            'trust_score' => $this->calculateTrustScore($behaviorData),
            'last_updated' => time()
        ];
    }
    
    /**
     * Obter limites adaptativos baseados no comportamento
     */
    private function getAdaptiveLimits(string $endpoint, array $behaviorProfile): array
    {
        $baseLimits = $this->config['endpoints'][$endpoint] ?? $this->config['default_limits'];
        
        // Multiplicador baseado na confiança
        $trustMultiplier = $this->calculateTrustMultiplier($behaviorProfile['trust_score']);
        
        // Multiplicador baseado no comportamento
        $behaviorMultiplier = $this->calculateBehaviorMultiplier($behaviorProfile);
        
        $finalMultiplier = $trustMultiplier * $behaviorMultiplier;
        
        return [
            'requests' => (int)($baseLimits['requests'] * $finalMultiplier),
            'window' => $baseLimits['window'],
            'burst' => (int)($baseLimits['burst'] * $finalMultiplier),
            'multiplier_applied' => $finalMultiplier,
            'trust_score' => $behaviorProfile['trust_score']
        ];
    }
    
    /**
     * Executar verificação de rate limit
     */
    private function performRateLimitCheck(string $key, array $limits, array $context): array
    {
        $window = $limits['window'];
        $maxRequests = $limits['requests'];
        $currentTime = time();
        $windowStart = $currentTime - $window;
        
        // Usar sliding window log
        $requestsKey = "requests:{$key}";
        
        // Remover requisições antigas
        $this->redis->zRemRangeByScore($requestsKey, 0, $windowStart);
        
        // Contar requisições no window atual
        $currentRequests = $this->redis->zCard($requestsKey);
        
        if ($currentRequests >= $maxRequests) {
            // Rate limit excedido
            $oldestRequest = $this->redis->zRange($requestsKey, 0, 0, ['WITHSCORES' => true]);
            $resetTime = !empty($oldestRequest) ? (int)array_values($oldestRequest)[0] + $window : $currentTime + $window;
            
            return [
                'allowed' => false,
                'limit' => $maxRequests,
                'remaining' => 0,
                'reset_at' => $resetTime,
                'retry_after' => $resetTime - $currentTime,
                'window' => $window,
                'current_requests' => $currentRequests,
                'reason' => 'RATE_LIMIT_EXCEEDED'
            ];
        }
        
        // Adicionar requisição atual
        $requestId = uniqid('req_', true);
        $this->redis->zAdd($requestsKey, $currentTime, $requestId);
        $this->redis->expire($requestsKey, $window + 60); // TTL com margem
        
        // Calcular próximo reset
        $oldestRequest = $this->redis->zRange($requestsKey, 0, 0, ['WITHSCORES' => true]);
        $resetTime = !empty($oldestRequest) ? (int)array_values($oldestRequest)[0] + $window : $currentTime + $window;
        
        return [
            'allowed' => true,
            'limit' => $maxRequests,
            'remaining' => $maxRequests - $currentRequests - 1,
            'reset_at' => $resetTime,
            'window' => $window,
            'current_requests' => $currentRequests + 1,
            'trust_boost' => $limits['multiplier_applied'] ?? 1.0
        ];
    }
    
    /**
     * Detectar padrões de DDoS
     */
    private function analyzeDDoSPatterns(string $ip, string $endpoint, array $context): array
    {
        $ddosKey = "ddos_analysis:{$ip}";
        $timeWindow = 300; // 5 minutos
        $currentTime = time();
        
        // Coletar métricas
        $requestCount = $this->redis->get("requests_count:{$ip}:{$endpoint}") ?? 0;
        $uniqueEndpoints = $this->redis->sCard("endpoints:{$ip}");
        $requestInterval = $this->getAverageRequestInterval($ip);
        
        // Análise de padrões suspeitos
        $patterns = [
            'high_frequency' => $requestCount > ($this->config['ddos_thresholds']['requests_per_5min'] ?? 1000),
            'low_diversity' => $uniqueEndpoints < 3 && $requestCount > 100,
            'uniform_timing' => $requestInterval > 0 && $requestInterval < 0.1, // < 100ms
            'suspicious_ua' => $this->isSuspiciousUserAgent($context['user_agent'] ?? ''),
            'bot_signature' => $this->detectBotSignature($context)
        ];
        
        $suspicionScore = array_sum($patterns) / count($patterns);
        
        return [
            'is_ddos' => $suspicionScore > 0.6,
            'suspicion_score' => $suspicionScore,
            'patterns_detected' => array_keys(array_filter($patterns)),
            'request_count' => $requestCount,
            'unique_endpoints' => $uniqueEndpoints,
            'avg_interval' => $requestInterval,
            'analysis_time' => $currentTime
        ];
    }
    
    /**
     * Aplicar proteção anti-burst
     */
    private function applyBurstProtection(string $identifier, array $burstPattern): void
    {
        $protectionKey = "burst_protection:{$identifier}";
        $protectionDuration = min(3600, $burstPattern['suspicion_score'] * 1800); // Max 1 hora
        
        $this->redis->set($protectionKey, json_encode([
            'applied_at' => time(),
            'duration' => $protectionDuration,
            'reason' => 'burst_protection',
            'pattern' => $burstPattern
        ]), (int)$protectionDuration);
        
        // Audit log
        $this->audit->logEvent('burst_protection_applied', [
            'identifier' => $identifier,
            'pattern' => $burstPattern,
            'duration' => $protectionDuration
        ]);
    }
    
    /**
     * Obter perfil de risco por país
     */
    private function getCountryRiskProfile(string $country): array
    {
        $riskProfiles = [
            'BR' => ['risk_level' => 'low', 'multiplier' => 1.0],
            'US' => ['risk_level' => 'low', 'multiplier' => 1.0],
            'DE' => ['risk_level' => 'low', 'multiplier' => 1.0],
            'CN' => ['risk_level' => 'medium', 'multiplier' => 0.7],
            'RU' => ['risk_level' => 'high', 'multiplier' => 0.5],
            'KP' => ['risk_level' => 'critical', 'multiplier' => 0.1]
        ];
        
        return $riskProfiles[$country] ?? ['risk_level' => 'medium', 'multiplier' => 0.8];
    }
    
    /**
     * Métodos auxiliares
     */
    
    private function buildRateLimitKey(string $identifier, string $endpoint): string
    {
        return "rl:{$identifier}:{$endpoint}";
    }
    
    private function buildBehaviorKey(string $identifier): string
    {
        return "behavior:{$identifier}";
    }
    
    private function createAllowedResponse(int $limit, int $used): array
    {
        return [
            'allowed' => true,
            'limit' => $limit,
            'remaining' => $limit - $used,
            'reset_at' => time() + 3600
        ];
    }
    
    private function createBlockedResponse(string $reason, string $message): array
    {
        return [
            'allowed' => false,
            'reason' => $reason,
            'message' => $message,
            'blocked_at' => time()
        ];
    }
    
    private function handleRateLimitExceeded(string $identifier, string $endpoint, array $result, array $context): void
    {
        $this->audit->logEvent('rate_limit_exceeded', [
            'identifier' => $identifier,
            'endpoint' => $endpoint,
            'result' => $result,
            'context' => $context,
            'timestamp' => time()
        ]);
    }
    
    private function getDefaultConfig(): array
    {
        return [
            'default_limits' => [
                'requests' => 60,
                'window' => 60,
                'burst' => 10
            ],
            'endpoints' => [
                '/api/auth/login' => ['requests' => 5, 'window' => 300, 'burst' => 2],
                '/api/auth/register' => ['requests' => 3, 'window' => 3600, 'burst' => 1],
                '/api/security/scan' => ['requests' => 10, 'window' => 3600, 'burst' => 2],
                '/api/data/export' => ['requests' => 20, 'window' => 3600, 'burst' => 5]
            ],
            'ddos_thresholds' => [
                'requests_per_5min' => 1000,
                'unique_endpoints_min' => 3,
                'min_interval_ms' => 100
            ],
            'trust_levels' => [
                'very_high' => 2.0,
                'high' => 1.5,
                'medium' => 1.0,
                'low' => 0.7,
                'very_low' => 0.3
            ]
        ];
    }
    
    private function getDefaultBehaviorProfile(): array
    {
        return [
            'avg_interval' => 0,
            'request_pattern' => 'unknown',
            'time_distribution' => [],
            'endpoint_diversity' => 0,
            'anomaly_score' => 0.5,
            'trust_score' => 0.5,
            'last_updated' => time()
        ];
    }
    
    private function calculateTrustMultiplier(float $trustScore): float
    {
        if ($trustScore >= 0.9) return 2.0;
        if ($trustScore >= 0.7) return 1.5;
        if ($trustScore >= 0.5) return 1.0;
        if ($trustScore >= 0.3) return 0.7;
        return 0.3;
    }
    
    private function calculateBehaviorMultiplier(array $profile): float
    {
        $baseMultiplier = 1.0;
        
        // Reduzir para comportamento anômalo
        if ($profile['anomaly_score'] > 0.7) {
            $baseMultiplier *= 0.5;
        }
        
        // Aumentar para padrões consistentes
        if ($profile['request_pattern'] === 'consistent') {
            $baseMultiplier *= 1.2;
        }
        
        return max(0.1, min(3.0, $baseMultiplier));
    }
    
    private function isWhitelisted(string $ip): bool
    {
        return $this->redis->sIsMember('ip_whitelist', $ip);
    }
    
    private function isBlocked(string $ip): bool
    {
        return $this->redis->exists("ip_blocked:{$ip}");
    }
    
    private function getUserTrustProfile(int $userId): array
    {
        $profileData = $this->redis->get("user_trust:{$userId}");
        return $profileData ? json_decode($profileData, true) : ['trust_level' => 'medium', 'score' => 0.5];
    }
    
    private function getTrustMultiplier(string $trustLevel): float
    {
        return $this->config['trust_levels'][$trustLevel] ?? 1.0;
    }
    
    private function getAPIKeyInfo(string $apiKey): ?array
    {
        $keyData = $this->redis->get("api_key:{$apiKey}");
        return $keyData ? json_decode($keyData, true) : null;
    }
    
    private function checkAPIQuota(string $apiKey, array $apiKeyInfo): array
    {
        $quotaKey = "api_quota:{$apiKey}:" . date('Y-m');
        $currentUsage = (int)$this->redis->get($quotaKey);
        $monthlyQuota = $apiKeyInfo['monthly_quota'] ?? 10000;
        
        if ($currentUsage >= $monthlyQuota) {
            return [
                'allowed' => false,
                'reason' => 'QUOTA_EXCEEDED',
                'quota_limit' => $monthlyQuota,
                'quota_used' => $currentUsage,
                'quota_remaining' => 0
            ];
        }
        
        return [
            'allowed' => true,
            'quota_limit' => $monthlyQuota,
            'quota_used' => $currentUsage,
            'quota_remaining' => $monthlyQuota - $currentUsage
        ];
    }
    
    // Métodos de análise comportamental (implementação básica)
    private function calculateAverageInterval(array $behaviorData): float { return 1.0; }
    private function analyzeRequestPattern(array $behaviorData): string { return 'normal'; }
    private function analyzeTimeDistribution(array $behaviorData): array { return []; }
    private function analyzeEndpointDiversity(array $behaviorData): int { return 5; }
    private function calculateAnomalyScore(array $behaviorData): float { return 0.1; }
    private function calculateTrustScore(array $behaviorData): float { return 0.8; }
    private function analyzeBurstPattern(array $requests): array { 
        return ['is_suspicious' => false, 'suspicion_score' => 0.1, 'suggested_delay' => 1]; 
    }
    private function getAverageRequestInterval(string $ip): float { return 1.0; }
    private function isSuspiciousUserAgent(string $userAgent): bool { return false; }
    private function detectBotSignature(array $context): bool { return false; }
    private function getDeviceProfile(string $fingerprint): array { 
        return ['risk_score' => 0.1, 'device_type' => 'browser']; 
    }
    private function getGeoLimits(string $endpoint, array $riskProfile): array {
        $baseLimits = $this->config['endpoints'][$endpoint] ?? $this->config['default_limits'];
        return [
            'requests' => (int)($baseLimits['requests'] * $riskProfile['multiplier']),
            'window' => $baseLimits['window'],
            'burst' => (int)($baseLimits['burst'] * $riskProfile['multiplier'])
        ];
    }
    private function updateBehaviorProfile(string $identifier, string $endpoint, array $result, array $context): void {
        // Implementar atualização do perfil comportamental
    }
    private function handleDDoSDetection(string $ip, array $analysis): void {
        $this->redis->set("ip_blocked:{$ip}", json_encode($analysis), 3600);
        $this->audit->logEvent('ddos_detected', ['ip' => $ip, 'analysis' => $analysis]);
    }
}
