<?php

declare(strict_types=1);

namespace ERP\Core\Security\Integrations;

/**
 * VirusTotal API Client - Integração Real
 * 
 * Cliente para integração com a API v3 do VirusTotal
 * 
 * @package ERP\Core\Security\Integrations
 */
final class VirusTotalClient
{
    private string $apiKey;
    private string $baseUrl = 'https://www.virustotal.com/api/v3';
    private int $rateLimitDelay = 15; // seconds between requests (free tier)
    private array $lastRequestTime = [];
    
    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }
    
    /**
     * Verificar IP no VirusTotal
     */
    public function checkIP(string $ip): array
    {
        $this->enforceRateLimit('ip');
        
        $endpoint = "/ip_addresses/{$ip}";
        $response = $this->makeRequest('GET', $endpoint);
        
        if (!$response['success']) {
            return [
                'success' => false,
                'error' => $response['error'],
                'malicious' => false,
                'threat_score' => 0.0
            ];
        }
        
        $data = $response['data'];
        $stats = $data['attributes']['last_analysis_stats'] ?? [];
        
        $malicious = ($stats['malicious'] ?? 0) > 0;
        $suspicious = ($stats['suspicious'] ?? 0) > 0;
        $totalEngines = array_sum($stats);
        
        $threatScore = $totalEngines > 0 
            ? (($stats['malicious'] ?? 0) + ($stats['suspicious'] ?? 0) * 0.5) / $totalEngines * 100
            : 0.0;
        
        return [
            'success' => true,
            'ip' => $ip,
            'malicious' => $malicious,
            'suspicious' => $suspicious,
            'threat_score' => round($threatScore, 2),
            'detection_engines' => $stats,
            'total_engines' => $totalEngines,
            'reputation' => $data['attributes']['reputation'] ?? 0,
            'country' => $data['attributes']['country'] ?? 'Unknown',
            'as_owner' => $data['attributes']['as_owner'] ?? 'Unknown',
            'last_analysis_date' => $data['attributes']['last_analysis_date'] ?? null,
            'categories' => $data['attributes']['categories'] ?? [],
            'raw_response' => $data
        ];
    }
    
    /**
     * Verificar domínio no VirusTotal
     */
    public function checkDomain(string $domain): array
    {
        $this->enforceRateLimit('domain');
        
        $endpoint = "/domains/{$domain}";
        $response = $this->makeRequest('GET', $endpoint);
        
        if (!$response['success']) {
            return [
                'success' => false,
                'error' => $response['error'],
                'malicious' => false,
                'threat_score' => 0.0
            ];
        }
        
        $data = $response['data'];
        $stats = $data['attributes']['last_analysis_stats'] ?? [];
        
        $malicious = ($stats['malicious'] ?? 0) > 0;
        $suspicious = ($stats['suspicious'] ?? 0) > 0;
        $totalEngines = array_sum($stats);
        
        $threatScore = $totalEngines > 0 
            ? (($stats['malicious'] ?? 0) + ($stats['suspicious'] ?? 0) * 0.5) / $totalEngines * 100
            : 0.0;
        
        return [
            'success' => true,
            'domain' => $domain,
            'malicious' => $malicious,
            'suspicious' => $suspicious,
            'threat_score' => round($threatScore, 2),
            'detection_engines' => $stats,
            'total_engines' => $totalEngines,
            'reputation' => $data['attributes']['reputation'] ?? 0,
            'categories' => $data['attributes']['categories'] ?? [],
            'creation_date' => $data['attributes']['creation_date'] ?? null,
            'last_modification_date' => $data['attributes']['last_modification_date'] ?? null,
            'registrar' => $data['attributes']['registrar'] ?? 'Unknown',
            'raw_response' => $data
        ];
    }
    
    /**
     * Verificar URL no VirusTotal
     */
    public function checkURL(string $url): array
    {
        $this->enforceRateLimit('url');
        
        // Encode URL for safe transmission
        $urlId = base64_encode($url);
        $urlId = str_replace(['=', '+', '/'], ['', '-', '_'], $urlId);
        
        $endpoint = "/urls/{$urlId}";
        $response = $this->makeRequest('GET', $endpoint);
        
        if (!$response['success']) {
            // If URL not found, submit for analysis
            $submitResponse = $this->submitURL($url);
            if ($submitResponse['success']) {
                return [
                    'success' => true,
                    'url' => $url,
                    'status' => 'submitted_for_analysis',
                    'analysis_id' => $submitResponse['analysis_id'],
                    'malicious' => false,
                    'threat_score' => 0.0,
                    'message' => 'URL submitted for analysis. Check back later for results.'
                ];
            }
            
            return [
                'success' => false,
                'error' => $response['error'],
                'malicious' => false,
                'threat_score' => 0.0
            ];
        }
        
        $data = $response['data'];
        $stats = $data['attributes']['last_analysis_stats'] ?? [];
        
        $malicious = ($stats['malicious'] ?? 0) > 0;
        $suspicious = ($stats['suspicious'] ?? 0) > 0;
        $totalEngines = array_sum($stats);
        
        $threatScore = $totalEngines > 0 
            ? (($stats['malicious'] ?? 0) + ($stats['suspicious'] ?? 0) * 0.5) / $totalEngines * 100
            : 0.0;
        
        return [
            'success' => true,
            'url' => $url,
            'malicious' => $malicious,
            'suspicious' => $suspicious,
            'threat_score' => round($threatScore, 2),
            'detection_engines' => $stats,
            'total_engines' => $totalEngines,
            'categories' => $data['attributes']['categories'] ?? [],
            'last_analysis_date' => $data['attributes']['last_analysis_date'] ?? null,
            'title' => $data['attributes']['title'] ?? '',
            'raw_response' => $data
        ];
    }
    
    /**
     * Verificar hash de arquivo
     */
    public function checkFileHash(string $hash): array
    {
        $this->enforceRateLimit('file');
        
        $endpoint = "/files/{$hash}";
        $response = $this->makeRequest('GET', $endpoint);
        
        if (!$response['success']) {
            return [
                'success' => false,
                'error' => $response['error'],
                'malicious' => false,
                'threat_score' => 0.0
            ];
        }
        
        $data = $response['data'];
        $stats = $data['attributes']['last_analysis_stats'] ?? [];
        
        $malicious = ($stats['malicious'] ?? 0) > 0;
        $suspicious = ($stats['suspicious'] ?? 0) > 0;
        $totalEngines = array_sum($stats);
        
        $threatScore = $totalEngines > 0 
            ? (($stats['malicious'] ?? 0) + ($stats['suspicious'] ?? 0) * 0.5) / $totalEngines * 100
            : 0.0;
        
        return [
            'success' => true,
            'hash' => $hash,
            'malicious' => $malicious,
            'suspicious' => $suspicious,
            'threat_score' => round($threatScore, 2),
            'detection_engines' => $stats,
            'total_engines' => $totalEngines,
            'file_type' => $data['attributes']['type_description'] ?? 'Unknown',
            'file_size' => $data['attributes']['size'] ?? 0,
            'md5' => $data['attributes']['md5'] ?? '',
            'sha1' => $data['attributes']['sha1'] ?? '',
            'sha256' => $data['attributes']['sha256'] ?? '',
            'names' => $data['attributes']['names'] ?? [],
            'signature_info' => $data['attributes']['signature_info'] ?? [],
            'creation_date' => $data['attributes']['creation_date'] ?? null,
            'first_submission_date' => $data['attributes']['first_submission_date'] ?? null,
            'raw_response' => $data
        ];
    }
    
    /**
     * Submeter URL para análise
     */
    public function submitURL(string $url): array
    {
        $this->enforceRateLimit('submit');
        
        $endpoint = '/urls';
        $data = ['url' => $url];
        
        $response = $this->makeRequest('POST', $endpoint, $data);
        
        if (!$response['success']) {
            return [
                'success' => false,
                'error' => $response['error']
            ];
        }
        
        return [
            'success' => true,
            'analysis_id' => $response['data']['id'] ?? null,
            'message' => 'URL submitted successfully for analysis'
        ];
    }
    
    /**
     * Obter comentários sobre um recurso
     */
    public function getComments(string $resourceId): array
    {
        $this->enforceRateLimit('comments');
        
        // Detect resource type and format ID
        if (filter_var($resourceId, FILTER_VALIDATE_IP)) {
            $endpoint = "/ip_addresses/{$resourceId}/comments";
        } elseif (filter_var($resourceId, FILTER_VALIDATE_DOMAIN)) {
            $endpoint = "/domains/{$resourceId}/comments";
        } elseif (preg_match('/^[a-f0-9]{32,64}$/i', $resourceId)) {
            $endpoint = "/files/{$resourceId}/comments";
        } else {
            // Assume it's a URL
            $urlId = base64_encode($resourceId);
            $urlId = str_replace(['=', '+', '/'], ['', '-', '_'], $urlId);
            $endpoint = "/urls/{$urlId}/comments";
        }
        
        $response = $this->makeRequest('GET', $endpoint);
        
        if (!$response['success']) {
            return [
                'success' => false,
                'error' => $response['error'],
                'comments' => []
            ];
        }
        
        $comments = [];
        foreach ($response['data']['data'] ?? [] as $comment) {
            $comments[] = [
                'author' => $comment['attributes']['author'] ?? 'Anonymous',
                'date' => $comment['attributes']['date'] ?? null,
                'comment' => $comment['attributes']['text'] ?? '',
                'votes' => [
                    'positive' => $comment['attributes']['positive_votes'] ?? 0,
                    'negative' => $comment['attributes']['negative_votes'] ?? 0
                ]
            ];
        }
        
        return [
            'success' => true,
            'resource_id' => $resourceId,
            'comments' => $comments,
            'total_comments' => count($comments)
        ];
    }
    
    /**
     * Fazer requisição HTTP para API
     */
    private function makeRequest(string $method, string $endpoint, array $data = []): array
    {
        $url = $this->baseUrl . $endpoint;
        
        $headers = [
            'x-apikey: ' . $this->apiKey,
            'Accept: application/json',
            'User-Agent: ERP-Sistema-Security/1.0'
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0
        ]);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if (!empty($data)) {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            }
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'error' => "cURL Error: {$error}",
                'http_code' => 0
            ];
        }
        
        $decodedResponse = json_decode($response, true);
        
        if ($httpCode !== 200) {
            $errorMessage = $decodedResponse['error']['message'] ?? "HTTP Error {$httpCode}";
            return [
                'success' => false,
                'error' => $errorMessage,
                'http_code' => $httpCode
            ];
        }
        
        return [
            'success' => true,
            'data' => $decodedResponse,
            'http_code' => $httpCode
        ];
    }
    
    /**
     * Aplicar rate limiting
     */
    private function enforceRateLimit(string $requestType): void
    {
        $currentTime = time();
        $lastRequest = $this->lastRequestTime[$requestType] ?? 0;
        
        $timeDiff = $currentTime - $lastRequest;
        
        if ($timeDiff < $this->rateLimitDelay) {
            $sleepTime = $this->rateLimitDelay - $timeDiff;
            sleep($sleepTime);
        }
        
        $this->lastRequestTime[$requestType] = time();
    }
    
    /**
     * Configurar rate limit personalizado
     */
    public function setRateLimit(int $delaySeconds): void
    {
        $this->rateLimitDelay = $delaySeconds;
    }
    
    /**
     * Verificar se API key é válida
     */
    public function validateApiKey(): bool
    {
        $response = $this->makeRequest('GET', '/users/' . $this->apiKey);
        return $response['success'] && isset($response['data']['data']);
    }
    
    /**
     * Obter informações da cota da API
     */
    public function getQuotaInfo(): array
    {
        $response = $this->makeRequest('GET', '/users/' . $this->apiKey);
        
        if (!$response['success']) {
            return [
                'success' => false,
                'error' => $response['error']
            ];
        }
        
        $userData = $response['data']['data']['attributes'] ?? [];
        
        return [
            'success' => true,
            'quota' => [
                'monthly_quota' => $userData['quotas']['api_requests_monthly']['allowed'] ?? 0,
                'monthly_used' => $userData['quotas']['api_requests_monthly']['used'] ?? 0,
                'daily_quota' => $userData['quotas']['api_requests_daily']['allowed'] ?? 0,
                'daily_used' => $userData['quotas']['api_requests_daily']['used'] ?? 0,
                'hourly_quota' => $userData['quotas']['api_requests_hourly']['allowed'] ?? 0,
                'hourly_used' => $userData['quotas']['api_requests_hourly']['used'] ?? 0
            ],
            'user_type' => $userData['type'] ?? 'free'
        ];
    }
}