<?php

namespace ERP\Modules\Dashboard;

use ERP\Core\App;

/**
 * Módulo Dashboard Executivo
 * Métricas consolidadas em tempo real
 */
class DashboardModule 
{
    private $database;
    private $cache;
    private $auth;
    
    public function __construct()
    {
        $this->database = app('database');
        $this->cache = app('cache');
        $this->auth = app('auth');
    }
    
    /**
     * Registra rotas do módulo
     */
    public function register(): void
    {
        $router = app()->get('router');
        
        $router->group('api/v1/dashboard', ['auth'], function($router) {
            $router->get('/', [DashboardController::class, 'index']);
            $router->get('/metrics', [DashboardController::class, 'metrics']);
            $router->get('/sales-chart', [DashboardController::class, 'salesChart']);
            $router->get('/financial-summary', [DashboardController::class, 'financialSummary']);
            $router->get('/top-products', [DashboardController::class, 'topProducts']);
            $router->get('/recent-activities', [DashboardController::class, 'recentActivities']);
            $router->get('/alerts', [DashboardController::class, 'alerts']);
            $router->post('/widgets/save', [DashboardController::class, 'saveWidgets']);
        });
    }
}

/**
 * Controller do Dashboard
 */
class DashboardController 
{
    private $dashboard;
    
    public function __construct()
    {
        $this->dashboard = new DashboardService();
    }
    
    /**
     * Dashboard principal
     */
    public function index(Request $request): Response
    {
        $companyId = auth()->companyId();
        
        $data = [
            'metrics' => $this->dashboard->getMainMetrics($companyId),
            'sales_chart' => $this->dashboard->getSalesChart($companyId, 30),
            'financial_summary' => $this->dashboard->getFinancialSummary($companyId),
            'top_products' => $this->dashboard->getTopProducts($companyId, 10),
            'recent_activities' => $this->dashboard->getRecentActivities($companyId, 10),
            'alerts' => $this->dashboard->getAlerts($companyId)
        ];
        
        return json_response($data, 'Dashboard carregado com sucesso');
    }
    
    /**
     * Métricas principais
     */
    public function metrics(Request $request): Response
    {
        $companyId = auth()->companyId();
        $period = $request->query('period', '30'); // dias
        
        $metrics = $this->dashboard->getMainMetrics($companyId, $period);
        
        return json_response($metrics);
    }
    
    /**
     * Gráfico de vendas
     */
    public function salesChart(Request $request): Response
    {
        $companyId = auth()->companyId();
        $period = $request->query('period', '12'); // meses
        $type = $request->query('type', 'monthly'); // daily, weekly, monthly
        
        $data = $this->dashboard->getSalesChart($companyId, $period, $type);
        
        return json_response($data);
    }
    
    /**
     * Resumo financeiro
     */
    public function financialSummary(Request $request): Response
    {
        $companyId = auth()->companyId();
        $date = $request->query('date', date('Y-m'));
        
        $summary = $this->dashboard->getFinancialSummary($companyId, $date);
        
        return json_response($summary);
    }
    
    /**
     * Top produtos
     */
    public function topProducts(Request $request): Response
    {
        $companyId = auth()->companyId();
        $limit = $request->query('limit', 10);
        $period = $request->query('period', '30');
        
        $products = $this->dashboard->getTopProducts($companyId, $limit, $period);
        
        return json_response($products);
    }
    
    /**
     * Atividades recentes
     */
    public function recentActivities(Request $request): Response
    {
        $companyId = auth()->companyId();
        $limit = $request->query('limit', 20);
        
        $activities = $this->dashboard->getRecentActivities($companyId, $limit);
        
        return json_response($activities);
    }
    
    /**
     * Alertas do sistema
     */
    public function alerts(Request $request): Response
    {
        $companyId = auth()->companyId();
        
        $alerts = $this->dashboard->getAlerts($companyId);
        
        return json_response($alerts);
    }
    
    /**
     * Salva configuração de widgets
     */
    public function saveWidgets(Request $request): Response
    {
        $companyId = auth()->companyId();
        $userId = auth()->id();
        $widgets = $request->input('widgets', []);
        
        $this->dashboard->saveUserWidgets($userId, $widgets);
        
        return json_response(null, 'Configuração salva com sucesso');
    }
}

/**
 * Service do Dashboard
 */
class DashboardService 
{
    private $database;
    private $cache;
    
    public function __construct()
    {
        $this->database = app('database');
        $this->cache = app('cache');
    }
    
    /**
     * Métricas principais
     */
    public function getMainMetrics(int $companyId, int $period = 30): array
    {
        $cacheKey = "dashboard:metrics:{$companyId}:{$period}";
        
        return $this->cache->remember($cacheKey, function () use ($companyId, $period) {
            $startDate = date('Y-m-d', strtotime("-{$period} days"));
            $today = date('Y-m-d');
            
            // Vendas do período
            $salesStats = $this->database->first("
                SELECT 
                    COUNT(*) as total_sales,
                    SUM(total_amount) as total_revenue,
                    AVG(total_amount) as avg_ticket
                FROM sales 
                WHERE company_id = ? 
                AND date >= ? 
                AND status != 'cancelled'
            ", [$companyId, $startDate]);
            
            // Vendas de hoje
            $todaySales = $this->database->first("
                SELECT 
                    COUNT(*) as today_sales,
                    SUM(total_amount) as today_revenue
                FROM sales 
                WHERE company_id = ? 
                AND date = ?
                AND status != 'cancelled'
            ", [$companyId, $today]);
            
            // Clientes
            $clientsStats = $this->database->first("
                SELECT 
                    COUNT(*) as total_clients,
                    COUNT(CASE WHEN created_at >= ? THEN 1 END) as new_clients
                FROM clients 
                WHERE company_id = ? 
                AND deleted_at IS NULL
            ", [$startDate, $companyId]);
            
            // Produtos em falta
            $stockAlerts = $this->database->first("
                SELECT COUNT(*) as products_out_stock
                FROM products 
                WHERE company_id = ? 
                AND current_stock <= min_stock 
                AND active = 1
            ", [$companyId]);
            
            // Contas a receber vencidas
            $overdueAccounts = $this->database->first("
                SELECT 
                    COUNT(*) as overdue_count,
                    SUM(amount - paid_amount) as overdue_amount
                FROM accounts_receivable 
                WHERE company_id = ? 
                AND status IN ('pending', 'partial')
                AND due_date < ?
            ", [$companyId, $today]);
            
            // Fluxo de caixa do mês
            $cashFlow = $this->database->first("
                SELECT 
                    SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as total_income,
                    SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as total_expense
                FROM financial_movements 
                WHERE company_id = ? 
                AND date >= ? 
                AND date <= ?
            ", [$companyId, date('Y-m-01'), $today]);
            
            return [
                'sales' => [
                    'total' => (int)($salesStats['total_sales'] ?? 0),
                    'revenue' => (float)($salesStats['total_revenue'] ?? 0),
                    'avg_ticket' => (float)($salesStats['avg_ticket'] ?? 0),
                    'today_sales' => (int)($todaySales['today_sales'] ?? 0),
                    'today_revenue' => (float)($todaySales['today_revenue'] ?? 0)
                ],
                'clients' => [
                    'total' => (int)($clientsStats['total_clients'] ?? 0),
                    'new' => (int)($clientsStats['new_clients'] ?? 0)
                ],
                'stock' => [
                    'products_out_stock' => (int)($stockAlerts['products_out_stock'] ?? 0)
                ],
                'financial' => [
                    'overdue_count' => (int)($overdueAccounts['overdue_count'] ?? 0),
                    'overdue_amount' => (float)($overdueAccounts['overdue_amount'] ?? 0),
                    'income' => (float)($cashFlow['total_income'] ?? 0),
                    'expense' => (float)($cashFlow['total_expense'] ?? 0),
                    'balance' => (float)(($cashFlow['total_income'] ?? 0) - ($cashFlow['total_expense'] ?? 0))
                ]
            ];
        }, 300); // Cache 5 minutos
    }
    
    /**
     * Gráfico de vendas
     */
    public function getSalesChart(int $companyId, int $period = 12, string $type = 'monthly'): array
    {
        $cacheKey = "dashboard:sales_chart:{$companyId}:{$period}:{$type}";
        
        return $this->cache->remember($cacheKey, function () use ($companyId, $period, $type) {
            switch ($type) {
                case 'daily':
                    $dateFormat = '%Y-%m-%d';
                    $startDate = date('Y-m-d', strtotime("-{$period} days"));
                    break;
                case 'weekly':
                    $dateFormat = '%Y-%u';
                    $startDate = date('Y-m-d', strtotime("-{$period} weeks"));
                    break;
                default: // monthly
                    $dateFormat = '%Y-%m';
                    $startDate = date('Y-m-d', strtotime("-{$period} months"));
            }
            
            $data = $this->database->select("
                SELECT 
                    DATE_FORMAT(date, ?) as period,
                    COUNT(*) as sales_count,
                    SUM(total_amount) as total_revenue,
                    AVG(total_amount) as avg_ticket
                FROM sales 
                WHERE company_id = ? 
                AND date >= ?
                AND status != 'cancelled'
                GROUP BY DATE_FORMAT(date, ?)
                ORDER BY period
            ", [$dateFormat, $companyId, $startDate, $dateFormat]);
            
            return [
                'labels' => array_column($data, 'period'),
                'datasets' => [
                    [
                        'label' => 'Vendas',
                        'data' => array_column($data, 'sales_count'),
                        'backgroundColor' => 'rgba(54, 162, 235, 0.2)',
                        'borderColor' => 'rgba(54, 162, 235, 1)',
                        'yAxisID' => 'y'
                    ],
                    [
                        'label' => 'Faturamento',
                        'data' => array_column($data, 'total_revenue'),
                        'backgroundColor' => 'rgba(255, 99, 132, 0.2)',
                        'borderColor' => 'rgba(255, 99, 132, 1)',
                        'yAxisID' => 'y1'
                    ]
                ]
            ];
        }, 900); // Cache 15 minutos
    }
    
    /**
     * Resumo financeiro
     */
    public function getFinancialSummary(int $companyId, string $month = null): array
    {
        if (! $month) {
            $month = date('Y-m');
        }
        
        $cacheKey = "dashboard:financial:{$companyId}:{$month}";
        
        return $this->cache->remember($cacheKey, function () use ($companyId, $month) {
            $startDate = $month . '-01';
            $endDate = date('Y-m-t', strtotime($startDate));
            
            // Contas a receber
            $receivable = $this->database->first("
                SELECT 
                    SUM(amount) as total_amount,
                    SUM(paid_amount) as paid_amount,
                    COUNT(*) as total_count,
                    COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid_count
                FROM accounts_receivable 
                WHERE company_id = ? 
                AND due_date BETWEEN ? AND ?
            ", [$companyId, $startDate, $endDate]);
            
            // Contas a pagar
            $payable = $this->database->first("
                SELECT 
                    SUM(amount) as total_amount,
                    SUM(paid_amount) as paid_amount,
                    COUNT(*) as total_count,
                    COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid_count
                FROM accounts_payable 
                WHERE company_id = ? 
                AND due_date BETWEEN ? AND ?
            ", [$companyId, $startDate, $endDate]);
            
            // Movimentações por categoria
            $categories = $this->database->select("
                SELECT 
                    fc.name,
                    fc.type,
                    SUM(fm.amount) as total
                FROM financial_movements fm
                JOIN financial_categories fc ON fm.category_id = fc.id
                WHERE fm.company_id = ? 
                AND fm.date BETWEEN ? AND ?
                GROUP BY fc.id, fc.name, fc.type
                ORDER BY total DESC
            ", [$companyId, $startDate, $endDate]);
            
            return [
                'receivable' => [
                    'total_amount' => (float)($receivable['total_amount'] ?? 0),
                    'paid_amount' => (float)($receivable['paid_amount'] ?? 0),
                    'pending_amount' => (float)(($receivable['total_amount'] ?? 0) - ($receivable['paid_amount'] ?? 0)),
                    'total_count' => (int)($receivable['total_count'] ?? 0),
                    'paid_count' => (int)($receivable['paid_count'] ?? 0)
                ],
                'payable' => [
                    'total_amount' => (float)($payable['total_amount'] ?? 0),
                    'paid_amount' => (float)($payable['paid_amount'] ?? 0),
                    'pending_amount' => (float)(($payable['total_amount'] ?? 0) - ($payable['paid_amount'] ?? 0)),
                    'total_count' => (int)($payable['total_count'] ?? 0),
                    'paid_count' => (int)($payable['paid_count'] ?? 0)
                ],
                'categories' => $categories
            ];
        }, 1800); // Cache 30 minutos
    }
    
    /**
     * Top produtos mais vendidos
     */
    public function getTopProducts(int $companyId, int $limit = 10, int $period = 30): array
    {
        $cacheKey = "dashboard:top_products:{$companyId}:{$limit}:{$period}";
        
        return $this->cache->remember($cacheKey, function () use ($companyId, $limit, $period) {
            $startDate = date('Y-m-d', strtotime("-{$period} days"));
            
            return $this->database->select("
                SELECT 
                    p.name,
                    p.sku,
                    p.sale_price,
                    SUM(si.quantity) as total_quantity,
                    SUM(si.total_price) as total_revenue,
                    COUNT(DISTINCT s.id) as sales_count
                FROM sale_items si
                JOIN sales s ON si.sale_id = s.id
                JOIN products p ON si.product_id = p.id
                WHERE s.company_id = ? 
                AND s.date >= ?
                AND s.status != 'cancelled'
                GROUP BY p.id, p.name, p.sku, p.sale_price
                ORDER BY total_quantity DESC
                LIMIT ?
            ", [$companyId, $startDate, $limit]);
        }, 1800); // Cache 30 minutos
    }
    
    /**
     * Atividades recentes
     */
    public function getRecentActivities(int $companyId, int $limit = 10): array
    {
        $cacheKey = "dashboard:activities:{$companyId}:{$limit}";
        
        return $this->cache->remember($cacheKey, function () use ($companyId, $limit) {
            $activities = [];
            
            // Vendas recentes
            $recentSales = $this->database->select("
                SELECT 
                    'sale' as type,
                    s.id,
                    s.number,
                    s.total_amount,
                    s.created_at,
                    c.name as client_name,
                    u.name as user_name
                FROM sales s
                LEFT JOIN clients c ON s.client_id = c.id
                JOIN users u ON s.user_id = u.id
                WHERE s.company_id = ?
                ORDER BY s.created_at DESC
                LIMIT ?
            ", [$companyId, $limit]);
            
            // Clientes recentes
            $recentClients = $this->database->select("
                SELECT 
                    'client' as type,
                    id,
                    name,
                    email,
                    created_at
                FROM clients
                WHERE company_id = ?
                AND deleted_at IS NULL
                ORDER BY created_at DESC
                LIMIT ?
            ", [$companyId, $limit]);
            
            // Produtos com estoque baixo
            $lowStock = $this->database->select("
                SELECT 
                    'stock_alert' as type,
                    id,
                    name,
                    sku,
                    current_stock,
                    min_stock,
                    updated_at as created_at
                FROM products
                WHERE company_id = ?
                AND current_stock <= min_stock
                AND active = 1
                ORDER BY current_stock ASC
                LIMIT ?
            ", [$companyId, $limit]);
            
            // Combina e ordena atividades
            $activities = array_merge($recentSales, $recentClients, $lowStock);
            
            usort($activities, function($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
            });
            
            return array_slice($activities, 0, $limit);
        }, 300); // Cache 5 minutos
    }
    
    /**
     * Alertas do sistema
     */
    public function getAlerts(int $companyId): array
    {
        $cacheKey = "dashboard:alerts:{$companyId}";
        
        return $this->cache->remember($cacheKey, function () use ($companyId) {
            $alerts = [];
            
            // Produtos em falta
            $outOfStock = $this->database->first("
                SELECT COUNT(*) as count
                FROM products 
                WHERE company_id = ? 
                AND current_stock = 0 
                AND active = 1
            ", [$companyId]);
            
            if ($outOfStock['count'] > 0) {
                $alerts[] = [
                    'type' => 'danger',
                    'title' => 'Produtos em Falta',
                    'message' => "{$outOfStock['count']} produto(s) sem estoque",
                    'action' => '/estoque/produtos?filter=out_of_stock'
                ];
            }
            
            // Estoque baixo
            $lowStock = $this->database->first("
                SELECT COUNT(*) as count
                FROM products 
                WHERE company_id = ? 
                AND current_stock <= min_stock 
                AND current_stock > 0
                AND active = 1
            ", [$companyId]);
            
            if ($lowStock['count'] > 0) {
                $alerts[] = [
                    'type' => 'warning',
                    'title' => 'Estoque Baixo',
                    'message' => "{$lowStock['count']} produto(s) com estoque baixo",
                    'action' => '/estoque/produtos?filter=low_stock'
                ];
            }
            
            // Contas vencidas
            $overdue = $this->database->first("
                SELECT COUNT(*) as count, SUM(amount - paid_amount) as total
                FROM accounts_receivable 
                WHERE company_id = ? 
                AND status IN ('pending', 'partial')
                AND due_date < CURDATE()
            ", [$companyId]);
            
            if ($overdue['count'] > 0) {
                $alerts[] = [
                    'type' => 'danger',
                    'title' => 'Contas Vencidas',
                    'message' => "{$overdue['count']} conta(s) em atraso - " . money_format($overdue['total']),
                    'action' => '/financeiro/receber?filter=overdue'
                ];
            }
            
            // Vendas baixas (comparado com média dos últimos 30 dias)
            $avgSales = $this->database->first("
                SELECT AVG(daily_sales) as avg_daily
                FROM (
                    SELECT DATE(created_at) as date, COUNT(*) as daily_sales
                    FROM sales 
                    WHERE company_id = ? 
                    AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    AND status != 'cancelled'
                    GROUP BY DATE(created_at)
                ) as daily_stats
            ", [$companyId]);
            
            $todaySales = $this->database->first("
                SELECT COUNT(*) as today_sales
                FROM sales 
                WHERE company_id = ? 
                AND DATE(created_at) = CURDATE()
                AND status != 'cancelled'
            ", [$companyId]);
            
            $avgDaily = $avgSales['avg_daily'] ?? 0;
            $today = $todaySales['today_sales'] ?? 0;
            
            if ($avgDaily > 0 && $today < ($avgDaily * 0.5)) {
                $alerts[] = [
                    'type' => 'info',
                    'title' => 'Vendas Baixas',
                    'message' => "Vendas hoje ({$today}) abaixo da média ({$avgDaily})",
                    'action' => '/pdv'
                ];
            }
            
            return $alerts;
        }, 300); // Cache 5 minutos
    }
    
    /**
     * Salva configuração de widgets do usuário
     */
    public function saveUserWidgets(int $userId, array $widgets): bool
    {
        $key = "user_widgets:{$userId}";
        return $this->cache->set($key, $widgets, 86400 * 30); // 30 dias
    }
    
    /**
     * Obtém configuração de widgets do usuário
     */
    public function getUserWidgets(int $userId): array
    {
        $key = "user_widgets:{$userId}";
        return $this->cache->get($key, $this->getDefaultWidgets());
    }
    
    /**
     * Widgets padrão
     */
    private function getDefaultWidgets(): array
    {
        return [
            ['id' => 'sales_metrics', 'position' => 1, 'size' => 'large'],
            ['id' => 'financial_summary', 'position' => 2, 'size' => 'medium'],
            ['id' => 'sales_chart', 'position' => 3, 'size' => 'large'],
            ['id' => 'top_products', 'position' => 4, 'size' => 'medium'],
            ['id' => 'recent_activities', 'position' => 5, 'size' => 'medium'],
            ['id' => 'alerts', 'position' => 6, 'size' => 'small']
        ];
    }
}
