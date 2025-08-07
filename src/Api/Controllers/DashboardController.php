<?php

declare(strict_types=1);

namespace ERP\Api\Controllers;

use ERP\Core\Http\Request;
use ERP\Core\Http\Response;
use Carbon\Carbon;

/**
 * Controlador da API do Dashboard
 * 
 * Gerencia métricas do dashboard, KPIs e análises
 * 
 * @package ERP\Api\Controllers
 */
final class DashboardController extends ControladorBase
{
    /**
     * Obter métricas e KPIs do dashboard
     * GET /api/dashboard/metrics
     */
    public function metrics(Request $request): Response
    {
        $this->autorizar('dashboard.visualizar');
        
        return $this->cacheado('metricas_dashboard', function () use ($request) {
            $periodo = $request->query('period', '30'); // dias
            $dataInicio = Carbon::now()->subDays((int)$periodo)->startOfDay();
            
            $metricas = [
                'vendas' => $this->obterMetricasVendas($dataInicio),
                'clientes' => $this->obterMetricasClientes($dataInicio),
                'estoque' => $this->obterMetricasEstoque(),
                'financeiro' => $this->obterMetricasFinanceiras($dataInicio),
                'pedidos' => $this->obterMetricasPedidos($dataInicio),
                'performance' => $this->obterMetricasPerformance($dataInicio),
            ];
            
            return $metricas;
        }, 300); // Cache por 5 minutos
    }
    
    /**
     * Obter dados do gráfico de vendas
     * GET /api/dashboard/sales-chart
     */
    public function salesChart(Request $request): Response
    {
        $this->autorizar('dashboard.visualizar');
        
        $periodo = $request->query('period', '30');
        $agruparPor = $request->query('group_by', 'day'); // day, week, month
        
        return $this->cacheado("grafico_vendas_{$periodo}_{$agruparPor}", function () use ($periodo, $agruparPor) {
            $dataInicio = Carbon::now()->subDays((int)$periodo)->startOfDay();
            
            $query = $this->database->table('vendas')
                ->selectRaw($this->obterSelectGraficoVendas($agruparPor))
                ->where('created_at', '>=', $dataInicio);
            
            $this->aplicarFiltroTenant($query);
            
            $dados = $query->groupBy($this->obterGroupByGraficoVendas($agruparPor))
                          ->orderBy($this->obterGroupByGraficoVendas($agruparPor))
                          ->get();
            
            return [
                'rotulos' => $dados->pluck('periodo')->toArray(),
                'conjuntos_dados' => [
                    [
                        'rotulo' => 'Vendas (R$)',
                        'dados' => $dados->pluck('total')->toArray(),
                        'cor_fundo' => 'rgba(54, 162, 235, 0.2)',
                        'cor_borda' => 'rgba(54, 162, 235, 1)',
                        'largura_borda' => 2,
                        'preenchimento' => true,
                    ],
                    [
                        'rotulo' => 'Quantidade',
                        'dados' => $dados->pluck('count')->toArray(),
                        'cor_fundo' => 'rgba(255, 99, 132, 0.2)',
                        'cor_borda' => 'rgba(255, 99, 132, 1)',
                        'largura_borda' => 2,
                        'preenchimento' => false,
                        'eixo_y_id' => 'y1',
                    ]
                ]
            ];
        }, 600); // Cache por 10 minutos
    }
    
    /**
     * Obter dados do gráfico de receitas
     * GET /api/dashboard/revenue-chart
     */
    public function revenueChart(Request $request): Response
    {
        $this->autorizar('dashboard.visualizar');
        
        $periodo = $request->query('period', '12');
        
        return $this->cacheado("grafico_receitas_{$periodo}", function () use ($periodo) {
            $dataInicio = Carbon::now()->subMonths((int)$periodo)->startOfMonth();
            
            $query = $this->database->table('vendas')
                ->selectRaw('
                    YEAR(created_at) as ano,
                    MONTH(created_at) as mes,
                    SUM(valor_total) as total,
                    COUNT(*) as contador
                ')
                ->where('created_at', '>=', $dataInicio)
                ->where('status', 'concluida');
            
            $this->aplicarFiltroTenant($query);
            
            $dados = $query->groupBy('ano', 'mes')
                          ->orderBy('ano')
                          ->orderBy('mes')
                          ->get();
            
            return [
                'rotulos' => $dados->map(function ($item) {
                    return Carbon::createFromDate($item->ano, $item->mes)->format('M/Y');
                })->toArray(),
                'conjuntos_dados' => [
                    [
                        'rotulo' => 'Receita (R$)',
                        'dados' => $dados->pluck('total')->toArray(),
                        'cor_fundo' => 'rgba(75, 192, 192, 0.2)',
                        'cor_borda' => 'rgba(75, 192, 192, 1)',
                        'largura_borda' => 2,
                    ]
                ]
            ];
        }, 3600); // Cache por 1 hora
    }
    
    /**
     * Obter produtos mais vendidos
     * GET /api/dashboard/top-products
     */
    public function topProducts(Request $request): Response
    {
        $this->autorizar('dashboard.visualizar');
        
        $limit = min(20, max(5, (int) $request->query('limit', 10)));
        $period = $request->query('period', '30');
        
        return $this->cached("top_products_{$limit}_{$period}", function () use ($limit, $period) {
            $startDate = Carbon::now()->subDays((int)$period)->startOfDay();
            
            $query = $this->database->table('venda_itens')
                ->join('produtos', 'venda_itens.produto_id', '=', 'produtos.id')
                ->join('vendas', 'venda_itens.venda_id', '=', 'vendas.id')
                ->select([
                    'produtos.id',
                    'produtos.nome',
                    'produtos.codigo',
                    'produtos.preco',
                ])
                ->selectRaw('
                    SUM(venda_itens.quantidade) as total_vendido,
                    SUM(venda_itens.valor_total) as receita_total,
                    COUNT(DISTINCT vendas.id) as num_vendas
                ')
                ->where('vendas.created_at', '>=', $startDate)
                ->where('vendas.status', 'concluida');
            
            $this->applyTenantFilter($query, 'vendas.tenant_id');
            
            return $query->groupBy('produtos.id', 'produtos.nome', 'produtos.codigo', 'produtos.preco')
                         ->orderBy('total_vendido', 'desc')
                         ->limit($limit)
                         ->get()
                         ->toArray();
        }, 1800); // Cache for 30 minutes
    }
    
    /**
     * Get customer insights
     * GET /api/dashboard/customer-insights
     */
    public function customerInsights(Request $request): Response
    {
        $this->authorize('dashboard.view');
        
        return $this->cached('customer_insights', function () {
            $query = $this->database->table('clientes');
            $this->applyTenantFilter($query);
            
            $totalCustomers = $query->count();
            
            // New customers this month
            $newThisMonth = $query->where('created_at', '>=', Carbon::now()->startOfMonth())->count();
            
            // Customer segments
            $segments = $this->database->table('clientes')
                ->selectRaw('
                    CASE 
                        WHEN tipo = "pessoa_fisica" THEN "Pessoa Física"
                        WHEN tipo = "pessoa_juridica" THEN "Pessoa Jurídica"
                        ELSE "Outros"
                    END as segment,
                    COUNT(*) as count
                ')
                ->groupBy('tipo')
                ->get();
            
            $this->applyTenantFilter($query);
            
            // Top customers by revenue
            $topCustomers = $this->database->table('clientes')
                ->join('vendas', 'clientes.id', '=', 'vendas.cliente_id')
                ->select([
                    'clientes.id',
                    'clientes.nome',
                    'clientes.email',
                ])
                ->selectRaw('
                    SUM(vendas.valor_total) as total_gasto,
                    COUNT(vendas.id) as num_compras,
                    MAX(vendas.created_at) as ultima_compra
                ')
                ->where('vendas.status', 'concluida')
                ->groupBy('clientes.id', 'clientes.nome', 'clientes.email')
                ->orderBy('total_gasto', 'desc')
                ->limit(10)
                ->get();
            
            $this->applyTenantFilter($query, 'clientes.tenant_id');
            
            return [
                'total_customers' => $totalCustomers,
                'new_this_month' => $newThisMonth,
                'growth_rate' => $this->calculateCustomerGrowthRate(),
                'segments' => $segments->toArray(),
                'top_customers' => $topCustomers->toArray(),
            ];
        }, 1800); // Cache for 30 minutes
    }
    
    /**
     * Get real-time notifications
     * GET /api/dashboard/notifications
     */
    public function notifications(Request $request): Response
    {
        $this->authorize('dashboard.view');
        
        $notifications = [];
        
        // Low stock alerts
        $lowStock = $this->database->table('produtos')
            ->where('estoque_atual', '<=', $this->database->raw('estoque_minimo'))
            ->where('ativo', true);
        
        $this->applyTenantFilter($lowStock);
        
        $lowStockCount = $lowStock->count();
        if ($lowStockCount > 0) {
            $notifications[] = [
                'type' => 'warning',
                'title' => 'Estoque Baixo',
                'message' => "{$lowStockCount} produto(s) com estoque baixo",
                'action' => '/estoque/alerts',
                'created_at' => Carbon::now()->toISOString(),
            ];
        }
        
        // Pending orders
        $pendingOrders = $this->database->table('vendas')
            ->where('status', 'pendente');
        
        $this->applyTenantFilter($pendingOrders);
        
        $pendingCount = $pendingOrders->count();
        if ($pendingCount > 0) {
            $notifications[] = [
                'type' => 'info',
                'title' => 'Vendas Pendentes',
                'message' => "{$pendingCount} venda(s) aguardando processamento",
                'action' => '/vendas?status=pendente',
                'created_at' => Carbon::now()->toISOString(),
            ];
        }
        
        // Overdue invoices
        $overdueInvoices = $this->database->table('financeiro_transacoes')
            ->where('tipo', 'receita')
            ->where('status', 'pendente')
            ->where('data_vencimento', '<', Carbon::now()->toDateString());
        
        $this->applyTenantFilter($overdueInvoices);
        
        $overdueCount = $overdueInvoices->count();
        if ($overdueCount > 0) {
            $notifications[] = [
                'type' => 'danger',
                'title' => 'Faturas Vencidas',
                'message' => "{$overdueCount} fatura(s) em atraso",
                'action' => '/financeiro/contas?status=vencidas',
                'created_at' => Carbon::now()->toISOString(),
            ];
        }
        
        return $this->success($notifications);
    }
    
    /**
     * Get sales metrics
     */
    private function getSalesMetrics(Carbon $startDate): array
    {
        $query = $this->database->table('vendas')
            ->where('created_at', '>=', $startDate);
        
        $this->applyTenantFilter($query);
        
        $totalSales = $query->where('status', 'concluida')->sum('valor_total') ?? 0;
        $totalOrders = $query->where('status', 'concluida')->count();
        $pendingOrders = $query->where('status', 'pendente')->count();
        
        // Previous period for comparison
        $previousStart = $startDate->copy()->subDays($startDate->diffInDays(Carbon::now()));
        $previousQuery = $this->database->table('vendas')
            ->where('created_at', '>=', $previousStart)
            ->where('created_at', '<', $startDate);
        
        $this->applyTenantFilter($previousQuery);
        
        $previousSales = $previousQuery->where('status', 'concluida')->sum('valor_total') ?? 0;
        
        return [
            'total_sales' => $totalSales,
            'total_orders' => $totalOrders,
            'pending_orders' => $pendingOrders,
            'average_order' => $totalOrders > 0 ? $totalSales / $totalOrders : 0,
            'growth_rate' => $previousSales > 0 ? (($totalSales - $previousSales) / $previousSales) * 100 : 0,
        ];
    }
    
    /**
     * Get customer metrics
     */
    private function getCustomerMetrics(Carbon $startDate): array
    {
        $query = $this->database->table('clientes');
        $this->applyTenantFilter($query);
        
        $totalCustomers = $query->count();
        $newCustomers = $query->where('created_at', '>=', $startDate)->count();
        $activeCustomers = $this->database->table('clientes')
            ->join('vendas', 'clientes.id', '=', 'vendas.cliente_id')
            ->where('vendas.created_at', '>=', $startDate)
            ->distinct('clientes.id')
            ->count();
        
        return [
            'total_customers' => $totalCustomers,
            'new_customers' => $newCustomers,
            'active_customers' => $activeCustomers,
            'retention_rate' => $totalCustomers > 0 ? ($activeCustomers / $totalCustomers) * 100 : 0,
        ];
    }
    
    /**
     * Get inventory metrics
     */
    private function getInventoryMetrics(): array
    {
        $query = $this->database->table('produtos')
            ->where('ativo', true);
        
        $this->applyTenantFilter($query);
        
        $totalProducts = $query->count();
        $lowStock = $query->where('estoque_atual', '<=', $this->database->raw('estoque_minimo'))->count();
        $outOfStock = $query->where('estoque_atual', '<=', 0)->count();
        $totalValue = $query->sum($this->database->raw('estoque_atual * preco_custo')) ?? 0;
        
        return [
            'total_products' => $totalProducts,
            'low_stock' => $lowStock,
            'out_of_stock' => $outOfStock,
            'inventory_value' => $totalValue,
        ];
    }
    
    /**
     * Get financial metrics
     */
    private function getFinancialMetrics(Carbon $startDate): array
    {
        $query = $this->database->table('financeiro_transacoes')
            ->where('data_transacao', '>=', $startDate);
        
        $this->applyTenantFilter($query);
        
        $receitas = $query->where('tipo', 'receita')->where('status', 'pago')->sum('valor') ?? 0;
        $despesas = $query->where('tipo', 'despesa')->where('status', 'pago')->sum('valor') ?? 0;
        $receitasPendentes = $query->where('tipo', 'receita')->where('status', 'pendente')->sum('valor') ?? 0;
        $despesasPendentes = $query->where('tipo', 'despesa')->where('status', 'pendente')->sum('valor') ?? 0;
        
        return [
            'total_revenue' => $receitas,
            'total_expenses' => $despesas,
            'net_profit' => $receitas - $despesas,
            'pending_receivables' => $receitasPendentes,
            'pending_payables' => $despesasPendentes,
            'profit_margin' => $receitas > 0 ? (($receitas - $despesas) / $receitas) * 100 : 0,
        ];
    }
    
    /**
     * Get order metrics
     */
    private function getOrderMetrics(Carbon $startDate): array
    {
        $query = $this->database->table('vendas')
            ->where('created_at', '>=', $startDate);
        
        $this->applyTenantFilter($query);
        
        $statusCounts = $query->groupBy('status')
                             ->selectRaw('status, COUNT(*) as count')
                             ->pluck('count', 'status')
                             ->toArray();
        
        return [
            'completed' => $statusCounts['concluida'] ?? 0,
            'pending' => $statusCounts['pendente'] ?? 0,
            'cancelled' => $statusCounts['cancelada'] ?? 0,
            'processing' => $statusCounts['processando'] ?? 0,
        ];
    }
    
    /**
     * Get performance metrics
     */
    private function getPerformanceMetrics(Carbon $startDate): array
    {
        // This would typically include conversion rates, page views, etc.
        // For now, we'll return basic metrics
        return [
            'conversion_rate' => 2.5, // Mock data
            'avg_session_duration' => 180, // seconds
            'bounce_rate' => 35.2, // percentage
            'page_views' => 1250,
        ];
    }
    
    /**
     * Calculate customer growth rate
     */
    private function calculateCustomerGrowthRate(): float
    {
        $thisMonth = $this->database->table('clientes')
            ->where('created_at', '>=', Carbon::now()->startOfMonth());
        
        $this->applyTenantFilter($thisMonth);
        $thisMonthCount = $thisMonth->count();
        
        $lastMonth = $this->database->table('clientes')
            ->where('created_at', '>=', Carbon::now()->subMonth()->startOfMonth())
            ->where('created_at', '<', Carbon::now()->startOfMonth());
        
        $this->applyTenantFilter($lastMonth);
        $lastMonthCount = $lastMonth->count();
        
        return $lastMonthCount > 0 ? (($thisMonthCount - $lastMonthCount) / $lastMonthCount) * 100 : 0;
    }
    
    /**
     * Get sales chart SELECT clause based on grouping
     */
    private function getSalesChartSelect(string $groupBy): string
    {
        return match($groupBy) {
            'day' => 'DATE(created_at) as period, SUM(valor_total) as total, COUNT(*) as count',
            'week' => 'YEARWEEK(created_at) as period, SUM(valor_total) as total, COUNT(*) as count',
            'month' => 'DATE_FORMAT(created_at, "%Y-%m") as period, SUM(valor_total) as total, COUNT(*) as count',
            default => 'DATE(created_at) as period, SUM(valor_total) as total, COUNT(*) as count',
        };
    }
    
    /**
     * Get sales chart GROUP BY clause based on grouping
     */
    private function getSalesChartGroupBy(string $groupBy): string
    {
        return match($groupBy) {
            'day' => 'DATE(created_at)',
            'week' => 'YEARWEEK(created_at)',
            'month' => 'YEAR(created_at), MONTH(created_at)',
            default => 'DATE(created_at)',
        };
    }
}
