<?php

declare(strict_types=1);

namespace ERP\Core\Security;

use ERP\Core\Security\Integrations\VirusTotalClient;

/**
 * Sistema de Threat Intelligence Avançado
 * 
 * Coleta, analisa e correlaciona inteligência de ameaças de múltiplas fontes
 * 
 * @package ERP\Core\Security
 */
final class ThreatIntelligenceManager
{
    private array $config;
    private array $threatFeeds = [];
    private array $iocs = []; // Indicators of Compromise
    private array $ttps = []; // Tactics, Techniques & Procedures
    private array $threatActors = [];
    private array $campaigns = [];
    private array $contextualData = [];
    private AuditManager $audit;
    private AIMonitoringManager $aiMonitoring;
    private ?VirusTotalClient $virusTotalClient = null;
    
    public function __construct(
        AuditManager $audit,
        AIMonitoringManager $aiMonitoring,
        array $config = []
    ) {
        $this->audit = $audit;
        $this->aiMonitoring = $aiMonitoring;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        
        // Initialize VirusTotal client if API key is provided
        if (!empty($this->config['virustotal_api_key'])) {
            $this->virusTotalClient = new VirusTotalClient($this->config['virustotal_api_key']);
        }
        
        $this->initializeThreatFeeds();
        $this->loadIOCs();
        $this->loadTTPs();
        $this->startThreatIntelligenceCollection();
    }
    
    /**
     * Coletar inteligência de ameaças de múltiplas fontes
     */
    public function collectThreatIntelligence(): array
    {
        $collectionId = uniqid('threat_intel_');
        
        $collection = [
            'collection_id' => $collectionId,
            'started_at' => time(),
            'sources' => [],
            'iocs_collected' => 0,
            'campaigns_identified' => 0,
            'threat_actors_updated' => 0,
            'ttps_discovered' => 0
        ];
        
        // Coletar de cada feed de threat intelligence
        foreach ($this->threatFeeds as $feedName => $feedConfig) {
            if (!$feedConfig['enabled']) {
                continue;
            }
            
            try {
                // Use VirusTotal integration for real data
                if ($feedName === 'virustotal' && $this->virusTotalClient) {
                    $feedData = $this->collectFromVirusTotal();
                } else {
                    $feedData = $this->collectFromFeed($feedName, $feedConfig);
                }
                $collection['sources'][$feedName] = $feedData;
                
                // Processar dados coletados
                $processedData = $this->processFeedData($feedData, $feedName);
                
                // Atualizar base de conhecimento
                $this->updateKnowledgeBase($processedData);
                
                $collection['iocs_collected'] += count($processedData['iocs'] ?? []);
                $collection['campaigns_identified'] += count($processedData['campaigns'] ?? []);
                $collection['threat_actors_updated'] += count($processedData['actors'] ?? []);
                $collection['ttps_discovered'] += count($processedData['ttps'] ?? []);
                
            } catch (\Exception $e) {
                $collection['sources'][$feedName] = [
                    'status' => 'error',
                    'error' => $e->getMessage()
                ];
            }
        }
        
        // Análise de correlação
        $collection['correlation_analysis'] = $this->performThreatCorrelation($collection);
        
        // Contextualização com dados internos
        $collection['contextualization'] = $this->contextualizeThreatData($collection);
        
        $collection['completed_at'] = time();
        
        // Gerar alertas baseados em nova inteligência
        $this->generateThreatIntelligenceAlerts($collection);
        
        $this->audit->logEvent('threat_intelligence_collected', $collection);
        
        return $collection;
    }
    
    /**
     * Analisar IoCs contra infraestrutura interna
     */
    public function analyzeIOCs(array $indicators = null): array
    {
        $analysisId = uniqid('ioc_analysis_');
        $indicators = $indicators ?? $this->iocs;
        
        $analysis = [
            'analysis_id' => $analysisId,
            'timestamp' => time(),
            'indicators_analyzed' => count($indicators),
            'matches_found' => [],
            'risk_assessment' => [],
            'recommended_actions' => []
        ];
        
        foreach ($indicators as $iocId => $ioc) {
            // Verificar IoC contra logs de sistema
            $systemMatches = $this->checkIOCAgainstSystemLogs($ioc);
            
            // Verificar contra tráfego de rede
            $networkMatches = $this->checkIOCAgainstNetworkTraffic($ioc);
            
            // Verificar contra dados de DNS
            $dnsMatches = $this->checkIOCAgainstDNSLogs($ioc);
            
            // Verificar contra dados de endpoint
            $endpointMatches = $this->checkIOCAgainstEndpoints($ioc);
            
            $totalMatches = array_merge($systemMatches, $networkMatches, $dnsMatches, $endpointMatches);
            
            if (!empty($totalMatches)) {
                $analysis['matches_found'][$iocId] = [
                    'ioc' => $ioc,
                    'matches' => $totalMatches,
                    'match_count' => count($totalMatches),
                    'severity' => $this->calculateIOCSeverity($ioc, $totalMatches),
                    'confidence' => $this->calculateIOCConfidence($ioc, $totalMatches)
                ];
            }
        }
        
        // Avaliação de risco
        $analysis['risk_assessment'] = $this->assessIOCRisk($analysis['matches_found']);
        
        // Ações recomendadas
        $analysis['recommended_actions'] = $this->recommendIOCActions($analysis);
        
        // Timeline de atividade maliciosa
        $analysis['malicious_activity_timeline'] = $this->createMaliciousActivityTimeline($analysis);
        
        return $analysis;
    }
    
    /**
     * Mapear TTPs para MITRE ATT&CK Framework
     */
    public function mapTTPsToMITRE(array $observedTTPs = null): array
    {
        $mappingId = uniqid('mitre_mapping_');
        $ttps = $observedTTPs ?? $this->ttps;
        
        $mapping = [
            'mapping_id' => $mappingId,
            'timestamp' => time(),
            'ttps_analyzed' => count($ttps),
            'mitre_mappings' => [],
            'attack_phases' => [],
            'technique_coverage' => []
        ];
        
        foreach ($ttps as $ttpId => $ttp) {
            $mitreMapping = $this->mapToMITREFramework($ttp);
            
            if ($mitreMapping) {
                $mapping['mitre_mappings'][$ttpId] = $mitreMapping;
                
                // Categorizar por fase de ataque
                $phase = $mitreMapping['tactic'] ?? 'unknown';
                if (!isset($mapping['attack_phases'][$phase])) {
                    $mapping['attack_phases'][$phase] = [];
                }
                $mapping['attack_phases'][$phase][] = $mitreMapping;
                
                // Análise de cobertura
                $technique = $mitreMapping['technique_id'] ?? 'unknown';
                $mapping['technique_coverage'][$technique] = ($mapping['technique_coverage'][$technique] ?? 0) + 1;
            }
        }
        
        // Análise de gaps de detecção
        $mapping['detection_gaps'] = $this->identifyDetectionGaps($mapping);
        
        // Recomendações de defesa
        $mapping['defense_recommendations'] = $this->generateDefenseRecommendations($mapping);
        
        return $mapping;
    }
    
    /**
     * Rastrear campanhas de threat actors
     */
    public function trackThreatCampaigns(): array
    {
        $trackingId = uniqid('campaign_tracking_');
        
        $tracking = [
            'tracking_id' => $trackingId,
            'timestamp' => time(),
            'active_campaigns' => [],
            'emerging_campaigns' => [],
            'threat_actor_activity' => [],
            'campaign_analysis' => []
        ];
        
        // Analisar campanhas ativas
        foreach ($this->campaigns as $campaignId => $campaign) {
            $campaignAnalysis = $this->analyzeCampaign($campaign);
            
            if ($campaignAnalysis['status'] === 'active') {
                $tracking['active_campaigns'][$campaignId] = $campaignAnalysis;
            } elseif ($campaignAnalysis['status'] === 'emerging') {
                $tracking['emerging_campaigns'][$campaignId] = $campaignAnalysis;
            }
        }
        
        // Atividade de threat actors
        foreach ($this->threatActors as $actorId => $actor) {
            $actorActivity = $this->analyzeActorActivity($actor);
            $tracking['threat_actor_activity'][$actorId] = $actorActivity;
        }
        
        // Correlação de campanhas
        $tracking['campaign_correlation'] = $this->correlateCampaigns($tracking);
        
        // Predição de próximas ações
        $tracking['activity_predictions'] = $this->predictCampaignActivity($tracking);
        
        return $tracking;
    }
    
    /**
     * Enriquecer contexto de ameaças
     */
    public function enrichThreatContext(array $threatData): array
    {
        $enrichmentId = uniqid('threat_enrichment_');
        
        $enrichment = [
            'enrichment_id' => $enrichmentId,
            'timestamp' => time(),
            'original_threat' => $threatData,
            'enriched_data' => [],
            'context_sources' => [],
            'confidence_score' => 0
        ];
        
        // Enriquecimento geográfico
        $geoEnrichment = $this->performGeographicEnrichment($threatData);
        $enrichment['enriched_data']['geographic'] = $geoEnrichment;
        $enrichment['context_sources'][] = 'geographic_intelligence';
        
        // Enriquecimento temporal
        $temporalEnrichment = $this->performTemporalEnrichment($threatData);
        $enrichment['enriched_data']['temporal'] = $temporalEnrichment;
        $enrichment['context_sources'][] = 'temporal_analysis';
        
        // Enriquecimento de infraestrutura
        $infraEnrichment = $this->performInfrastructureEnrichment($threatData);
        $enrichment['enriched_data']['infrastructure'] = $infraEnrichment;
        $enrichment['context_sources'][] = 'infrastructure_intelligence';
        
        // Enriquecimento de malware
        $malwareEnrichment = $this->performMalwareEnrichment($threatData);
        $enrichment['enriched_data']['malware'] = $malwareEnrichment;
        $enrichment['context_sources'][] = 'malware_intelligence';
        
        // Enriquecimento de vulnerabilidades
        $vulnEnrichment = $this->performVulnerabilityEnrichment($threatData);
        $enrichment['enriched_data']['vulnerabilities'] = $vulnEnrichment;
        $enrichment['context_sources'][] = 'vulnerability_intelligence';
        
        // Calcular score de confiança
        $enrichment['confidence_score'] = $this->calculateEnrichmentConfidence($enrichment);
        
        // Resumo executivo
        $enrichment['executive_summary'] = $this->generateThreatSummary($enrichment);
        
        return $enrichment;
    }
    
    /**
     * Dashboard de Threat Intelligence
     */
    public function getThreatIntelligenceDashboard(): array
    {
        return [
            'timestamp' => time(),
            'collection_status' => [
                'active_feeds' => count(array_filter($this->threatFeeds, fn($f) => $f['enabled'])),
                'last_collection' => $this->getLastCollectionTime(),
                'collection_health' => $this->assessCollectionHealth(),
                'data_freshness' => $this->assessDataFreshness()
            ],
            'intelligence_metrics' => [
                'total_iocs' => count($this->iocs),
                'active_campaigns' => $this->countActiveCampaigns(),
                'tracked_actors' => count($this->threatActors),
                'known_ttps' => count($this->ttps),
                'ioc_matches_today' => $this->getIOCMatchesToday(),
                'new_threats_detected' => $this->getNewThreatsDetected()
            ],
            'threat_landscape' => [
                'top_threat_actors' => $this->getTopThreatActors(),
                'trending_campaigns' => $this->getTrendingCampaigns(),
                'emerging_ttps' => $this->getEmergingTTPs(),
                'geographic_threats' => $this->getGeographicThreats(),
                'industry_targeting' => $this->getIndustryTargeting()
            ],
            'actionable_intelligence' => [
                'high_confidence_iocs' => $this->getHighConfidenceIOCs(),
                'immediate_threats' => $this->getImmediateThreats(),
                'preventive_measures' => $this->getPreventiveMeasures(),
                'hunting_opportunities' => $this->getHuntingOpportunities()
            ],
            'mitre_coverage' => [
                'tactics_covered' => $this->getMITRETacticsCoverage(),
                'techniques_observed' => $this->getMITRETechniquesObserved(),
                'detection_gaps' => $this->getDetectionGaps(),
                'coverage_score' => $this->calculateMITRECoverageScore()
            ]
        ];
    }
    
    /**
     * Gerar relatório de threat intelligence
     */
    public function generateThreatReport(string $reportType = 'weekly'): array
    {
        $reportId = uniqid('threat_report_');
        
        $report = [
            'report_id' => $reportId,
            'report_type' => $reportType,
            'generated_at' => time(),
            'period' => $this->getReportPeriod($reportType),
            'executive_summary' => [],
            'threat_landscape' => [],
            'technical_analysis' => [],
            'recommendations' => []
        ];
        
        // Resumo executivo
        $report['executive_summary'] = [
            'key_findings' => $this->getKeyFindings($reportType),
            'threat_level_assessment' => $this->assessThreatLevel(),
            'business_impact' => $this->assessBusinessImpact(),
            'priority_actions' => $this->getPriorityActions()
        ];
        
        // Análise do cenário de ameaças
        $report['threat_landscape'] = [
            'new_threats' => $this->getNewThreats($reportType),
            'trending_attacks' => $this->getTrendingAttacks($reportType),
            'actor_activity' => $this->getActorActivity($reportType),
            'campaign_updates' => $this->getCampaignUpdates($reportType)
        ];
        
        // Análise técnica
        $report['technical_analysis'] = [
            'ioc_analysis' => $this->getIOCAnalysis($reportType),
            'ttp_analysis' => $this->getTTPAnalysis($reportType),
            'vulnerability_intelligence' => $this->getVulnerabilityIntelligence($reportType),
            'infrastructure_analysis' => $this->getInfrastructureAnalysis($reportType)
        ];
        
        // Recomendações
        $report['recommendations'] = [
            'defensive_measures' => $this->getDefensiveMeasures(),
            'detection_improvements' => $this->getDetectionImprovements(),
            'threat_hunting_focus' => $this->getThreatHuntingFocus(),
            'training_needs' => $this->getTrainingNeeds()
        ];
        
        return $report;
    }
    
    /**
     * Métodos privados
     */
    
    private function initializeThreatFeeds(): void
    {
        $this->threatFeeds = [
            'misp' => [
                'name' => 'MISP Threat Intelligence',
                'type' => 'misp',
                'url' => $this->config['misp_url'] ?? '',
                'api_key' => $this->config['misp_api_key'] ?? '',
                'enabled' => true,
                'priority' => 'high',
                'update_frequency' => 3600 // 1 hora
            ],
            'otx' => [
                'name' => 'AlienVault OTX',
                'type' => 'otx',
                'url' => 'https://otx.alienvault.com/api/v1/',
                'api_key' => $this->config['otx_api_key'] ?? '',
                'enabled' => true,
                'priority' => 'high',
                'update_frequency' => 1800 // 30 minutos
            ],
            'virustotal' => [
                'name' => 'VirusTotal Intelligence',
                'type' => 'virustotal',
                'url' => 'https://www.virustotal.com/vtapi/v2/',
                'api_key' => $this->config['vt_api_key'] ?? '',
                'enabled' => true,
                'priority' => 'medium',
                'update_frequency' => 3600
            ],
            'crowdstrike' => [
                'name' => 'CrowdStrike Falcon Intelligence',
                'type' => 'crowdstrike',
                'url' => 'https://api.crowdstrike.com/',
                'api_key' => $this->config['cs_api_key'] ?? '',
                'enabled' => false,
                'priority' => 'high',
                'update_frequency' => 900 // 15 minutos
            ],
            'recorded_future' => [
                'name' => 'Recorded Future',
                'type' => 'recorded_future',
                'url' => 'https://api.recordedfuture.com/',
                'api_key' => $this->config['rf_api_key'] ?? '',
                'enabled' => false,
                'priority' => 'high',
                'update_frequency' => 1800
            ]
        ];
    }
    
    private function loadIOCs(): void
    {
        $this->iocs = [
            'malicious_ips' => [],
            'malicious_domains' => [],
            'malicious_urls' => [],
            'file_hashes' => [],
            'email_indicators' => [],
            'certificate_hashes' => []
        ];
    }
    
    private function loadTTPs(): void
    {
        $this->ttps = [
            // Carregar TTPs conhecidas
        ];
    }
    
    private function startThreatIntelligenceCollection(): void
    {
        $this->audit->logEvent('threat_intelligence_started', [
            'feeds_configured' => count($this->threatFeeds),
            'enabled_feeds' => count(array_filter($this->threatFeeds, fn($f) => $f['enabled']))
        ]);
    }
    
    private function collectFromFeed(string $feedName, array $feedConfig): array
    {
        // Simular coleta de feed de threat intelligence
        return [
            'feed_name' => $feedName,
            'status' => 'success',
            'items_collected' => rand(50, 200),
            'collection_time' => time(),
            'data_types' => ['iocs', 'campaigns', 'actors', 'ttps']
        ];
    }
    
    private function processFeedData(array $feedData, string $feedName): array
    {
        return [
            'iocs' => $this->extractIOCs($feedData),
            'campaigns' => $this->extractCampaigns($feedData),
            'actors' => $this->extractActors($feedData),
            'ttps' => $this->extractTTPs($feedData)
        ];
    }
    
    private function updateKnowledgeBase(array $processedData): void
    {
        // Atualizar base de conhecimento com novos dados
        foreach ($processedData as $dataType => $data) {
            switch ($dataType) {
                case 'iocs':
                    $this->iocs = array_merge($this->iocs, $data);
                    break;
                case 'campaigns':
                    $this->campaigns = array_merge($this->campaigns, $data);
                    break;
                case 'actors':
                    $this->threatActors = array_merge($this->threatActors, $data);
                    break;
                case 'ttps':
                    $this->ttps = array_merge($this->ttps, $data);
                    break;
            }
        }
    }
    
    private function getDefaultConfig(): array
    {
        return [
            'collection_interval' => 1800, // 30 minutos
            'ioc_retention_days' => 365,
            'confidence_threshold' => 0.7,
            'auto_blocking' => false,
            'threat_feeds' => [],
            'mitre_mapping' => true,
            'contextual_enrichment' => true,
            'report_generation' => true
        ];
    }
    
    // Implementações simplificadas dos métodos auxiliares
    private function performThreatCorrelation(array $collection): array
    {
        return ['correlated_threats' => rand(5, 15), 'correlation_confidence' => rand(70, 95)];
    }
    
    private function contextualizeThreatData(array $collection): array
    {
        return ['context_added' => true, 'internal_relevance' => rand(60, 90)];
    }
    
    private function generateThreatIntelligenceAlerts(array $collection): void
    {
        if ($collection['iocs_collected'] > 100) {
            $this->audit->logEvent('high_volume_threat_intel', $collection);
        }
    }
    
    // Métodos de análise de IoCs simplificados
    private function checkIOCAgainstSystemLogs(array $ioc): array { return []; }
    private function checkIOCAgainstNetworkTraffic(array $ioc): array { return []; }
    private function checkIOCAgainstDNSLogs(array $ioc): array { return []; }
    private function checkIOCAgainstEndpoints(array $ioc): array { return []; }
    private function calculateIOCSeverity(array $ioc, array $matches): string { return ['low', 'medium', 'high', 'critical'][rand(0, 3)]; }
    private function calculateIOCConfidence(array $ioc, array $matches): float { return rand(70, 95) / 100; }
    private function assessIOCRisk(array $matches): array { return ['overall_risk' => 'medium']; }
    private function recommendIOCActions(array $analysis): array { return ['Monitor closely', 'Consider blocking']; }
    private function createMaliciousActivityTimeline(array $analysis): array { return ['timeline' => 'created']; }
    
    // Métodos MITRE simplificados
    private function mapToMITREFramework(array $ttp): ?array
    {
        return [
            'technique_id' => 'T' . rand(1000, 1999),
            'technique_name' => 'Sample Technique',
            'tactic' => ['reconnaissance', 'initial-access', 'execution'][rand(0, 2)],
            'confidence' => rand(70, 95)
        ];
    }
    private function identifyDetectionGaps(array $mapping): array { return ['gaps' => 'identified']; }
    private function generateDefenseRecommendations(array $mapping): array { return ['Improve detection for T1059']; }
    
    // Métodos de campanha simplificados
    private function analyzeCampaign(array $campaign): array
    {
        return [
            'status' => ['active', 'dormant', 'emerging'][rand(0, 2)],
            'activity_level' => rand(1, 10),
            'last_activity' => time() - rand(3600, 86400)
        ];
    }
    private function analyzeActorActivity(array $actor): array { return ['activity' => 'analyzed']; }
    private function correlateCampaigns(array $tracking): array { return ['correlations' => 'found']; }
    private function predictCampaignActivity(array $tracking): array { return ['predictions' => 'generated']; }
    
    // Métodos de enriquecimento simplificados
    private function performGeographicEnrichment(array $threat): array { return ['country' => 'CN', 'region' => 'Asia']; }
    private function performTemporalEnrichment(array $threat): array { return ['time_pattern' => 'business_hours']; }
    private function performInfrastructureEnrichment(array $threat): array { return ['hosting' => 'cloud_provider']; }
    private function performMalwareEnrichment(array $threat): array { return ['malware_family' => 'ransomware']; }
    private function performVulnerabilityEnrichment(array $threat): array { return ['exploited_cves' => ['CVE-2023-1234']]; }
    private function calculateEnrichmentConfidence(array $enrichment): float { return rand(75, 95) / 100; }
    private function generateThreatSummary(array $enrichment): string { return 'Threat analysis completed with high confidence'; }
    
    // Métodos de extração simplificados
    private function extractIOCs(array $data): array { return ['sample_ioc' => ['type' => 'ip', 'value' => '192.168.1.100']]; }
    private function extractCampaigns(array $data): array { return ['sample_campaign' => ['name' => 'APT28 Campaign']]; }
    private function extractActors(array $data): array { return ['apt28' => ['name' => 'APT28', 'origin' => 'RU']]; }
    private function extractTTPs(array $data): array { return ['ttp1' => ['technique' => 'spearphishing']]; }
    
    // Métodos de dashboard simplificados
    private function getLastCollectionTime(): int { return time() - 1800; }
    private function assessCollectionHealth(): string { return 'healthy'; }
    private function assessDataFreshness(): string { return 'fresh'; }
    private function countActiveCampaigns(): int { return rand(10, 25); }
    private function getIOCMatchesToday(): int { return rand(5, 20); }
    private function getNewThreatsDetected(): int { return rand(2, 8); }
    private function getTopThreatActors(): array { return ['APT28', 'APT29', 'Lazarus']; }
    private function getTrendingCampaigns(): array { return ['Campaign A', 'Campaign B']; }
    private function getEmergingTTPs(): array { return ['New phishing technique']; }
    private function getGeographicThreats(): array { return ['CN' => 40, 'RU' => 30, 'KP' => 20]; }
    private function getIndustryTargeting(): array { return ['Finance' => 35, 'Healthcare' => 25, 'Government' => 40]; }
    private function getHighConfidenceIOCs(): array { return ['192.168.1.100', 'malicious.example.com']; }
    private function getImmediateThreats(): array { return ['Active APT campaign targeting our industry']; }
    private function getPreventiveMeasures(): array { return ['Update signatures', 'Block IoCs']; }
    private function getHuntingOpportunities(): array { return ['Search for APT28 TTPs']; }
    private function getMITRETacticsCoverage(): array { return ['initial-access' => 80, 'execution' => 70]; }
    private function getMITRETechniquesObserved(): array { return ['T1059', 'T1055', 'T1027']; }
    private function getDetectionGaps(): array { return ['T1078' => 'Insufficient coverage']; }
    private function calculateMITRECoverageScore(): int { return rand(70, 85); }
    
    // Métodos de relatório simplificados
    private function getReportPeriod(string $type): array
    {
        $periods = [
            'daily' => 1,
            'weekly' => 7,
            'monthly' => 30
        ];
        $days = $periods[$type] ?? 7;
        return [
            'start' => time() - ($days * 86400),
            'end' => time(),
            'days' => $days
        ];
    }
    private function getKeyFindings(string $type): array { return ['Key finding 1', 'Key finding 2']; }
    private function assessThreatLevel(): string { return 'elevated'; }
    private function assessBusinessImpact(): string { return 'medium'; }
    private function getPriorityActions(): array { return ['Action 1', 'Action 2']; }
    private function getNewThreats(string $type): array { return ['New threat 1']; }
    private function getTrendingAttacks(string $type): array { return ['Phishing increase']; }
    private function getActorActivity(string $type): array { return ['APT28 active']; }
    private function getCampaignUpdates(string $type): array { return ['Campaign X updated']; }
    private function getIOCAnalysis(string $type): array { return ['IoC analysis completed']; }
    private function getTTPAnalysis(string $type): array { return ['TTP analysis completed']; }
    private function getVulnerabilityIntelligence(string $type): array { return ['Vuln intel gathered']; }
    private function getInfrastructureAnalysis(string $type): array { return ['Infrastructure analyzed']; }
    private function getDefensiveMeasures(): array { return ['Implement WAF rules']; }
    private function getDetectionImprovements(): array { return ['Improve SIEM rules']; }
    private function getThreatHuntingFocus(): array { return ['Focus on APT techniques']; }
    private function getTrainingNeeds(): array { return ['Phishing awareness training']; }
    
    /**
     * Coletar dados reais do VirusTotal
     */
    private function collectFromVirusTotal(): array
    {
        if (!$this->virusTotalClient) {
            return [
                'status' => 'error',
                'error' => 'VirusTotal client not initialized',
                'iocs_collected' => 0
            ];
        }
        
        $collection = [
            'status' => 'success',
            'timestamp' => time(),
            'iocs_collected' => 0,
            'iocs' => [],
            'quota_info' => []
        ];
        
        try {
            // Verificar informações da cota
            $quotaInfo = $this->virusTotalClient->getQuotaInfo();
            if ($quotaInfo['success']) {
                $collection['quota_info'] = $quotaInfo['quota'];
            }
            
            // Lista de IPs suspeitos para verificar (exemplo)
            $suspiciousIPs = [
                '198.51.100.42',  // Example IP from threat intelligence
                '203.0.113.99',   // Another example IP
                '192.0.2.150'     // Third example IP
            ];
            
            foreach ($suspiciousIPs as $ip) {
                try {
                    $result = $this->virusTotalClient->checkIP($ip);
                    
                    if ($result['success']) {
                        $ioc = [
                            'type' => 'ip',
                            'value' => $ip,
                            'malicious' => $result['malicious'],
                            'threat_score' => $result['threat_score'],
                            'detection_engines' => $result['detection_engines'],
                            'country' => $result['country'],
                            'as_owner' => $result['as_owner'],
                            'categories' => $result['categories'],
                            'source' => 'virustotal',
                            'collected_at' => time(),
                            'confidence_score' => min(95.0, $result['threat_score'] + 10)
                        ];
                        
                        $collection['iocs'][] = $ioc;
                        $collection['iocs_collected']++;
                        
                        // Log if malicious
                        if ($result['malicious']) {
                            $this->audit->logEvent('virustotal_malicious_ip_detected', [
                                'ip' => $ip,
                                'threat_score' => $result['threat_score'],
                                'engines_count' => $result['total_engines']
                            ]);
                        }
                    }
                    
                    // Rate limiting - VirusTotal free tier allows 4 requests per minute
                    sleep(16); // Wait 16 seconds between requests for safety
                    
                } catch (\Exception $e) {
                    $this->audit->logEvent('virustotal_ip_check_error', [
                        'ip' => $ip,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            // Lista de domínios suspeitos para verificar
            $suspiciousDomains = [
                'malicious-c2.example.com',
                'phishing-bank.example.org'
            ];
            
            foreach ($suspiciousDomains as $domain) {
                try {
                    $result = $this->virusTotalClient->checkDomain($domain);
                    
                    if ($result['success']) {
                        $ioc = [
                            'type' => 'domain',
                            'value' => $domain,
                            'malicious' => $result['malicious'],
                            'threat_score' => $result['threat_score'],
                            'detection_engines' => $result['detection_engines'],
                            'categories' => $result['categories'],
                            'registrar' => $result['registrar'],
                            'creation_date' => $result['creation_date'],
                            'source' => 'virustotal',
                            'collected_at' => time(),
                            'confidence_score' => min(95.0, $result['threat_score'] + 10)
                        ];
                        
                        $collection['iocs'][] = $ioc;
                        $collection['iocs_collected']++;
                        
                        // Log if malicious
                        if ($result['malicious']) {
                            $this->audit->logEvent('virustotal_malicious_domain_detected', [
                                'domain' => $domain,
                                'threat_score' => $result['threat_score'],
                                'engines_count' => $result['total_engines']
                            ]);
                        }
                    }
                    
                    sleep(16); // Rate limiting
                    
                } catch (\Exception $e) {
                    $this->audit->logEvent('virustotal_domain_check_error', [
                        'domain' => $domain,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
        } catch (\Exception $e) {
            $collection['status'] = 'error';
            $collection['error'] = $e->getMessage();
            
            $this->audit->logEvent('virustotal_collection_error', [
                'error' => $e->getMessage()
            ]);
        }
        
        return $collection;
    }
    
    /**
     * Verificar IoC específico no VirusTotal
     */
    public function checkIOCWithVirusTotal(string $ioc, string $type): array
    {
        if (!$this->virusTotalClient) {
            return [
                'success' => false,
                'error' => 'VirusTotal client not initialized'
            ];
        }
        
        try {
            switch ($type) {
                case 'ip':
                    return $this->virusTotalClient->checkIP($ioc);
                    
                case 'domain':
                    return $this->virusTotalClient->checkDomain($ioc);
                    
                case 'url':
                    return $this->virusTotalClient->checkURL($ioc);
                    
                case 'file_hash':
                    return $this->virusTotalClient->checkFileHash($ioc);
                    
                default:
                    return [
                        'success' => false,
                        'error' => "Unsupported IoC type: {$type}"
                    ];
            }
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Obter informações da cota do VirusTotal
     */
    public function getVirusTotalQuota(): array
    {
        if (!$this->virusTotalClient) {
            return [
                'success' => false,
                'error' => 'VirusTotal client not initialized'
            ];
        }
        
        return $this->virusTotalClient->getQuotaInfo();
    }
}