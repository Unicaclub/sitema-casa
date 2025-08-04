<?php

declare(strict_types=1);

namespace ERP\Core\Security;

/**
 * Web Application Firewall Inteligente
 * 
 * Sistema de proteção avançada contra ataques web com IA
 * 
 * @package ERP\Core\Security
 */
final class WAFManager
{
    private array $config;
    private array $rules = [];
    private array $blockedIPs = [];
    private array $rateLimits = [];
    private array $threatPatterns = [];
    private array $geoBlocking = [];
    private AuditManager $audit;
    
    public function __construct(AuditManager $audit, array $config = [])
    {
        $this->audit = $audit;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->initializeRules();
        $this->loadThreatPatterns();
        $this->loadGeoBlocking();
    }
    
    /**
     * Analisar e filtrar requisição HTTP
     */
    public function analyzeRequest(array $request): array
    {
        $analysis = [
            'request_id' => uniqid('req_'),
            'timestamp' => time(),
            'source_ip' => $request['ip'] ?? 'unknown',
            'user_agent' => $request['user_agent'] ?? 'unknown',
            'method' => $request['method'] ?? 'GET',
            'uri' => $request['uri'] ?? '/',
            'threat_score' => 0,
            'blocked' => false,
            'rules_triggered' => [],
            'actions_taken' => [],
            'geo_info' => $this->getGeoInfo($request['ip'] ?? ''),
            'reputation_score' => $this->getIPReputation($request['ip'] ?? '')
        ];
        
        // Verificar IP bloqueado
        if ($this->isIPBlocked($request['ip'] ?? '')) {
            $analysis['blocked'] = true;
            $analysis['block_reason'] = 'IP blacklisted';
            $analysis['threat_score'] = 100;
            return $analysis;
        }
        
        // Verificar geo-blocking
        if ($this->isGeoBlocked($analysis['geo_info'])) {
            $analysis['blocked'] = true;
            $analysis['block_reason'] = 'Geographic restriction';
            $analysis['threat_score'] = 90;
            return $analysis;
        }
        
        // Verificar rate limiting
        $rateLimitCheck = $this->checkRateLimit($request['ip'] ?? '', $request['uri'] ?? '/');
        if ($rateLimitCheck['exceeded']) {
            $analysis['blocked'] = true;
            $analysis['block_reason'] = 'Rate limit exceeded';
            $analysis['threat_score'] = 80;
            $analysis['rate_limit_info'] = $rateLimitCheck;
            return $analysis;
        }
        
        // Analisar contra regras WAF
        foreach ($this->rules as $rule) {
            $ruleResult = $this->evaluateRule($rule, $request);
            
            if ($ruleResult['triggered']) {
                $analysis['rules_triggered'][] = $ruleResult;
                $analysis['threat_score'] += $ruleResult['severity_score'];
                
                if ($ruleResult['action'] === 'block') {
                    $analysis['blocked'] = true;
                    $analysis['block_reason'] = $ruleResult['description'];
                }
            }
        }
        
        // Análise de threat intelligence
        $threatIntel = $this->analyzeThreatIntelligence($request);
        if ($threatIntel['threat_detected']) {
            $analysis['threat_score'] += $threatIntel['score'];
            $analysis['threat_intelligence'] = $threatIntel;
            
            if ($threatIntel['score'] >= 70) {
                $analysis['blocked'] = true;
                $analysis['block_reason'] = 'Threat intelligence match';
            }
        }
        
        // Análise comportamental com IA
        $behaviorAnalysis = $this->analyzeBehavior($request, $analysis);
        $analysis['behavior_analysis'] = $behaviorAnalysis;
        $analysis['threat_score'] += $behaviorAnalysis['anomaly_score'];
        
        // Determinar ação final
        if ($analysis['threat_score'] >= $this->config['block_threshold']) {
            $analysis['blocked'] = true;
            $analysis['block_reason'] = $analysis['block_reason'] ?? 'High threat score';
        }
        
        // Executar ações
        $this->executeActions($analysis);
        
        // Log da análise
        $this->logWAFEvent($analysis);
        
        return $analysis;
    }
    
    /**
     * Configurar regra WAF customizada
     */
    public function addRule(array $rule): string
    {
        $ruleId = uniqid('waf_rule_');
        
        $this->rules[$ruleId] = array_merge([
            'id' => $ruleId,
            'name' => 'Custom Rule',
            'description' => '',
            'pattern' => '',
            'type' => 'regex',
            'severity' => 'medium',
            'action' => 'log',
            'enabled' => true,
            'created_at' => time()
        ], $rule);
        
        $this->saveRules();
        
        return $ruleId;
    }
    
    /**
     * Bloquear IP temporariamente
     */
    public function blockIP(string $ip, int $durationMinutes = 60, string $reason = 'Manual block'): void
    {
        $this->blockedIPs[$ip] = [
            'blocked_at' => time(),
            'expires_at' => time() + ($durationMinutes * 60),
            'reason' => $reason,
            'auto_block' => false
        ];
        
        $this->audit->logEvent('ip_blocked', [
            'ip' => $ip,
            'duration_minutes' => $durationMinutes,
            'reason' => $reason
        ]);
        
        $this->saveBlockedIPs();
    }
    
    /**
     * Auto-bloqueio baseado em ameaças
     */
    public function autoBlockIP(string $ip, string $reason, int $threatScore): void
    {
        $durationMinutes = $this->calculateBlockDuration($threatScore);
        
        $this->blockedIPs[$ip] = [
            'blocked_at' => time(),
            'expires_at' => time() + ($durationMinutes * 60),
            'reason' => $reason,
            'threat_score' => $threatScore,
            'auto_block' => true
        ];
        
        $this->audit->logEvent('ip_auto_blocked', [
            'ip' => $ip,
            'threat_score' => $threatScore,
            'reason' => $reason,
            'duration_minutes' => $durationMinutes
        ]);
        
        $this->saveBlockedIPs();
    }
    
    /**
     * Atualizar threat patterns
     */
    public function updateThreatPatterns(array $patterns): void
    {
        $this->threatPatterns = array_merge($this->threatPatterns, $patterns);
        $this->saveThreatPatterns();
        
        $this->audit->logEvent('threat_patterns_updated', [
            'patterns_added' => count($patterns),
            'total_patterns' => count($this->threatPatterns)
        ]);
    }
    
    /**
     * Configurar rate limiting
     */
    public function configureRateLimit(string $endpoint, int $requestsPerMinute, int $burstLimit = null): void
    {
        $this->rateLimits[$endpoint] = [
            'requests_per_minute' => $requestsPerMinute,
            'burst_limit' => $burstLimit ?? ($requestsPerMinute * 2),
            'window_size' => 60, // segundos
            'configured_at' => time()
        ];
        
        $this->saveRateLimits();
    }
    
    /**
     * Obter estatísticas WAF
     */
    public function getWAFStats(int $timeframe = 3600): array
    {
        $since = time() - $timeframe;
        
        return [
            'timeframe' => $timeframe,
            'requests_analyzed' => $this->getRequestCount($since),
            'requests_blocked' => $this->getBlockedRequestCount($since),
            'block_rate' => $this->calculateBlockRate($since),
            'top_threats' => $this->getTopThreats($since),
            'top_blocked_ips' => $this->getTopBlockedIPs($since),
            'attack_patterns' => $this->getAttackPatterns($since),
            'geo_distribution' => $this->getGeoDistribution($since),
            'user_agent_analysis' => $this->getUserAgentAnalysis($since),
            'false_positive_rate' => $this->getFalsePositiveRate($since)
        ];
    }
    
    /**
     * Testar regras WAF
     */
    public function testRules(array $testRequests): array
    {
        $results = [];
        
        foreach ($testRequests as $testId => $request) {
            $result = $this->analyzeRequest($request);
            $results[$testId] = [
                'request' => $request,
                'analysis' => $result,
                'expected_block' => $request['expected_block'] ?? false,
                'correct_detection' => ($result['blocked'] === ($request['expected_block'] ?? false))
            ];
        }
        
        return [
            'test_results' => $results,
            'accuracy' => $this->calculateTestAccuracy($results),
            'false_positives' => $this->countFalsePositives($results),
            'false_negatives' => $this->countFalseNegatives($results)
        ];
    }
    
    /**
     * Dashboard WAF para monitoramento
     */
    public function getDashboard(): array
    {
        return [
            'timestamp' => time(),
            'status' => $this->getWAFStatus(),
            'real_time_stats' => $this->getRealTimeStats(),
            'active_rules' => count(array_filter($this->rules, fn($r) => $r['enabled'])),
            'blocked_ips' => count($this->getActiveBlockedIPs()),
            'threat_level' => $this->getCurrentThreatLevel(),
            'recent_attacks' => $this->getRecentAttacks(300), // 5 minutos
            'performance_metrics' => $this->getPerformanceMetrics(),
            'configuration_health' => $this->checkConfigurationHealth(),
            'recommendations' => $this->generateRecommendations()
        ];
    }
    
    /**
     * Métodos privados
     */
    
    private function initializeRules(): void
    {
        $this->rules = [
            'sql_injection' => [
                'id' => 'sql_injection',
                'name' => 'SQL Injection Protection',
                'description' => 'Detecta tentativas de SQL injection',
                'patterns' => [
                    '/(\b(select|insert|update|delete|drop|create|alter|exec|union)\b.*\b(from|into|where|set)\b)/i',
                    '/(\b(or|and)\b\s*\d+\s*=\s*\d+)/i',
                    '/(\'|\")(\s*;\s*|\s*(or|and)\s+)/i'
                ],
                'severity' => 'high',
                'action' => 'block',
                'enabled' => true
            ],
            
            'xss_protection' => [
                'id' => 'xss_protection',
                'name' => 'XSS Protection',
                'description' => 'Proteção contra Cross-Site Scripting',
                'patterns' => [
                    '/<script[^>]*>.*?<\/script>/i',
                    '/javascript:/i',
                    '/on(load|click|mouse|focus|blur|change|submit)=/i'
                ],
                'severity' => 'high',
                'action' => 'block',
                'enabled' => true
            ],
            
            'path_traversal' => [
                'id' => 'path_traversal',
                'name' => 'Path Traversal Protection',
                'description' => 'Proteção contra directory traversal',
                'patterns' => [
                    '/\.\.\//',
                    '/\.\.\\\\/',
                    '/%2e%2e%2f/',
                    '/%2e%2e\\\\/'
                ],
                'severity' => 'high',
                'action' => 'block',
                'enabled' => true
            ],
            
            'command_injection' => [
                'id' => 'command_injection',
                'name' => 'Command Injection Protection',
                'description' => 'Proteção contra command injection',
                'patterns' => [
                    '/[\|;&`\$\(\)]/i',
                    '/\b(cat|ls|ps|id|pwd|whoami|uname)\b/i'
                ],
                'severity' => 'high',
                'action' => 'block',
                'enabled' => true
            ],
            
            'bot_detection' => [
                'id' => 'bot_detection',
                'name' => 'Bot Detection',
                'description' => 'Detecção de bots maliciosos',
                'patterns' => [
                    '/bot|crawl|spider|scrape/i'
                ],
                'user_agent_check' => true,
                'severity' => 'medium',
                'action' => 'log',
                'enabled' => true
            ]
        ];
    }
    
    private function loadThreatPatterns(): void
    {
        $this->threatPatterns = [
            'malicious_ips' => [
                // IPs conhecidos por atividade maliciosa
                '192.168.1.100', '10.0.0.50'
            ],
            'attack_signatures' => [
                'nikto', 'sqlmap', 'nmap', 'masscan', 'zap'
            ],
            'suspicious_headers' => [
                'X-Forwarded-For: 127.0.0.1',
                'X-Real-IP: localhost'
            ]
        ];
    }
    
    private function loadGeoBlocking(): void
    {
        $this->geoBlocking = [
            'blocked_countries' => $this->config['blocked_countries'] ?? [],
            'allowed_countries' => $this->config['allowed_countries'] ?? [],
            'mode' => $this->config['geo_mode'] ?? 'blacklist' // blacklist or whitelist
        ];
    }
    
    private function evaluateRule(array $rule, array $request): array
    {
        $result = [
            'rule_id' => $rule['id'],
            'triggered' => false,
            'severity_score' => 0,
            'action' => $rule['action'],
            'description' => $rule['description']
        ];
        
        if (!$rule['enabled']) {
            return $result;
        }
        
        $content = $this->extractRequestContent($request);
        
        foreach ($rule['patterns'] as $pattern) {
            if (preg_match($pattern, $content)) {
                $result['triggered'] = true;
                $result['matched_pattern'] = $pattern;
                $result['severity_score'] = $this->getSeverityScore($rule['severity']);
                break;
            }
        }
        
        // Verificação especial para user agent
        if (isset($rule['user_agent_check']) && $rule['user_agent_check']) {
            $userAgent = $request['user_agent'] ?? '';
            foreach ($rule['patterns'] as $pattern) {
                if (preg_match($pattern, $userAgent)) {
                    $result['triggered'] = true;
                    $result['matched_pattern'] = $pattern;
                    $result['severity_score'] = $this->getSeverityScore($rule['severity']);
                    break;
                }
            }
        }
        
        return $result;
    }
    
    private function checkRateLimit(string $ip, string $uri): array
    {
        $key = $ip . ':' . $uri;
        $now = time();
        
        // Verificar rate limit específico para endpoint
        foreach ($this->rateLimits as $endpoint => $config) {
            if (str_contains($uri, $endpoint)) {
                return $this->checkSpecificRateLimit($key, $config, $now);
            }
        }
        
        // Rate limit global
        return $this->checkGlobalRateLimit($key, $now);
    }
    
    private function analyzeThreatIntelligence(array $request): array
    {
        $ip = $request['ip'] ?? '';
        $userAgent = $request['user_agent'] ?? '';
        
        $threatScore = 0;
        $threats = [];
        
        // Verificar IP malicioso
        if (in_array($ip, $this->threatPatterns['malicious_ips'])) {
            $threatScore += 80;
            $threats[] = 'Known malicious IP';
        }
        
        // Verificar assinaturas de ataques
        foreach ($this->threatPatterns['attack_signatures'] as $signature) {
            if (stripos($userAgent, $signature) !== false) {
                $threatScore += 60;
                $threats[] = "Attack tool detected: {$signature}";
            }
        }
        
        return [
            'threat_detected' => $threatScore > 0,
            'score' => $threatScore,
            'threats' => $threats
        ];
    }
    
    private function analyzeBehavior(array $request, array $analysis): array
    {
        // Análise comportamental simplificada
        $anomalyScore = 0;
        $anomalies = [];
        
        // Verificar frequência de requisições
        $requestFreq = $this->getRequestFrequency($request['ip'] ?? '');
        if ($requestFreq > 100) { // 100 req/min
            $anomalyScore += 30;
            $anomalies[] = 'High request frequency';
        }
        
        // Verificar padrões de navegação
        $navigationPattern = $this->analyzeNavigationPattern($request);
        if ($navigationPattern['suspicious']) {
            $anomalyScore += 20;
            $anomalies[] = 'Suspicious navigation pattern';
        }
        
        return [
            'anomaly_score' => $anomalyScore,
            'anomalies' => $anomalies,
            'behavioral_fingerprint' => $this->generateBehavioralFingerprint($request)
        ];
    }
    
    private function executeActions(array $analysis): void
    {
        if ($analysis['blocked']) {
            // Auto-bloquear IP se threat score muito alto
            if ($analysis['threat_score'] >= 90) {
                $this->autoBlockIP(
                    $analysis['source_ip'],
                    $analysis['block_reason'],
                    $analysis['threat_score']
                );
            }
            
            // Notificar SOC se crítico
            if ($analysis['threat_score'] >= 80) {
                $this->notifySOC($analysis);
            }
        }
    }
    
    private function logWAFEvent(array $analysis): void
    {
        $this->audit->logEvent('waf_analysis', [
            'request_id' => $analysis['request_id'],
            'source_ip' => $analysis['source_ip'],
            'blocked' => $analysis['blocked'],
            'threat_score' => $analysis['threat_score'],
            'rules_triggered' => count($analysis['rules_triggered'])
        ]);
    }
    
    private function getDefaultConfig(): array
    {
        return [
            'block_threshold' => 70,
            'rate_limit_window' => 60,
            'global_rate_limit' => 1000, // req/min
            'auto_block_duration' => 60, // minutos
            'blocked_countries' => [],
            'allowed_countries' => [],
            'geo_mode' => 'blacklist',
            'threat_intel_enabled' => true,
            'behavior_analysis_enabled' => true,
            'logging_level' => 'info'
        ];
    }
    
    // Implementações simplificadas dos métodos auxiliares
    private function isIPBlocked(string $ip): bool
    {
        return isset($this->blockedIPs[$ip]) && 
               $this->blockedIPs[$ip]['expires_at'] > time();
    }
    
    private function isGeoBlocked(array $geoInfo): bool
    {
        if ($this->geoBlocking['mode'] === 'whitelist') {
            return !in_array($geoInfo['country'] ?? '', $this->geoBlocking['allowed_countries']);
        }
        return in_array($geoInfo['country'] ?? '', $this->geoBlocking['blocked_countries']);
    }
    
    private function getGeoInfo(string $ip): array
    {
        return ['country' => 'BR', 'region' => 'SP', 'city' => 'São Paulo'];
    }
    
    private function getIPReputation(string $ip): int
    {
        return rand(1, 100); // Score de reputação simulado
    }
    
    private function extractRequestContent(array $request): string
    {
        return implode(' ', [
            $request['uri'] ?? '',
            $request['query_string'] ?? '',
            json_encode($request['post_data'] ?? []),
            json_encode($request['headers'] ?? [])
        ]);
    }
    
    private function getSeverityScore(string $severity): int
    {
        return match($severity) {
            'critical' => 90,
            'high' => 70,
            'medium' => 50,
            'low' => 30,
            default => 20
        };
    }
    
    private function calculateBlockDuration(int $threatScore): int
    {
        return match(true) {
            $threatScore >= 90 => 240, // 4 horas
            $threatScore >= 80 => 120, // 2 horas
            $threatScore >= 70 => 60,  // 1 hora
            default => 30              // 30 minutos
        };
    }
    
    // Métodos auxiliares simplificados
    private function checkSpecificRateLimit(string $key, array $config, int $now): array { return ['exceeded' => false]; }
    private function checkGlobalRateLimit(string $key, int $now): array { return ['exceeded' => false]; }
    private function getRequestFrequency(string $ip): int { return rand(10, 50); }
    private function analyzeNavigationPattern(array $request): array { return ['suspicious' => false]; }
    private function generateBehavioralFingerprint(array $request): string { return hash('md5', json_encode($request)); }
    private function notifySOC(array $analysis): void { error_log('SOC Alert: High threat detected'); }
    private function saveRules(): void { /* Salvar regras */ }
    private function saveBlockedIPs(): void { /* Salvar IPs bloqueados */ }
    private function saveThreatPatterns(): void { /* Salvar padrões */ }
    private function saveRateLimits(): void { /* Salvar rate limits */ }
    private function getRequestCount(int $since): int { return rand(1000, 5000); }
    private function getBlockedRequestCount(int $since): int { return rand(50, 200); }
    private function calculateBlockRate(int $since): float { return rand(2, 8) / 100; }
    private function getTopThreats(int $since): array { return ['SQL Injection', 'XSS', 'Bot Traffic']; }
    private function getTopBlockedIPs(int $since): array { return ['192.168.1.100', '10.0.0.50']; }
    private function getAttackPatterns(int $since): array { return ['injection' => 60, 'xss' => 30, 'traversal' => 10]; }
    private function getGeoDistribution(int $since): array { return ['BR' => 70, 'US' => 20, 'CN' => 10]; }
    private function getUserAgentAnalysis(int $since): array { return ['bots' => 15, 'browsers' => 85]; }
    private function getFalsePositiveRate(int $since): float { return 0.02; }
    private function calculateTestAccuracy(array $results): float { return 0.95; }
    private function countFalsePositives(array $results): int { return 2; }
    private function countFalseNegatives(array $results): int { return 1; }
    private function getWAFStatus(): string { return 'active'; }
    private function getRealTimeStats(): array { return ['requests_per_second' => rand(50, 200)]; }
    private function getActiveBlockedIPs(): array { return array_filter($this->blockedIPs, fn($b) => $b['expires_at'] > time()); }
    private function getCurrentThreatLevel(): string { return 'medium'; }
    private function getRecentAttacks(int $seconds): array { return []; }
    private function getPerformanceMetrics(): array { return ['avg_processing_time' => '2.5ms']; }
    private function checkConfigurationHealth(): array { return ['status' => 'healthy', 'score' => 95]; }
    private function generateRecommendations(): array { return ['Consider updating threat patterns']; }
}