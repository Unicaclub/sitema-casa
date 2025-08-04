<?php

namespace ERP\Tests\Unit\Modules\Dashboard;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use ERP\Modules\Dashboard\DashboardService;
use ERP\Core\Database;
use ERP\Core\Cache;

/**
 * Testes para DashboardService
 */
class DashboardServiceTest extends TestCase
{
    private DashboardService $service;
    private MockObject $database;
    private MockObject $cache;
    
    protected function setUp(): void
    {
        $this->database = $this->createMock(Database::class);
        $this->cache = $this->createMock(Cache::class);
        
        // Mock global app function
        $GLOBALS['app_instance'] = $this->createMock(\ERP\Core\App::class);
        $GLOBALS['app_instance']->method('get')
            ->willReturnMap([
                ['database', $this->database],
                ['cache', $this->cache]
            ]);
        
        $this->service = new DashboardService();
    }
    
    public function testGetMainMetricsReturnsExpectedStructure(): void
    {
        $companyId = 1;
        $expectedData = [
            'sales' => [
                'total' => 100,
                'revenue' => 50000.00,
                'avg_ticket' => 500.00,
                'today_sales' => 5,
                'today_revenue' => 2500.00
            ],
            'clients' => [
                'total' => 250,
                'new' => 15
            ],
            'stock' => [
                'products_out_stock' => 3
            ],
            'financial' => [
                'overdue_count' => 2,
                'overdue_amount' => 1500.00,
                'income' => 45000.00,
                'expense' => 25000.00,
                'balance' => 20000.00
            ]
        ];
        
        $this->cache->expects($this->once())
            ->method('remember')
            ->willReturn($expectedData);
        
        $result = $this->service->getMainMetrics($companyId);
        
        $this->assertEquals($expectedData, $result);
        $this->assertArrayHasKey('sales', $result);
        $this->assertArrayHasKey('clients', $result);
        $this->assertArrayHasKey('stock', $result);
        $this->assertArrayHasKey('financial', $result);
    }
    
    public function testGetSalesChartReturnsChartData(): void
    {
        $companyId = 1;
        $expectedData = [
            'labels' => ['2025-01', '2025-02', '2025-03'],
            'datasets' => [
                [
                    'label' => 'Vendas',
                    'data' => [50, 65, 80],
                    'backgroundColor' => 'rgba(54, 162, 235, 0.2)',
                    'borderColor' => 'rgba(54, 162, 235, 1)',
                    'yAxisID' => 'y'
                ],
                [
                    'label' => 'Faturamento',
                    'data' => [25000, 32500, 40000],
                    'backgroundColor' => 'rgba(255, 99, 132, 0.2)',
                    'borderColor' => 'rgba(255, 99, 132, 1)',
                    'yAxisID' => 'y1'
                ]
            ]
        ];
        
        $this->cache->expects($this->once())
            ->method('remember')
            ->willReturn($expectedData);
        
        $result = $this->service->getSalesChart($companyId);
        
        $this->assertEquals($expectedData, $result);
        $this->assertArrayHasKey('labels', $result);
        $this->assertArrayHasKey('datasets', $result);
        $this->assertCount(2, $result['datasets']);
    }
    
    public function testGetTopProductsReturnsProductList(): void
    {
        $companyId = 1;
        $expectedProducts = [
            [
                'name' => 'Produto A',
                'sku' => 'PROD-001',
                'sale_price' => 29.90,
                'total_quantity' => 150,
                'total_revenue' => 4485.00,
                'sales_count' => 45
            ],
            [
                'name' => 'Produto B',
                'sku' => 'PROD-002',
                'sale_price' => 49.90,
                'total_quantity' => 120,
                'total_revenue' => 5988.00,
                'sales_count' => 38
            ]
        ];
        
        $this->cache->expects($this->once())
            ->method('remember')
            ->willReturn($expectedProducts);
        
        $result = $this->service->getTopProducts($companyId);
        
        $this->assertEquals($expectedProducts, $result);
        $this->assertIsArray($result);
        
        if (!empty($result)) {
            $this->assertArrayHasKey('name', $result[0]);
            $this->assertArrayHasKey('sku', $result[0]);
            $this->assertArrayHasKey('total_quantity', $result[0]);
        }
    }
    
    public function testGetAlertsReturnsAlertsList(): void
    {
        $companyId = 1;
        $expectedAlerts = [
            [
                'type' => 'danger',
                'title' => 'Produtos em Falta',
                'message' => '3 produto(s) sem estoque',
                'action' => '/estoque/produtos?filter=out_of_stock'
            ],
            [
                'type' => 'warning',
                'title' => 'Estoque Baixo',
                'message' => '5 produto(s) com estoque baixo',
                'action' => '/estoque/produtos?filter=low_stock'
            ]
        ];
        
        $this->cache->expects($this->once())
            ->method('remember')
            ->willReturn($expectedAlerts);
        
        $result = $this->service->getAlerts($companyId);
        
        $this->assertEquals($expectedAlerts, $result);
        $this->assertIsArray($result);
        
        if (!empty($result)) {
            $this->assertArrayHasKey('type', $result[0]);
            $this->assertArrayHasKey('title', $result[0]);
            $this->assertArrayHasKey('message', $result[0]);
        }
    }
    
    public function testSaveUserWidgetsReturnsBoolean(): void
    {
        $userId = 1;
        $widgets = [
            ['id' => 'sales_metrics', 'position' => 1, 'size' => 'large'],
            ['id' => 'financial_summary', 'position' => 2, 'size' => 'medium']
        ];
        
        $this->cache->expects($this->once())
            ->method('set')
            ->with("user_widgets:{$userId}", $widgets, 86400 * 30)
            ->willReturn(true);
        
        $result = $this->service->saveUserWidgets($userId, $widgets);
        
        $this->assertTrue($result);
    }
    
    protected function tearDown(): void
    {
        unset($GLOBALS['app_instance']);
    }
}