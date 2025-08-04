<?php

namespace ERP\Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Testes funcionais da API do Dashboard
 */
class DashboardApiTest extends TestCase
{
    private string $baseUrl;
    private string $authToken;
    
    protected function setUp(): void
    {
        $this->baseUrl = $_ENV['APP_URL'] ?? 'http://localhost';
        $this->authToken = $this->getAuthToken();
    }
    
    private function getAuthToken(): string
    {
        // Simular login para obter token
        $loginData = [
            'email' => 'admin@test.com',
            'password' => 'password123'
        ];
        
        $response = $this->makeRequest('POST', '/api/v1/auth/login', $loginData);
        
        return $response['token'] ?? '';
    }
    
    private function makeRequest(string $method, string $endpoint, array $data = [], array $headers = []): array
    {
        $url = $this->baseUrl . $endpoint;
        
        $defaultHeaders = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        
        if ($this->authToken) {
            $defaultHeaders[] = 'Authorization: Bearer ' . $this->authToken;
        }
        
        $headers = array_merge($defaultHeaders, $headers);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $this->assertNotFalse($response, 'Request failed');
        
        $decoded = json_decode($response, true);
        $this->assertIsArray($decoded, 'Invalid JSON response');
        
        return [
            'status_code' => $httpCode,
            'data' => $decoded
        ] + $decoded;
    }
    
    public function testDashboardIndexReturnsExpectedStructure(): void
    {
        $response = $this->makeRequest('GET', '/api/v1/dashboard/');
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertArrayHasKey('data', $response);
        
        $data = $response['data'];
        $this->assertArrayHasKey('metrics', $data);
        $this->assertArrayHasKey('sales_chart', $data);
        $this->assertArrayHasKey('financial_summary', $data);
        $this->assertArrayHasKey('top_products', $data);
        $this->assertArrayHasKey('recent_activities', $data);
        $this->assertArrayHasKey('alerts', $data);
    }
    
    public function testMetricsEndpointReturnsValidData(): void
    {
        $response = $this->makeRequest('GET', '/api/v1/dashboard/metrics?period=30');
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertArrayHasKey('data', $response);
        
        $metrics = $response['data'];
        $this->assertArrayHasKey('sales', $metrics);
        $this->assertArrayHasKey('clients', $metrics);
        $this->assertArrayHasKey('stock', $metrics);
        $this->assertArrayHasKey('financial', $metrics);
        
        // Verificar estrutura das vendas
        $sales = $metrics['sales'];
        $this->assertArrayHasKey('total', $sales);
        $this->assertArrayHasKey('revenue', $sales);
        $this->assertArrayHasKey('avg_ticket', $sales);
        $this->assertIsNumeric($sales['total']);
        $this->assertIsNumeric($sales['revenue']);
    }
    
    public function testSalesChartWithDifferentPeriods(): void
    {
        $periods = ['daily', 'weekly', 'monthly'];
        
        foreach ($periods as $period) {
            $response = $this->makeRequest('GET', "/api/v1/dashboard/sales-chart?type={$period}&period=12");
            
            $this->assertEquals(200, $response['status_code']);
            $this->assertArrayHasKey('data', $response);
            
            $chart = $response['data'];
            $this->assertArrayHasKey('labels', $chart);
            $this->assertArrayHasKey('datasets', $chart);
            $this->assertIsArray($chart['labels']);
            $this->assertIsArray($chart['datasets']);
            $this->assertCount(2, $chart['datasets']); // Vendas e Faturamento
        }
    }
    
    public function testTopProductsEndpoint(): void
    {
        $response = $this->makeRequest('GET', '/api/v1/dashboard/top-products?limit=5&period=30');
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertArrayHasKey('data', $response);
        
        $products = $response['data'];
        $this->assertIsArray($products);
        $this->assertLessThanOrEqual(5, count($products));
        
        if (!empty($products)) {
            $product = $products[0];
            $this->assertArrayHasKey('name', $product);
            $this->assertArrayHasKey('sku', $product);
            $this->assertArrayHasKey('total_quantity', $product);
            $this->assertArrayHasKey('total_revenue', $product);
        }
    }
    
    public function testAlertsEndpoint(): void
    {
        $response = $this->makeRequest('GET', '/api/v1/dashboard/alerts');
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertArrayHasKey('data', $response);
        
        $alerts = $response['data'];
        $this->assertIsArray($alerts);
        
        foreach ($alerts as $alert) {
            $this->assertArrayHasKey('type', $alert);
            $this->assertArrayHasKey('title', $alert);
            $this->assertArrayHasKey('message', $alert);
            $this->assertContains($alert['type'], ['danger', 'warning', 'info', 'success']);
        }
    }
    
    public function testSaveWidgetsEndpoint(): void
    {
        $widgets = [
            ['id' => 'sales_metrics', 'position' => 1, 'size' => 'large'],
            ['id' => 'financial_summary', 'position' => 2, 'size' => 'medium'],
            ['id' => 'sales_chart', 'position' => 3, 'size' => 'large']
        ];
        
        $response = $this->makeRequest('POST', '/api/v1/dashboard/widgets/save', ['widgets' => $widgets]);
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertArrayHasKey('message', $response);
        $this->assertEquals('Configuração salva com sucesso', $response['message']);
    }
    
    public function testUnauthorizedAccessReturns401(): void
    {
        $this->authToken = ''; // Remove token
        
        $response = $this->makeRequest('GET', '/api/v1/dashboard/');
        
        $this->assertEquals(401, $response['status_code']);
    }
    
    public function testInvalidPeriodParameterHandling(): void
    {
        $response = $this->makeRequest('GET', '/api/v1/dashboard/metrics?period=invalid');
        
        // Should still return 200 with default period
        $this->assertEquals(200, $response['status_code']);
        $this->assertArrayHasKey('data', $response);
    }
    
    public function testRateLimiting(): void
    {
        // Fazer múltiplas requisições rapidamente
        $responses = [];
        for ($i = 0; $i < 10; $i++) {
            $responses[] = $this->makeRequest('GET', '/api/v1/dashboard/metrics');
        }
        
        // Verificar se pelo menos algumas passaram
        $successCount = 0;
        foreach ($responses as $response) {
            if ($response['status_code'] === 200) {
                $successCount++;
            }
        }
        
        $this->assertGreaterThan(0, $successCount, 'Nenhuma requisição foi bem-sucedida');
    }
}